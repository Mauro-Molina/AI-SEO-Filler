<?php
/**
 * AI provider factory — routes SEO generation to Gemini or Groq.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and delegates to the configured AI provider.
 */
class AI_Provider {

	/**
	 * Generates SEO data using the active AI provider.
	 *
	 * @param int $post_id Post or product ID.
	 * @return array|\WP_Error Structured SEO fields or WP_Error.
	 */
	public static function generate_seo_data( $post_id ) {
		if ( ! Settings::has_active_provider_configured() ) {
			return new \WP_Error(
				'missing_api_key',
				Settings::get_missing_provider_message()
			);
		}

		$provider = Settings::get_ai_provider();

		if ( 'groq' === $provider ) {
			return ( new Groq() )->generate_seo_data( $post_id );
		}

		return ( new Gemini() )->generate_seo_data( $post_id );
	}

	/**
	 * Returns a human-readable label for the active provider.
	 *
	 * @return string
	 */
	public static function get_active_provider_label() {
		return 'groq' === Settings::get_ai_provider()
			? 'Groq'
			: 'Gemini';
	}
}
