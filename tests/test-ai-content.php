<?php
/**
 * PHPUnit tests for AI_Content utilities.
 *
 * @package AiSeoFiller
 */

use PHPUnit\Framework\TestCase;

/**
 * AI_Content test case.
 */
class AI_Content_Test extends TestCase {

	public function test_extract_json_from_markdown_fence() {
		$raw  = "```json\n{\"meta_title\":\"Test\"}\n```";
		$json = AiSeoFiller\AI_Content::extract_json_string( $raw );
		$this->assertStringContainsString( 'meta_title', $json );
	}

	public function test_enforce_rankmath_adds_keyword_to_title() {
		$data = AiSeoFiller\AI_Content::enforce_rankmath_rules( array(
			'focus_keyword'    => 'MacBook Pro',
			'meta_title'       => 'Best Laptop',
			'meta_description' => 'Great device',
			'slug'             => 'best-laptop',
		) );

		$this->assertStringContainsString( 'MacBook Pro', $data['meta_title'] );
		$this->assertStringContainsString( 'MacBook Pro', $data['meta_description'] );
		$this->assertStringContainsString( 'macbook-pro', $data['slug'] );
	}

	public function test_enforce_rankmath_keyword_at_content_start_and_density() {
		$data = AiSeoFiller\AI_Content::enforce_rankmath_rules( array(
			'focus_keyword'     => 'zapatillas running',
			'meta_title'        => 'zapatillas running oferta',
			'meta_description'  => 'zapatillas running cómodas',
			'slug'              => 'oferta-deportiva',
			'optimized_content' => '<p>Descubre nuestra colección deportiva con materiales premium.</p><p>Envío gratis.</p>',
		) );

		$plain = wp_strip_all_tags( $data['optimized_content'] );
		$this->assertStringStartsWith( 'zapatillas running', ltrim( mb_strtolower( $plain ) ) );
		$this->assertGreaterThanOrEqual( 3, AiSeoFiller\AI_Content::count_keyword_occurrences( $data['optimized_content'], 'zapatillas running' ) );
		$this->assertSame( 'zapatillas-running-oferta-deportiva', $data['slug'] );
	}

	public function test_primary_focus_keyword_strips_alternates() {
		$this->assertSame(
			'MacBook Pro',
			AiSeoFiller\AI_Content::primary_focus_keyword( 'MacBook Pro, laptop apple, notebook' )
		);
	}

	public function test_count_words() {
		$this->assertSame( 3, AiSeoFiller\AI_Content::count_words( 'one two three' ) );
	}

	public function test_count_words_spanish_text() {
		$text = "Sobre este artículo MacBook Pro 2025 M5 Supercargado por M5";
		$this->assertGreaterThan( 5, AiSeoFiller\AI_Content::count_words( $text ) );
	}

	public function test_text_contains_keyword() {
		$this->assertTrue( AiSeoFiller\AI_Content::text_contains_keyword( 'MacBook Pro 2025', 'MacBook Pro' ) );
		$this->assertFalse( AiSeoFiller\AI_Content::text_contains_keyword( 'Dell XPS', 'MacBook' ) );
	}
}
