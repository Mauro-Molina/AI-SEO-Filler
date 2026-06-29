<?php
/**
 * Plugin settings: API key storage, encryption, and Settings API registration.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin options and secure API key storage.
 */
class Settings {

	/**
	 * Registers settings-related hooks.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers plugin options with the WordPress Settings API.
	 */
	public function register_settings() {
		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_PREFIX . 'language',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => get_locale(),
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_seo_plugin' ),
				'default'           => 'auto',
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_GEMINI_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_gemini_model' ),
				'default'           => AI_SEO_FILLER_GEMINI_MODEL_DEFAULT,
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_AI_PROVIDER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_ai_provider' ),
				'default'           => 'gemini',
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_GROQ_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_groq_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_GROQ_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_groq_model' ),
				'default'           => AI_SEO_FILLER_GROQ_MODEL_DEFAULT,
			)
		);
	}

	/**
	 * Sanitizes and encrypts the API key before saving.
	 *
	 * Preserves the existing key when the field is left blank on save.
	 *
	 * @param string $value Value submitted from the settings form.
	 * @return string Encrypted API key ready for storage.
	 */
	public function sanitize_api_key( $value ) {
		$value = trim( wp_unslash( (string) $value ) );

		// Empty field means "keep the current key" (the input is cleared when a key exists).
		if ( '' === $value ) {
			return get_option( AI_SEO_FILLER_OPTION_API_KEY, '' );
		}

		// Reject placeholder/mask submissions (legacy forms or browser autofill).
		if ( preg_match( '/^[•*]+$/u', $value ) ) {
			return get_option( AI_SEO_FILLER_OPTION_API_KEY, '' );
		}

		if ( ! self::looks_like_api_key( $value ) ) {
			add_settings_error(
				'ai_seo_filler_settings',
				'invalid_api_key',
				__( 'Invalid Gemini API key. Keys from Google AI Studio start with "AIza" or "AQ.".', 'ai-seo-filler' ),
				'error'
			);

			return get_option( AI_SEO_FILLER_OPTION_API_KEY, '' );
		}

		return self::encrypt_api_key( $value );
	}

	/**
	 * Sanitizes the SEO plugin preference.
	 *
	 * @param string $value Submitted value.
	 * @return string One of: auto, rankmath, yoast.
	 */
	public function sanitize_seo_plugin( $value ) {
		$allowed = array( 'auto', 'rankmath', 'yoast' );

		return in_array( $value, $allowed, true ) ? $value : 'auto';
	}

	/**
	 * Returns the list of Gemini models available in settings.
	 *
	 * @return array<string, string> Model slug => human-readable label.
	 */
	public static function get_available_models() {
		return array(
			'gemini-flash-latest' => __( 'Gemini Flash Latest (free tier)', 'ai-seo-filler' ),
			'gemini-2.0-flash'    => __( 'Gemini 2.0 Flash', 'ai-seo-filler' ),
			'gemini-1.5-flash'    => __( 'Gemini 1.5 Flash', 'ai-seo-filler' ),
			'gemini-2.5-flash'    => __( 'Gemini 2.5 Flash', 'ai-seo-filler' ),
		);
	}

	/**
	 * Sanitizes the selected Gemini model slug.
	 *
	 * @param string $value Submitted model slug.
	 * @return string Valid model slug.
	 */
	public function sanitize_gemini_model( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array_keys( self::get_available_models() );

		return in_array( $value, $allowed, true ) ? $value : AI_SEO_FILLER_GEMINI_MODEL_DEFAULT;
	}

	/**
	 * Returns the configured Gemini model slug.
	 *
	 * @return string
	 */
	public static function get_gemini_model() {
		$model = get_option( AI_SEO_FILLER_OPTION_GEMINI_MODEL, AI_SEO_FILLER_GEMINI_MODEL_DEFAULT );

		$allowed = array_keys( self::get_available_models() );

		return in_array( $model, $allowed, true ) ? $model : AI_SEO_FILLER_GEMINI_MODEL_DEFAULT;
	}

	/**
	 * Builds the full Gemini generateContent endpoint URL for the active model.
	 *
	 * @return string
	 */
	public static function get_gemini_endpoint() {
		return AI_SEO_FILLER_GEMINI_API_URL . self::get_gemini_model() . ':generateContent';
	}

	/**
	 * Returns the active AI provider slug.
	 *
	 * @return string 'gemini' or 'groq'.
	 */
	public static function get_ai_provider() {
		$provider = get_option( AI_SEO_FILLER_OPTION_AI_PROVIDER, 'gemini' );

		return in_array( $provider, array( 'gemini', 'groq' ), true ) ? $provider : 'gemini';
	}

	/**
	 * Sanitizes the selected AI provider.
	 *
	 * @param string $value Submitted provider slug.
	 * @return string
	 */
	public function sanitize_ai_provider( $value ) {
		$value = sanitize_text_field( $value );

		return in_array( $value, array( 'gemini', 'groq' ), true ) ? $value : 'gemini';
	}

	/**
	 * Returns available AI providers for the settings UI.
	 *
	 * @return array<string, string>
	 */
	public static function get_available_providers() {
		return array(
			'gemini' => __( 'Google Gemini', 'ai-seo-filler' ),
			'groq'   => __( 'Groq', 'ai-seo-filler' ),
		);
	}

	/**
	 * Returns whether the active provider has a valid API key configured.
	 *
	 * @return bool
	 */
	public static function has_active_provider_configured() {
		return 'groq' === self::get_ai_provider()
			? self::has_groq_api_key()
			: self::has_api_key();
	}

	/**
	 * Returns an error message when the active provider is not configured.
	 *
	 * @return string
	 */
	public static function get_missing_provider_message() {
		if ( 'groq' === self::get_ai_provider() ) {
			return __( 'Groq API key is not configured. Go to AI SEO Filler → Settings.', 'ai-seo-filler' );
		}

		return __( 'Gemini API key is not configured. Go to AI SEO Filler → Settings.', 'ai-seo-filler' );
	}

	/**
	 * Returns the model slug for the active provider.
	 *
	 * @return string
	 */
	public static function get_active_model() {
		return 'groq' === self::get_ai_provider()
			? self::get_groq_model()
			: self::get_gemini_model();
	}

	/**
	 * Sanitizes and encrypts the Groq API key before saving.
	 *
	 * @param string $value Value submitted from the settings form.
	 * @return string Encrypted API key ready for storage.
	 */
	public function sanitize_groq_api_key( $value ) {
		$value = trim( wp_unslash( (string) $value ) );

		if ( '' === $value ) {
			return get_option( AI_SEO_FILLER_OPTION_GROQ_API_KEY, '' );
		}

		if ( preg_match( '/^[•*]+$/u', $value ) ) {
			return get_option( AI_SEO_FILLER_OPTION_GROQ_API_KEY, '' );
		}

		if ( ! self::looks_like_groq_api_key( $value ) ) {
			add_settings_error(
				'ai_seo_filler_settings',
				'invalid_groq_api_key',
				__( 'Invalid Groq API key. Keys from console.groq.com start with "gsk_".', 'ai-seo-filler' ),
				'error'
			);

			return get_option( AI_SEO_FILLER_OPTION_GROQ_API_KEY, '' );
		}

		return self::encrypt_secret( $value, 'groq-api-key' );
	}

	/**
	 * Returns available Groq models for the settings UI.
	 *
	 * @return array<string, string>
	 */
	public static function get_available_groq_models() {
		return array(
			'llama-3.3-70b-versatile' => __( 'Llama 3.3 70B Versatile (recommended)', 'ai-seo-filler' ),
			'llama-3.1-8b-instant'    => __( 'Llama 3.1 8B Instant (fast)', 'ai-seo-filler' ),
			'gemma2-9b-it'            => __( 'Gemma 2 9B IT', 'ai-seo-filler' ),
			'mixtral-8x7b-32768'      => __( 'Mixtral 8x7B', 'ai-seo-filler' ),
		);
	}

	/**
	 * Sanitizes the selected Groq model slug.
	 *
	 * @param string $value Submitted model slug.
	 * @return string
	 */
	public function sanitize_groq_model( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array_keys( self::get_available_groq_models() );

		return in_array( $value, $allowed, true ) ? $value : AI_SEO_FILLER_GROQ_MODEL_DEFAULT;
	}

	/**
	 * Returns the configured Groq model slug.
	 *
	 * @return string
	 */
	public static function get_groq_model() {
		$model = get_option( AI_SEO_FILLER_OPTION_GROQ_MODEL, AI_SEO_FILLER_GROQ_MODEL_DEFAULT );
		$allowed = array_keys( self::get_available_groq_models() );

		return in_array( $model, $allowed, true ) ? $model : AI_SEO_FILLER_GROQ_MODEL_DEFAULT;
	}

	/**
	 * Checks whether a string looks like a Groq API key.
	 *
	 * @param string $value Candidate API key.
	 * @return bool
	 */
	public static function looks_like_groq_api_key( $value ) {
		return (bool) preg_match( '/^gsk_[A-Za-z0-9]{20,}$/', trim( (string) $value ) );
	}

	/**
	 * Returns the decrypted Groq API key from options.
	 *
	 * @return string
	 */
	public static function get_groq_api_key() {
		return self::get_stored_secret(
			AI_SEO_FILLER_OPTION_GROQ_API_KEY,
			array( __CLASS__, 'looks_like_groq_api_key' ),
			'groq-api-key'
		);
	}

	/**
	 * Returns whether a valid Groq API key is configured.
	 *
	 * @return bool
	 */
	public static function has_groq_api_key() {
		return self::looks_like_groq_api_key( self::get_groq_api_key() );
	}

	/**
	 * Checks whether a string looks like a Google AI Studio API key.
	 *
	 * Supports legacy standard keys (AIza…) and auth keys (AQ.…) introduced in 2026.
	 *
	 * @param string $value Candidate API key.
	 * @return bool
	 */
	public static function looks_like_api_key( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		// Legacy standard (traffic) keys.
		if ( preg_match( '/^AIza[0-9A-Za-z_-]{10,}$/', $value ) ) {
			return true;
		}

		// Auth keys — current default format from Google AI Studio.
		if ( preg_match( '/^AQ\.[A-Za-z0-9_-]{10,}$/', $value ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Encrypts a value using OpenSSL with a key derived from wp_hash().
	 *
	 * @param string $plain_text  Plain text to encrypt.
	 * @param string $context_key Context string for key derivation.
	 * @return string Base64-encoded ciphertext.
	 */
	public static function encrypt_secret( $plain_text, $context_key = 'ai-seo-filler-api-key' ) {
		$key = substr( hash( 'sha256', wp_hash( $context_key, 'ai-seo-filler' ) ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $plain_text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value previously encrypted with encrypt_secret().
	 *
	 * @param string $cipher_text Base64-encoded ciphertext.
	 * @param string $context_key Context string for key derivation.
	 * @return string Plain text, or empty string on failure.
	 */
	public static function decrypt_secret( $cipher_text, $context_key = 'ai-seo-filler-api-key' ) {
		if ( empty( $cipher_text ) ) {
			return '';
		}

		$raw = base64_decode( $cipher_text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$key       = substr( hash( 'sha256', wp_hash( $context_key, 'ai-seo-filler' ) ), 0, 32 );
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Encrypts the Gemini API key (wrapper for backward compatibility).
	 *
	 * @param string $plain_text Plain text to encrypt.
	 * @return string
	 */
	public static function encrypt_api_key( $plain_text ) {
		return self::encrypt_secret( $plain_text, 'ai-seo-filler-api-key' );
	}

	/**
	 * Decrypts the Gemini API key (wrapper for backward compatibility).
	 *
	 * @param string $cipher_text Encrypted value.
	 * @return string
	 */
	public static function decrypt_api_key( $cipher_text ) {
		return self::decrypt_secret( $cipher_text, 'ai-seo-filler-api-key' );
	}

	/**
	 * Retrieves and decrypts a stored secret from options.
	 *
	 * @param string   $option_name      Option name in the database.
	 * @param callable $validator        Callback to validate the plain-text secret.
	 * @param string   $encryption_context Context for encrypt/decrypt.
	 * @return string
	 */
	public static function get_stored_secret( $option_name, $validator, $encryption_context ) {
		$stored = get_option( $option_name, '' );

		if ( '' === $stored ) {
			return '';
		}

		if ( is_callable( $validator ) && call_user_func( $validator, $stored ) ) {
			return trim( $stored );
		}

		$decrypted = self::decrypt_secret( $stored, $encryption_context );

		if ( is_callable( $validator ) && call_user_func( $validator, $decrypted ) ) {
			return $decrypted;
		}

		return '';
	}

	/**
	 * Returns the decrypted Gemini API key from options.
	 *
	 * @return string Plain-text API key, or empty string.
	 */
	public static function get_api_key() {
		return self::get_stored_secret(
			AI_SEO_FILLER_OPTION_API_KEY,
			array( __CLASS__, 'looks_like_api_key' ),
			'ai-seo-filler-api-key'
		);
	}

	/**
	 * Returns whether a valid API key is configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return self::looks_like_api_key( self::get_api_key() );
	}
}
