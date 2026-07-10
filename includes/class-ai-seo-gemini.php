<?php
/**
 * Gemini Flash API client for SEO field generation.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Communicates with the Google Generative Language API.
 */
class Gemini implements AI_Provider_Interface {

	/**
	 * Maximum HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 120;

	/**
	 * Maximum number of API attempts for transient errors (503, 429, etc.).
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Initial delay in seconds before the first retry (doubles each attempt).
	 *
	 * @var int
	 */
	private $retry_delay = 2;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'Gemini';
	}

	/**
	 * Generates SEO data for a post or product using Gemini Flash.
	 *
	 * @param int   $post_id Post or product ID.
	 * @param array $args    Generation arguments.
	 * @return array|\WP_Error Structured SEO fields or WP_Error.
	 */
	public function generate_seo_data( $post_id, $args = array() ) {
		$post = AI_Content::get_valid_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$api_key = Settings::get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Gemini API key is not configured.', 'ai-seo-filler' )
			);
		}

		if ( ! Settings::looks_like_api_key( $api_key ) ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'Stored Gemini API key is invalid. Go to AI SEO Filler → Settings and paste your key again.', 'ai-seo-filler' )
			);
		}

		$content_data = AI_Content::gather_post_content( $post );
		$prompt       = AI_Content::build_prompt( $content_data, $args );
		$response     = $this->call_api( $api_key, $prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$seo_data = AI_Content::parse_seo_response( $response, $post_id );

		if ( is_wp_error( $seo_data ) ) {
			return $seo_data;
		}

		$provider = $this;

		return AI_Content::complete_with_body_content(
			$post_id,
			$seo_data,
			$content_data,
			$args,
			function ( $body_prompt ) use ( $provider, $api_key ) {
				return $provider->call_text_api( $api_key, $body_prompt );
			}
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		$api_key = Settings::get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'Gemini API key is not configured.', 'ai-seo-filler' ) );
		}

		$endpoint = Settings::get_gemini_endpoint();
		$body     = array(
			'contents'         => array(
				array( 'parts' => array( array( 'text' => 'Reply with JSON: {"ok":true}' ) ) ),
			),
			'generationConfig' => array(
				'maxOutputTokens'  => 16,
				'responseMimeType' => 'application/json',
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => trim( $api_key ),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'gemini_test_failed', __( 'Gemini API test failed.', 'ai-seo-filler' ) );
		}

		return true;
	}

	/**
	 * Performs the HTTP request to the Gemini Flash API with automatic retries.
	 *
	 * @param string $api_key Google AI API key.
	 * @param string $prompt  Prompt text.
	 * @return string|\WP_Error Response text from Gemini or WP_Error.
	 */
	private function call_api( $api_key, $prompt ) {
		$api_key  = trim( $api_key );
		$endpoint = Settings::get_gemini_endpoint();

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.4,
				'topP'             => 0.9,
				'maxOutputTokens'  => 2048,
				'responseMimeType' => 'application/json',
				'responseSchema'   => AI_Content::get_seo_meta_schema(),
			),
		);

		$attempt    = 0;
		$delay      = $this->retry_delay;
		$last_error = null;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new \WP_Error(
					'gemini_request_failed',
					sprintf(
						/* translators: %s: network error message */
						__( 'Gemini connection error: %s', 'ai-seo-filler' ),
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
			'gemini_api_error',
			__( 'Gemini request failed after multiple attempts.', 'ai-seo-filler' )
		);
	}

	/**
	 * Calls Gemini and expects a JSON response.
	 *
	 * @param string $api_key API key.
	 * @param string $prompt  Prompt text.
	 * @return string|\WP_Error Response text from Gemini or WP_Error.
	 */
	public function call_json_api( $api_key, $prompt ) {
		$api_key  = trim( $api_key );
		$endpoint = Settings::get_gemini_endpoint();

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.5,
				'topP'             => 0.9,
				'maxOutputTokens'  => 4096,
				'responseMimeType' => 'application/json',
			),
		);

		$attempt    = 0;
		$delay      = $this->retry_delay;
		$last_error = null;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new \WP_Error(
					'gemini_request_failed',
					sprintf(
						/* translators: %s: network error message */
						__( 'Gemini connection error: %s', 'ai-seo-filler' ),
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
				$decoded = json_decode( $raw_body, true );

				if ( ! is_array( $decoded ) ) {
					return new \WP_Error( 'gemini_invalid_response', __( 'Gemini response is not valid JSON.', 'ai-seo-filler' ) );
				}

				$text = $this->extract_text_from_response( $decoded );

				if ( '' === $text ) {
					return new \WP_Error( 'gemini_empty_response', __( 'Gemini returned no content.', 'ai-seo-filler' ) );
				}

				return $text;
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
			'gemini_api_error',
			__( 'Gemini request failed after multiple attempts.', 'ai-seo-filler' )
		);
	}

	/**
	 * Calls Gemini for plain-text HTML body content (no JSON schema).
	 *
	 * @param string $api_key API key.
	 * @param string $prompt  Body content prompt.
	 * @return string|\WP_Error
	 */
	public function call_text_api( $api_key, $prompt ) {
		$api_key  = trim( $api_key );
		$endpoint = Settings::get_gemini_endpoint();

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.5,
				'topP'             => 0.9,
				'maxOutputTokens'  => 8192,
				'responseMimeType' => 'text/plain',
			),
		);

		$attempt    = 0;
		$delay      = $this->retry_delay;
		$last_error = null;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new \WP_Error(
					'gemini_request_failed',
					sprintf(
						/* translators: %s: network error message */
						__( 'Gemini connection error: %s', 'ai-seo-filler' ),
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
				$decoded = json_decode( $raw_body, true );

				if ( ! is_array( $decoded ) ) {
					return new \WP_Error( 'gemini_invalid_response', __( 'Gemini response is not valid JSON.', 'ai-seo-filler' ) );
				}

				$text = $this->extract_text_from_response( $decoded );

				if ( '' === $text ) {
					return new \WP_Error( 'gemini_empty_response', __( 'Gemini returned no content.', 'ai-seo-filler' ) );
				}

				return $text;
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
			'gemini_api_error',
			__( 'Gemini request failed after multiple attempts.', 'ai-seo-filler' )
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
	 * Builds a WP_Error from a failed Gemini API response.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $raw_body    Raw response body.
	 * @return \WP_Error
	 */
	private function build_api_error( $status_code, $raw_body ) {
		$error_data = json_decode( $raw_body, true );
		$message    = isset( $error_data['error']['message'] )
			? $error_data['error']['message']
			: __( 'Unknown Gemini API error.', 'ai-seo-filler' );

		if ( 503 === (int) $status_code ) {
			$message = __( 'Gemini is temporarily overloaded due to high demand. Please wait a moment and try again.', 'ai-seo-filler' );
		} elseif ( 429 === (int) $status_code ) {
			$message = __( 'Gemini rate limit reached. Please wait a moment and try again.', 'ai-seo-filler' );
		}

		return new \WP_Error(
			'gemini_api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: error message */
				__( 'Gemini returned error %1$d: %2$s', 'ai-seo-filler' ),
				$status_code,
				$message
			),
			array( 'status' => $status_code )
		);
	}

	/**
	 * Parses a successful Gemini API response body.
	 *
	 * @param string $raw_body Raw JSON response body.
	 * @return string|\WP_Error Generated text or WP_Error.
	 */
	private function parse_api_response_body( $raw_body ) {
		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'gemini_invalid_response',
				__( 'Gemini response is not valid JSON.', 'ai-seo-filler' )
			);
		}

		if ( ! empty( $decoded['promptFeedback']['blockReason'] ) ) {
			return new \WP_Error(
				'gemini_blocked',
				__( 'Gemini blocked this request due to content safety filters.', 'ai-seo-filler' )
			);
		}

		$text = $this->extract_text_from_response( $decoded );

		if ( empty( $text ) ) {
			$finish_reason = $this->get_finish_reason( $decoded );

			if ( 'MAX_TOKENS' === $finish_reason ) {
				return new \WP_Error(
					'gemini_truncated',
					__( 'Gemini response was truncated. Try again or switch to Groq in Settings.', 'ai-seo-filler' )
				);
			}

			return new \WP_Error(
				'gemini_empty_response',
				__( 'Gemini returned no content.', 'ai-seo-filler' )
			);
		}

		if ( 'MAX_TOKENS' === $this->get_finish_reason( $decoded ) ) {
			// Attempt parse anyway; extract_json_string can pad truncated braces.
			$json_test = AI_Content::extract_json_string( $text );
			if ( '' === $json_test || null === json_decode( $json_test, true ) ) {
				return new \WP_Error(
					'gemini_truncated',
					__( 'Gemini response was truncated before completing the JSON. Try again or switch to Groq.', 'ai-seo-filler' )
				);
			}
		}

		return $text;
	}

	/**
	 * Returns the finish reason from the first Gemini candidate.
	 *
	 * @param array $decoded Decoded API response.
	 * @return string
	 */
	private function get_finish_reason( $decoded ) {
		if ( empty( $decoded['candidates'][0]['finishReason'] ) ) {
			return '';
		}

		return (string) $decoded['candidates'][0]['finishReason'];
	}

	/**
	 * Extracts generated text from the Gemini API response body.
	 *
	 * @param array $decoded Decoded JSON response.
	 * @return string Generated text or empty string.
	 */
	private function extract_text_from_response( $decoded ) {
		if ( ! isset( $decoded['candidates'] ) || ! is_array( $decoded['candidates'] ) ) {
			return '';
		}

		foreach ( $decoded['candidates'] as $candidate ) {
			if ( ! isset( $candidate['content']['parts'] ) ) {
				continue;
			}

			foreach ( $candidate['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					return trim( $part['text'] );
				}
			}
		}

		return '';
	}
}
