<?php
/**
 * Contract for AI SEO generation providers.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * AI provider interface.
 */
interface AI_Provider_Interface {

	/**
	 * Provider slug (gemini, groq, openai).
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human-readable provider label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Generates SEO data for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Optional generation args (mode, focus_keyword, etc.).
	 * @return array|\WP_Error
	 */
	public function generate_seo_data( $post_id, $args = array() );

	/**
	 * Lightweight API connectivity test.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection();
}
