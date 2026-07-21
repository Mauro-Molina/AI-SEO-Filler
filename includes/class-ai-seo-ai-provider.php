<?php
/**
 * AI provider factory — routes SEO generation to Gemini, Groq, or OpenAI.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and delegates to the configured AI provider with optional fallback.
 */
class AI_Provider {

	/**
	 * Provider slug => class map.
	 *
	 * @var array<string, string>
	 */
	private static $providers = array(
		'gemini' => Gemini::class,
		'groq'   => Groq::class,
		'openai' => OpenAI::class,
	);

	/**
	 * Fallback order when primary provider fails.
	 *
	 * @var string[]
	 */
	private static $fallback_order = array( 'gemini', 'groq', 'openai' );

	/**
	 * Whether an API error is likely transient (quota, billing, rate limits).
	 *
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_recoverable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$message = strtolower( $error->get_error_message() );
		$needles = array(
			'quota',
			'billing',
			'rate limit',
			'rate_limit',
			'too many requests',
			'exceeded',
			'insufficient',
			'429',
			'overloaded',
			'unavailable',
		);

		foreach ( $needles as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns an instantiated provider by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return AI_Provider_Interface|null
	 */
	public static function get_provider( $slug ) {
		if ( ! isset( self::$providers[ $slug ] ) ) {
			return null;
		}

		$class = self::$providers[ $slug ];
		$instance = new $class();

		return $instance instanceof AI_Provider_Interface ? $instance : null;
	}

	/**
	 * Generates SEO data using the active AI provider (with optional fallback).
	 *
	 * @param int   $post_id Post or product ID.
	 * @param array $args    Generation arguments.
	 * @return array|\WP_Error
	 */
	public static function generate_seo_data( $post_id, $args = array() ) {
		if ( ! Settings::has_active_provider_configured() ) {
			return new \WP_Error( 'missing_api_key', Settings::get_missing_provider_message() );
		}

		$primary = Settings::get_ai_provider();
		$chain   = array_unique( array_merge( array( $primary ), self::$fallback_order ) );

		if ( ! Settings::is_fallback_enabled() ) {
			$chain = array( $primary );
		}

		$last_error = null;

		foreach ( $chain as $slug ) {
			$provider = self::get_provider( $slug );

			if ( ! $provider || ! self::provider_is_configured( $slug ) ) {
				continue;
			}

			/**
			 * Fires before SEO generation for a post.
			 *
			 * @param int    $post_id  Post ID.
			 * @param string $provider Provider slug.
			 * @param array  $args     Generation args.
			 */
			do_action( 'aiseofiller_before_generate', $post_id, $slug, $args );

			$result = $provider->generate_seo_data( $post_id, $args );

			if ( ! is_wp_error( $result ) ) {
				$result = self::validate_word_count( $result, $post_id, $args );

				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					Logger::warning( 'Word count validation failed, trying fallback', array( 'provider' => $slug ) );
					continue;
				}

				$result['_provider'] = $slug;
				Logger::info( 'SEO generated', array( 'post_id' => $post_id, 'provider' => $slug ) );

				/**
				 * Fires after successful SEO generation.
				 *
				 * @param array $result  SEO data.
				 * @param int   $post_id Post ID.
				 */
				do_action( 'aiseofiller_after_generate', $result, $post_id );

				return $result;
			}

			$last_error = $result;
			Logger::warning( 'Provider failed', array( 'provider' => $slug, 'error' => $result->get_error_message() ) );
		}

		return $last_error ? $last_error : new \WP_Error( 'no_provider', __( 'No AI provider available.', 'ai-seo-filler' ) );
	}

	/**
	 * Tests the active provider connection.
	 *
	 * When fallback is enabled, tries Gemini/Groq if the primary provider fails.
	 *
	 * @return true|\WP_Error
	 */
	public static function test_active_connection() {
		$primary = Settings::get_ai_provider();
		$chain   = array_unique( array_merge( array( $primary ), self::$fallback_order ) );

		if ( ! Settings::is_fallback_enabled() ) {
			$chain = array( $primary );
		}

		$errors = array();

		foreach ( $chain as $slug ) {
			if ( ! self::provider_is_configured( $slug ) ) {
				continue;
			}

			$provider = self::get_provider( $slug );

			if ( ! $provider ) {
				continue;
			}

			$result = $provider->test_connection();

			if ( ! is_wp_error( $result ) ) {
				if ( $slug === $primary ) {
					return true;
				}

				return new \WP_Error(
					'fallback_provider_ok',
					sprintf(
						/* translators: 1: working provider label, 2: configured primary provider label */
						__( 'Connection OK with %1$s. Your primary provider (%2$s) is unavailable — switch to %1$s in Settings for free-tier usage.', 'ai-seo-filler' ),
						$provider->get_label(),
						self::get_provider( $primary ) ? self::get_provider( $primary )->get_label() : $primary
					)
				);
			}

			$errors[] = $provider->get_label() . ': ' . $result->get_error_message();

			if ( ! self::is_recoverable_error( $result ) ) {
				break;
			}
		}

		if ( $errors ) {
			return new \WP_Error( 'all_providers_failed', implode( ' | ', $errors ) );
		}

		return new \WP_Error( 'no_provider', __( 'No AI provider configured. Add a free Gemini or Groq API key in Settings.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	private static function provider_is_configured( $slug ) {
		if ( 'groq' === $slug ) {
			return Settings::has_groq_api_key();
		}
		if ( 'openai' === $slug ) {
			return Settings::has_openai_api_key();
		}
		return Settings::has_api_key();
	}

	/**
	 * Validates minimum word count; returns WP_Error to trigger retry when too short.
	 *
	 * @param array $seo_data SEO data.
	 * @param int   $post_id  Post ID.
	 * @param array $args     Generation args.
	 * @return array|\WP_Error
	 */
	private static function validate_word_count( $seo_data, $post_id, $args = array() ) {
		if ( ( $args['mode'] ?? 'full' ) === 'meta_only' || ! Settings::should_generate_content() ) {
			return $seo_data;
		}

		if ( empty( $seo_data['optimized_content'] ) ) {
			return $seo_data;
		}

		$min   = Settings::get_min_word_count( $post_id );
		$count = AI_Content::count_words( $seo_data['optimized_content'] );

		if ( $count < $min ) {
			return new \WP_Error(
				'word_count_low',
				sprintf(
					/* translators: 1: actual word count, 2: required minimum */
					__( 'Generated content has only %1$d words (minimum %2$d).', 'ai-seo-filler' ),
					$count,
					$min
				)
			);
		}

		$seo_data['_word_count'] = $count;
		return $seo_data;
	}

	/**
	 * @return string
	 */
	public static function get_active_provider_label() {
		$provider = self::get_provider( Settings::get_ai_provider() );
		return $provider ? $provider->get_label() : 'Unknown';
	}
}
