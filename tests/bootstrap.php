<?php
/**
 * PHPUnit bootstrap for AI SEO Filler tests (dev only — excluded from WordPress.org ZIP).
 *
 * @package AiSeoFiller
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WordPress API stubs for isolated unit tests.
// phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- stub without full WP bootstrap.

require_once dirname( __DIR__ ) . '/ai-seo-filler.php';

// Minimal WordPress stubs for unit tests without full WP bootstrap.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}

	/**
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return sanitize_text_field( $str );
	}

	/**
	 * @param string $title Title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/', '-', (string) $title ) );
	}

	/**
	 * @param string $data HTML.
	 * @return string
	 */
	function wp_kses_post( $data ) {
		return $data;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ai-seo-ai-content.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-seo-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-seo-core.php';
