<?php
/**
 * PHPUnit bootstrap for AI SEO Filler tests.
 *
 * @package AiSeoFiller
 */

require_once dirname( __DIR__ ) . '/ai-seo-filler.php';

// Minimal WordPress stubs for unit tests without full WP bootstrap.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
	function sanitize_textarea_field( $str ) { return sanitize_text_field( $str ); }
	function sanitize_title( $title ) { return strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ); }
	function wp_kses_post( $data ) { return $data; }
	function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
	function __( $text ) { return $text; }
}

require_once dirname( __DIR__ ) . '/includes/class-ai-seo-ai-content.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-seo-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-seo-core.php';
