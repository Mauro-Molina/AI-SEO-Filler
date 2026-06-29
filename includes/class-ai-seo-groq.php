<?php
/**
 * Groq API client for SEO field generation.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Communicates with the Groq OpenAI-compatible Chat Completions API.
 */
class Groq {

	/**
	 * Maximum HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 120;

	/**
	 * Maximum number of API attempts for transient errors.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Initial delay in seconds before the first retry.
	 *
	 * @var int
	 */
	private $retry_delay = 2;

	/**
	 * Generates SEO data for a post or product using Groq.
	 *
	 * @param int $post_id Post or product ID.
	 * @return array|\WP_Error Structured SEO fields or WP_Error.
	 */
	public function generate_seo_data( $post_id ) {
		$post = AI_Content::get_valid_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$api_key = Settings::get_groq_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Groq API key is not configured.', 'ai-seo-filler' )
			);
		}

		if ( ! Settings::looks_like_groq_api_key( $api_key ) ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'Stored Groq API key is invalid. Go to AI SEO Filler → Settings and paste your key again.', 'ai-seo-filler' )
			);
		}

		$content_data = AI_Content::gather_post_content( $post );
		$prompt       = AI_Content::build_prompt( $content_data );
		$response     = $this->call_api( $api_key, $prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return AI_Content::parse_seo_response( $response );
	}

	/**
	 * Performs the HTTP request to the Groq API with automatic retries.
	 *
	 * @param string $api_key Groq API key.
	 * @param string $prompt  Prompt text.
	 * @return string|\WP_Error Response text or WP_Error.
	 */
	private function call_api( $api_key, $prompt ) {
		$api_key = trim( $api_key );
		$model   = Settings::get_groq_model();

		$body = array(
			'model'           => $model,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You are a WordPress SEO expert optimizing for Rank Math scoring. Respond only with valid JSON, no markdown. Follow all Rank Math rules in the user prompt strictly, including 600+ word optimized_content with focus keyword at the start.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.4,
			'max_tokens'      => 8192,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$attempt    = 0;
		$delay      = $this->retry_delay;
		$last_error = null;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				AI_SEO_FILLER_GROQ_API_URL,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new \WP_Error(
					'groq_request_failed',
					sprintf(
						/* translators: %s: network error message */
						__( 'Groq connection error: %s', 'ai-seo-filler' ),
						$response->get_error_message()
					)
				);

				if ( $attempt < $this->max_retries ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}

				return $last_error;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code >= 200 && $status_code < 300 ) {
				return $this->parse_api_response_body( $raw_body );
			}

			$last_error = $this->build_api_error( $status_code, $raw_body );

			if ( $this->is_retryable_status( $status_code ) && $attempt < $this->max_retries ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			return $last_error;
		}

		return $last_error ? $last_error : new \WP_Error(
			'groq_api_error',
			__( 'Groq request failed after multiple attempts.', 'ai-seo-filler' )
		);
	}

	/**
	 * Returns whether an HTTP status code warrants a retry.
	 *
	 * @param int $status_code HTTP response code.
	 * @return bool
	 */
	private function is_retryable_status( $status_code ) {
		return in_array( (int) $status_code, array( 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Builds a WP_Error from a failed Groq API response.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $raw_body    Raw response body.
	 * @return \WP_Error
	 */
	private function build_api_error( $status_code, $raw_body ) {
		$error_data = json_decode( $raw_body, true );
		$message    = __( 'Unknown Groq API error.', 'ai-seo-filler' );

		if ( isset( $error_data['error']['message'] ) ) {
			$message = $error_data['error']['message'];
		}

		if ( 503 === (int) $status_code ) {
			$message = __( 'Groq is temporarily overloaded. Please wait a moment and try again.', 'ai-seo-filler' );
		} elseif ( 429 === (int) $status_code ) {
			$message = __( 'Groq rate limit reached. Please wait a moment and try again.', 'ai-seo-filler' );
		}

		return new \WP_Error(
			'groq_api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message */
				__( 'Groq returned error %1$d: %2$s', 'ai-seo-filler' ),
				$status_code,
				$message
			),
			array( 'status' => $status_code )
		);
	}

	/**
	 * Parses a successful Groq chat completions response body.
	 *
	 * @param string $raw_body Raw JSON response body.
	 * @return string|\WP_Error Generated text or WP_Error.
	 */
	private function parse_api_response_body( $raw_body ) {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'groq_invalid_response',
				__( 'Groq response is not valid JSON.', 'ai-seo-filler' )
			);
		}

		if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
			return new \WP_Error(
				'groq_empty_response',
				__( 'Groq returned no content.', 'ai-seo-filler' )
			);
		}

		return trim( $decoded['choices'][0]['message']['content'] );
	}
}
