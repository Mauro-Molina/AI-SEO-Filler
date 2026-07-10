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
				'sanitize_callback' => array( $this, 'sanitize_content_language' ),
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

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_OPENAI_API_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_openai_api_key' ),
			'default'           => '',
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_OPENAI_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_openai_model' ),
			'default'           => AI_SEO_FILLER_OPENAI_MODEL_DEFAULT,
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_IMAGE_PROVIDER, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_image_provider' ),
			'default'           => 'auto',
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_OPENAI_IMAGE_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_openai_image_model' ),
			'default'           => AI_SEO_FILLER_OPENAI_IMAGE_MODEL_DEFAULT,
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_GEMINI_IMAGE_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_gemini_image_model' ),
			'default'           => AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT,
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_FLUX_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_flux_model' ),
			'default'           => AI_SEO_FILLER_FLUX_MODEL_DEFAULT,
		) );

		$bool_fields = array(
			'preview_mode'       => true,
			'only_if_empty'      => false,
			'revision_backup'    => true,
			'enable_fallback'    => true,
			'gen_meta'           => true,
			'gen_slug'           => true,
			'gen_content'        => true,
			'gen_short_desc'     => true,
			'gen_image_alts'     => true,
		);

		foreach ( $bool_fields as $field => $default ) {
			register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_PREFIX . $field, array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => $default,
			) );
		}

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_PREFIX . 'min_word_count', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_min_word_count' ),
			'default'           => AI_SEO_FILLER_MIN_WORD_COUNT_DEFAULT,
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_PREFIX . 'content_tone', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_content_tone' ),
			'default'           => 'commercial',
		) );

		register_setting( 'ai_seo_filler_settings', AI_SEO_FILLER_OPTION_PREFIX . 'bulk_rate_limit', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_bulk_rate_limit' ),
			'default'           => 2,
		) );
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
		if ( self::is_encrypted_secret( $value, 'ai-seo-filler-api-key', array( __CLASS__, 'looks_like_api_key' ) ) ) {
			return $value;
		}

		$value = self::normalize_secret_key( $value );

		// Empty field means "keep the current key" (the input is cleared when a key exists).
		if ( '' === $value || self::is_secret_placeholder( $value ) ) {
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
	 * Returns available content languages for the settings UI.
	 *
	 * @return array<string, string> Locale => label.
	 */
	public static function get_available_content_languages() {
		$languages = array(
			'en_US' => 'English (United States)',
			'en_GB' => 'English (United Kingdom)',
			'es_ES' => 'Español (España)',
			'es_MX' => 'Español (México)',
			'es_AR' => 'Español (Argentina)',
			'es_CO' => 'Español (Colombia)',
			'es_CL' => 'Español (Chile)',
			'es_PE' => 'Español (Perú)',
			'pt_BR' => 'Português (Brasil)',
			'pt_PT' => 'Português (Portugal)',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
			'it_IT' => 'Italiano',
			'nl_NL' => 'Nederlands',
			'pl_PL' => 'Polski',
			'ru_RU' => 'Русский',
			'uk'    => 'Українська',
			'tr_TR' => 'Türkçe',
			'ar'    => 'العربية',
			'he_IL' => 'עברית',
			'hi_IN' => 'हिन्दी',
			'ja'    => '日本語',
			'ko_KR' => '한국어',
			'zh_CN' => '简体中文',
			'zh_TW' => '繁體中文',
			'sv_SE' => 'Svenska',
			'da_DK' => 'Dansk',
			'fi'    => 'Suomi',
			'nb_NO' => 'Norsk bokmål',
			'ro_RO' => 'Română',
			'cs_CZ' => 'Čeština',
			'hu_HU' => 'Magyar',
			'el'    => 'Ελληνικά',
			'th'    => 'ไทย',
			'vi'    => 'Tiếng Việt',
			'id_ID' => 'Bahasa Indonesia',
			'ms_MY' => 'Bahasa Melayu',
			'ca'    => 'Català',
			'eu'    => 'Euskara',
			'gl_ES' => 'Galego',
		);

		$site_locale = get_locale();

		if ( $site_locale && ! isset( $languages[ $site_locale ] ) ) {
			$languages = array( $site_locale => $site_locale ) + $languages;
		}

		/**
		 * Filters the content language options shown in settings.
		 *
		 * @param array<string, string> $languages Locale => label.
		 */
		return apply_filters( 'ai_seo_filler_content_languages', $languages );
	}

	/**
	 * Sanitizes the content language locale.
	 *
	 * @param string $value Submitted locale.
	 * @return string
	 */
	public function sanitize_content_language( $value ) {
		$value     = sanitize_text_field( (string) $value );
		$allowed   = array_keys( self::get_available_content_languages() );
		$fallback  = get_locale();

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return in_array( $fallback, $allowed, true ) ? $fallback : 'en_US';
	}

	/**
	 * Returns the configured content language locale.
	 *
	 * @return string
	 */
	public static function get_content_language() {
		$value   = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'language', get_locale() );
		$allowed = array_keys( self::get_available_content_languages() );

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		$fallback = get_locale();

		return in_array( $fallback, $allowed, true ) ? $fallback : 'en_US';
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

		return in_array( $provider, array( 'gemini', 'groq', 'openai' ), true ) ? $provider : 'gemini';
	}

	/**
	 * Sanitizes the selected AI provider.
	 *
	 * @param string $value Submitted provider slug.
	 * @return string
	 */
	public function sanitize_ai_provider( $value ) {
		$value = sanitize_text_field( $value );

		return in_array( $value, array( 'gemini', 'groq', 'openai' ), true ) ? $value : 'gemini';
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
			'openai' => __( 'OpenAI', 'ai-seo-filler' ),
		);
	}

	/**
	 * Returns whether the active provider has a valid API key configured.
	 *
	 * @return bool
	 */
	public static function has_active_provider_configured() {
		$provider = self::get_ai_provider();

		if ( 'groq' === $provider ) {
			return self::has_groq_api_key();
		}

		if ( 'openai' === $provider ) {
			return self::has_openai_api_key();
		}

		return self::has_api_key();
	}

	/**
	 * Returns an error message when the active provider is not configured.
	 *
	 * @return string
	 */
	public static function get_missing_provider_message() {
		$provider = self::get_ai_provider();

		if ( 'groq' === $provider ) {
			return __( 'Groq API key is not configured. Go to AI SEO Filler → Settings.', 'ai-seo-filler' );
		}

		if ( 'openai' === $provider ) {
			return __( 'OpenAI API key is not configured. Go to AI SEO Filler → Settings.', 'ai-seo-filler' );
		}

		return __( 'Gemini API key is not configured. Go to AI SEO Filler → Settings.', 'ai-seo-filler' );
	}

	/**
	 * Returns the model slug for the active provider.
	 *
	 * @return string
	 */
	public static function get_active_model() {
		$provider = self::get_ai_provider();

		if ( 'groq' === $provider ) {
			return self::get_groq_model();
		}

		if ( 'openai' === $provider ) {
			return self::get_openai_model();
		}

		return self::get_gemini_model();
	}

	/**
	 * Sanitizes and encrypts the Groq API key before saving.
	 *
	 * @param string $value Value submitted from the settings form.
	 * @return string Encrypted API key ready for storage.
	 */
	public function sanitize_groq_api_key( $value ) {
		if ( self::is_encrypted_secret( $value, 'groq-api-key', array( __CLASS__, 'looks_like_groq_api_key' ) ) ) {
			return $value;
		}

		$value = self::normalize_secret_key( $value );

		if ( '' === $value || self::is_secret_placeholder( $value ) ) {
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

	/**
	 * Returns a short preview of a secret for admin UI (prefix…suffix).
	 *
	 * @param string $secret Plain-text secret.
	 * @return string
	 */
	public static function get_secret_preview( $secret ) {
		$secret = trim( (string) $secret );
		$len    = strlen( $secret );

		if ( $len < 12 ) {
			return $len > 0 ? str_repeat( '•', min( 8, $len ) ) : '';
		}

		$prefix_len = min( 10, (int) floor( $len / 3 ) );
		$suffix_len = min( 4, (int) floor( $len / 4 ) );

		return substr( $secret, 0, $prefix_len ) . '…' . substr( $secret, -$suffix_len );
	}

	/**
	 * Sanitizes a boolean option.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function sanitize_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_min_word_count( $value ) {
		$value = absint( $value );
		return max( 300, min( 3000, $value ? $value : AI_SEO_FILLER_MIN_WORD_COUNT_DEFAULT ) );
	}

	/**
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_bulk_rate_limit( $value ) {
		$value = absint( $value );
		return max( 0, min( 60, $value ) );
	}

	/**
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_content_tone( $value ) {
		$allowed = array( 'commercial', 'technical', 'neutral' );
		$value   = sanitize_text_field( $value );
		return in_array( $value, $allowed, true ) ? $value : 'commercial';
	}

	/** POST field name for OpenAI key (avoids hosts that strip fields containing "api_key"). */
	const OPENAI_SECRET_FIELD = 'ai_seo_filler_openai_secret';

	/**
	 * Normalizes a pasted API secret (trim, strip BOM/whitespace).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_secret_key( $value ) {
		$value = (string) $value;

		if ( function_exists( 'wp_unslash' ) ) {
			$value = \wp_unslash( $value );
		}

		$value = trim( $value );
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
		$value = preg_replace( '/\s+/', '', $value );

		return $value;
	}

	/**
	 * Detects browser/password-manager mask characters (not a real API key).
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_secret_placeholder( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return true;
		}

		if ( preg_match( '/^[•*]+$/u', $value ) ) {
			return true;
		}

		// Common Unicode bullet / mask glyphs used by password managers.
		if ( preg_match( '/^[\x{2022}\x{25CF}\x{25E6}\x{2219}\x{00B7}*]+$/u', $value ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true when a stored ciphertext decrypts to a valid secret.
	 *
	 * @param string   $value           Candidate ciphertext.
	 * @param string   $context_key     Encryption context.
	 * @param callable $validator       Plain-text validator.
	 * @return bool
	 */
	public static function is_encrypted_secret( $value, $context_key, $validator ) {
		if ( '' === $value || ! is_callable( $validator ) ) {
			return false;
		}

		$decrypted = self::decrypt_secret( $value, $context_key );

		return '' !== $decrypted && call_user_func( $validator, $decrypted );
	}

	/**
	 * Reads an OpenAI key submitted via settings form or AJAX.
	 *
	 * @param string $primary Value from the registered option field (may be empty).
	 * @return string
	 */
	public static function read_openai_key_from_request( $primary = '' ) {
		$value = self::normalize_secret_key( $primary );

		if ( '' !== $value && ! self::is_secret_placeholder( $value ) ) {
			return $value;
		}

		if ( isset( $_POST[ self::OPENAI_SECRET_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$alternate = self::normalize_secret_key( wp_unslash( $_POST[ self::OPENAI_SECRET_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' !== $alternate && ! self::is_secret_placeholder( $alternate ) ) {
				return $alternate;
			}
		}

		return '';
	}

	/**
	 * Validates, encrypts, and stores the OpenAI API key.
	 *
	 * @param string $plain_key Plain-text API key.
	 * @return true|\WP_Error
	 */
	public static function persist_openai_api_key( $plain_key ) {
		$plain_key = self::normalize_secret_key( $plain_key );

		if ( self::is_secret_placeholder( $plain_key ) ) {
			return new \WP_Error( 'openai_key_empty', __( 'Enter your OpenAI API key.', 'ai-seo-filler' ) );
		}

		if ( ! self::looks_like_openai_api_key( $plain_key ) ) {
			return new \WP_Error(
				'invalid_openai_key',
				__( 'Invalid OpenAI API key. Keys must start with "sk-" (for example sk-proj-…).', 'ai-seo-filler' )
			);
		}

		$encrypted = self::encrypt_secret( $plain_key, 'openai-api-key' );

		if ( '' === $encrypted ) {
			return new \WP_Error(
				'openai_key_encrypt_failed',
				__( 'Could not encrypt the OpenAI API key. Check that OpenSSL is enabled in PHP.', 'ai-seo-filler' )
			);
		}

		update_option( AI_SEO_FILLER_OPTION_OPENAI_API_KEY, $encrypted );

		return true;
	}

	/**
	 * @param string $value API key.
	 * @return string
	 */
	public function sanitize_openai_api_key( $value ) {
		if ( self::is_encrypted_secret( $value, 'openai-api-key', array( __CLASS__, 'looks_like_openai_api_key' ) ) ) {
			return $value;
		}

		$value = self::read_openai_key_from_request( $value );

		if ( '' === $value ) {
			return get_option( AI_SEO_FILLER_OPTION_OPENAI_API_KEY, '' );
		}

		if ( ! self::looks_like_openai_api_key( $value ) ) {
			add_settings_error(
				'ai_seo_filler_settings',
				'invalid_openai_key',
				__( 'Invalid OpenAI API key. Keys must start with "sk-" (for example sk-proj-…).', 'ai-seo-filler' ),
				'error'
			);
			return get_option( AI_SEO_FILLER_OPTION_OPENAI_API_KEY, '' );
		}

		$encrypted = self::encrypt_secret( $value, 'openai-api-key' );

		if ( '' === $encrypted ) {
			add_settings_error(
				'ai_seo_filler_settings',
				'openai_key_encrypt_failed',
				__( 'Could not encrypt the OpenAI API key. Check that OpenSSL is enabled in PHP.', 'ai-seo-filler' ),
				'error'
			);
			return get_option( AI_SEO_FILLER_OPTION_OPENAI_API_KEY, '' );
		}

		return $encrypted;
	}

	/**
	 * @param string $value Model slug.
	 * @return string
	 */
	public function sanitize_openai_model( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array_keys( self::get_available_openai_models() );
		return in_array( $value, $allowed, true ) ? $value : AI_SEO_FILLER_OPENAI_MODEL_DEFAULT;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_available_openai_models() {
		return array(
			'gpt-4o-mini' => __( 'GPT-4o Mini (recommended)', 'ai-seo-filler' ),
			'gpt-4o'      => __( 'GPT-4o', 'ai-seo-filler' ),
			'gpt-4.1-mini' => __( 'GPT-4.1 Mini', 'ai-seo-filler' ),
		);
	}

	/**
	 * Validates OpenAI API key format (legacy sk-… and project sk-proj-… keys).
	 *
	 * @param string $value Candidate key.
	 * @return bool
	 */
	public static function looks_like_openai_api_key( $value ) {
		$value = self::normalize_secret_key( $value );

		if ( '' === $value || strlen( $value ) < 20 ) {
			return false;
		}

		// Legacy sk-…, project sk-proj-…, service sk-svcacct-…, etc.
		return (bool) preg_match( '/^sk-(?:[a-z]+-)?[A-Za-z0-9_-]+$/', $value );
	}

	/**
	 * @return string
	 */
	public static function get_openai_api_key() {
		return self::get_stored_secret( AI_SEO_FILLER_OPTION_OPENAI_API_KEY, array( __CLASS__, 'looks_like_openai_api_key' ), 'openai-api-key' );
	}

	/**
	 * @return bool
	 */
	public static function has_openai_api_key() {
		return self::looks_like_openai_api_key( self::get_openai_api_key() );
	}

	/**
	 * @return string
	 */
	public static function get_openai_model() {
		$model   = get_option( AI_SEO_FILLER_OPTION_OPENAI_MODEL, AI_SEO_FILLER_OPENAI_MODEL_DEFAULT );
		$allowed = array_keys( self::get_available_openai_models() );
		return in_array( $model, $allowed, true ) ? $model : AI_SEO_FILLER_OPENAI_MODEL_DEFAULT;
	}

	/**
	 * @param string $field Option suffix without prefix.
	 * @return bool
	 */
	public static function is_enabled( $field ) {
		return (bool) get_option( AI_SEO_FILLER_OPTION_PREFIX . $field, true );
	}

	/** @return bool */
	public static function is_preview_mode() {
		return self::is_enabled( 'preview_mode' );
	}

	/** @return bool */
	public static function is_only_if_empty() {
		return self::is_enabled( 'only_if_empty' );
	}

	/** @return bool */
	public static function is_revision_backup_enabled() {
		return self::is_enabled( 'revision_backup' );
	}

	/** @return bool */
	public static function is_fallback_enabled() {
		return self::is_enabled( 'enable_fallback' );
	}

	/** @return bool */
	public static function should_generate_meta() {
		return self::is_enabled( 'gen_meta' );
	}

	/** @return bool */
	public static function should_generate_slug() {
		return self::is_enabled( 'gen_slug' );
	}

	/** @return bool */
	public static function should_generate_content() {
		return self::is_enabled( 'gen_content' );
	}

	/** @return bool */
	public static function should_generate_short_desc() {
		return self::is_enabled( 'gen_short_desc' );
	}

	/** @return bool */
	public static function should_generate_image_alts() {
		return self::is_enabled( 'gen_image_alts' );
	}

	/**
	 * @param int $post_id Optional post for per-type overrides.
	 * @return int
	 */
	public static function get_min_word_count( $post_id = 0 ) {
		$min = absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'min_word_count', AI_SEO_FILLER_MIN_WORD_COUNT_DEFAULT ) );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$override = absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'min_words_' . $post->post_type, 0 ) );
				if ( $override >= 300 ) {
					$min = $override;
				}
			}
		}

		return max( 300, $min );
	}

	/**
	 * @return string
	 */
	public static function get_content_tone() {
		$tone = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'content_tone', 'commercial' );
		return in_array( $tone, array( 'commercial', 'technical', 'neutral' ), true ) ? $tone : 'commercial';
	}

	/**
	 * @return int Seconds between bulk items.
	 */
	public static function get_bulk_rate_limit() {
		return absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'bulk_rate_limit', 2 ) );
	}

	/**
	 * Per post-type min word overrides.
	 *
	 * @return array<string, int>
	 */
	public static function get_post_type_min_words() {
		return array(
			'post'    => absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'min_words_post', 0 ) ) ?: self::get_min_word_count(),
			'page'    => absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'min_words_page', 0 ) ) ?: self::get_min_word_count(),
			'product' => absint( get_option( AI_SEO_FILLER_OPTION_PREFIX . 'min_words_product', 0 ) ) ?: self::get_min_word_count(),
		);
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_image_provider( $value ) {
		$allowed = array( 'auto', 'flux', 'openai', 'gemini' );
		$value   = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : 'auto';
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_flux_model( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array_keys( self::get_available_flux_models() );

		return in_array( $value, $allowed, true ) ? $value : AI_SEO_FILLER_FLUX_MODEL_DEFAULT;
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_openai_image_model( $value ) {
		$value = sanitize_text_field( $value );

		return $value ? $value : AI_SEO_FILLER_OPENAI_IMAGE_MODEL_DEFAULT;
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_gemini_image_model( $value ) {
		$value   = sanitize_text_field( $value );
		$allowed = array_keys( Settings::get_available_gemini_image_models() );

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT;
	}

	/**
	 * Resolved image generation provider slug (primary choice).
	 *
	 * @return string flux|openai|gemini|''
	 */
	public static function get_image_provider() {
		$chain = self::get_image_provider_chain();

		return $chain ? $chain[0] : '';
	}

	/**
	 * Ordered image providers to try (Flux first in auto mode — free, no API key).
	 *
	 * @return string[] flux|openai|gemini slugs.
	 */
	public static function get_image_provider_chain() {
		$setting = get_option( AI_SEO_FILLER_OPTION_IMAGE_PROVIDER, 'auto' );

		if ( 'flux' === $setting ) {
			return array( 'flux' );
		}

		if ( 'openai' === $setting ) {
			return self::has_openai_api_key() ? array( 'openai' ) : array();
		}

		if ( 'gemini' === $setting ) {
			return self::has_api_key() ? array( 'gemini' ) : array();
		}

		$chain = array( 'flux' );

		if ( self::has_api_key() ) {
			$chain[] = 'gemini';
		}

		if ( self::has_openai_api_key() ) {
			$chain[] = 'openai';
		}

		return $chain;
	}

	/**
	 * @return bool
	 */
	public static function has_image_provider_configured() {
		return '' !== self::get_image_provider();
	}

	/**
	 * @return string
	 */
	public static function get_openai_image_model() {
		return get_option( AI_SEO_FILLER_OPTION_OPENAI_IMAGE_MODEL, AI_SEO_FILLER_OPENAI_IMAGE_MODEL_DEFAULT );
	}

	/**
	 * @return string
	 */
	public static function get_gemini_image_model() {
		$model = get_option( AI_SEO_FILLER_OPTION_GEMINI_IMAGE_MODEL, AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT );

		// Legacy Imagen predict models are no longer available on the Gemini API.
		$legacy_imagen = array(
			'imagen-3.0-generate-002',
			'imagen-3.0-generate-001',
			'imagen-3.0-fast-generate-001',
			'imagen-4.0-generate-001',
		);

		if ( in_array( $model, $legacy_imagen, true ) ) {
			return AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT;
		}

		return $model ? $model : AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT;
	}

	/**
	 * @return string
	 */
	public static function get_flux_model() {
		$model   = get_option( AI_SEO_FILLER_OPTION_FLUX_MODEL, AI_SEO_FILLER_FLUX_MODEL_DEFAULT );
		$allowed = array_keys( self::get_available_flux_models() );

		return in_array( $model, $allowed, true ) ? $model : AI_SEO_FILLER_FLUX_MODEL_DEFAULT;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_available_gemini_image_models() {
		return array(
			'gemini-2.5-flash-image'         => 'Gemini 2.5 Flash Image',
			'gemini-3.1-flash-image-preview' => 'Gemini 3.1 Flash Image (preview)',
			'gemini-3-pro-image-preview'     => 'Gemini 3 Pro Image (preview)',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_available_flux_models() {
		return array(
			'flux'  => 'Flux (recommended, free)',
			'turbo' => 'Turbo (faster, free)',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_available_image_providers() {
		return array(
			'auto'   => __( 'Auto (Flux free, then Gemini, then OpenAI)', 'ai-seo-filler' ),
			'flux'   => __( 'Flux / Pollinations (free, no API key)', 'ai-seo-filler' ),
			'openai' => __( 'OpenAI (DALL·E / GPT Image)', 'ai-seo-filler' ),
			'gemini' => __( 'Gemini (free tier limits apply)', 'ai-seo-filler' ),
		);
	}
}
