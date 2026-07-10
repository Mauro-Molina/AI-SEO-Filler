<?php
/**
 * OpenAI API key validation tests.
 *
 * @package AiSeoFiller
 */

use AiSeoFiller\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests OpenAI key format detection.
 */
class Test_OpenAI_Api_Key extends TestCase {

	public function test_legacy_sk_key() {
		$key = 'sk-' . str_repeat( 'a', 48 );
		$this->assertTrue( Settings::looks_like_openai_api_key( $key ) );
	}

	public function test_project_sk_key() {
		$key = 'sk-proj-' . str_repeat( 'A', 20 ) . '_x-' . str_repeat( 'B', 20 );
		$this->assertTrue( Settings::looks_like_openai_api_key( $key ) );
	}

	public function test_project_key_with_whitespace_is_normalized() {
		$key = "  sk-proj-" . str_repeat( 'A', 40 ) . "\n";
		$this->assertTrue( Settings::looks_like_openai_api_key( $key ) );
	}

	public function test_rejects_short_key() {
		$this->assertFalse( Settings::looks_like_openai_api_key( 'sk-short' ) );
	}

	public function test_rejects_gemini_key() {
		$this->assertFalse( Settings::looks_like_openai_api_key( 'AIzaSyDfakekey1234567890abcdefghij' ) );
	}

	public function test_encrypt_roundtrip() {
		if ( ! function_exists( 'wp_hash' ) ) {
			$this->markTestSkipped( 'WordPress not loaded.' );
		}

		$key       = 'sk-proj-' . str_repeat( 'Z', 48 );
		$encrypted = Settings::encrypt_secret( $key, 'openai-api-key' );

		$this->assertNotSame( '', $encrypted );
		$this->assertTrue( Settings::looks_like_openai_api_key( Settings::decrypt_secret( $encrypted, 'openai-api-key' ) ) );
		$this->assertTrue( Settings::is_encrypted_secret( $encrypted, 'openai-api-key', array( Settings::class, 'looks_like_openai_api_key' ) ) );
	}

	public function test_placeholder_detection() {
		$this->assertTrue( Settings::is_secret_placeholder( '' ) );
		$this->assertTrue( Settings::is_secret_placeholder( '••••••••' ) );
		$this->assertFalse( Settings::is_secret_placeholder( 'sk-proj-' . str_repeat( 'A', 40 ) ) );
	}
}
