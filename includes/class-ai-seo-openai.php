<?php
/**
 * OpenAI API client for SEO field generation.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Communicates with the OpenAI Chat Completions API.
 */
class OpenAI implements AI_Provider_Interface {

	const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * @var int
	 */
	private $timeout = 120;

	/**
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * @var int
	 */
	private $retry_delay = 2;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_seo_data( $post_id, $args = array() ) {
		$post = AI_Content::get_valid_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$api_key = Settings::get_openai_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'ai-seo-filler' ) );
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
	 *
	 * @param string $api_key Optional key to test; uses stored key when empty.
	 */
	public function test_connection( $api_key = '' ) {
		$api_key = Settings::normalize_secret_key( $api_key );

		if ( '' === $api_key ) {
			$api_key = Settings::get_openai_api_key();
		}

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'ai-seo-filler' ) );
		}

		$body = array(
			'model'      => Settings::get_openai_model(),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'Reply with JSON: {"ok":true}',
				),
			),
			'max_tokens' => 16,
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $body ) && ! empty( $body['error']['message'] )
				? (string) $body['error']['message']
				: __( 'OpenAI API test failed.', 'ai-seo-filler' );

			return new \WP_Error( 'openai_test_failed', $message );
		}

		return true;
	}

	/**
	 * @param string $api_key API key.
	 * @param string $prompt  Prompt.
	 * @return string|\WP_Error
	 */
	private function call_api( $api_key, $prompt ) {
		$body = array(
			'model'           => Settings::get_openai_model(),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You are a WordPress SEO expert optimizing for Rank Math. Respond only with valid JSON. Leave optimized_content and short_description as empty strings.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.4,
			'max_tokens'      => 2048,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$attempt = 0;
		$delay   = $this->retry_delay;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				self::API_URL,
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
				if ( $attempt < $this->max_retries ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			if ( in_array( $code, array( 429, 500, 502, 503 ), true ) && $attempt < $this->max_retries ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'openai_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'OpenAI API error (HTTP %d).', 'ai-seo-filler' ),
						$code
					)
				);
			}

			$decoded = json_decode( $raw, true );

			if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
				return new \WP_Error( 'openai_empty', __( 'OpenAI returned no content.', 'ai-seo-filler' ) );
			}

			return trim( $decoded['choices'][0]['message']['content'] );
		}

		return new \WP_Error( 'openai_retries', __( 'OpenAI API failed after retries.', 'ai-seo-filler' ) );
	}

	/**
	 * Calls OpenAI for plain-text HTML body content.
	 *
	 * @param string $api_key API key.
	 * @param string $prompt  Body content prompt.
	 * @return string|\WP_Error
	 */
	public function call_text_api( $api_key, $prompt ) {
		$body = array(
			'model'       => Settings::get_openai_model(),
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are a WordPress SEO content writer. Return only raw HTML for the post body.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.5,
			'max_tokens'  => 8192,
		);

		$attempt = 0;
		$delay   = $this->retry_delay;

		while ( $attempt < $this->max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				self::API_URL,
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
				if ( $attempt < $this->max_retries ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			if ( in_array( $code, array( 429, 500, 502, 503 ), true ) && $attempt < $this->max_retries ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'openai_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'OpenAI API error (HTTP %d).', 'ai-seo-filler' ),
						$code
					)
				);
			}

			$decoded = json_decode( $raw, true );

			if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
				return new \WP_Error( 'openai_empty', __( 'OpenAI returned no content.', 'ai-seo-filler' ) );
			}

			return trim( $decoded['choices'][0]['message']['content'] );
		}

		return new \WP_Error( 'openai_retries', __( 'OpenAI API failed after retries.', 'ai-seo-filler' ) );
	}
}
