<?php
/**
 * Plugin Name:     AI SEO Filler
 * Plugin URI:      https://github.com/Mauro-Molina/AI-SEO-Filler
 * Description:     Automatically generates SEO fields for WooCommerce products, posts and pages using Gemini Flash, and writes them to Rank Math and Yoast SEO.
 * Author:          Mauro Molina Mazón
 * Author URI:      https://github.com/Mauro-Molina
 * Text Domain:     ai-seo-filler
 * Domain Path:     /languages
 * Version:         0.3.2
 * Requires at least: 6.0
 * Requires PHP:    7.4
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AiSeoFiller
 */

defined( 'ABSPATH' ) || exit;

/** Current plugin version. */
define( 'AI_SEO_FILLER_VERSION', '0.3.2' );

/** Absolute path to the plugin directory. */
define( 'AI_SEO_FILLER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL of the plugin directory. */
define( 'AI_SEO_FILLER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Plugin basename (used for activation hooks). */
define( 'AI_SEO_FILLER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/** Prefix for options stored in the database. */
define( 'AI_SEO_FILLER_OPTION_PREFIX', 'ai_seo_filler_' );

/** Option name for the encrypted Gemini API key. */
define( 'AI_SEO_FILLER_OPTION_API_KEY', AI_SEO_FILLER_OPTION_PREFIX . 'gemini_api_key' );

/** Option name for the bulk processing queue. */
define( 'AI_SEO_FILLER_OPTION_BULK_QUEUE', AI_SEO_FILLER_OPTION_PREFIX . 'bulk_queue' );

/** Option name for the Gemini model slug. */
define( 'AI_SEO_FILLER_OPTION_GEMINI_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'gemini_model' );

/** Default Gemini model (free tier). */
define( 'AI_SEO_FILLER_GEMINI_MODEL_DEFAULT', 'gemini-flash-latest' );

/** Option name for the active AI provider (gemini or groq). */
define( 'AI_SEO_FILLER_OPTION_AI_PROVIDER', AI_SEO_FILLER_OPTION_PREFIX . 'ai_provider' );

/** Option name for the encrypted Groq API key. */
define( 'AI_SEO_FILLER_OPTION_GROQ_API_KEY', AI_SEO_FILLER_OPTION_PREFIX . 'groq_api_key' );

/** Option name for the Groq model slug. */
define( 'AI_SEO_FILLER_OPTION_GROQ_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'groq_model' );

/** Default Groq model. */
define( 'AI_SEO_FILLER_GROQ_MODEL_DEFAULT', 'llama-3.3-70b-versatile' );

/** Groq Chat Completions API endpoint. */
define( 'AI_SEO_FILLER_GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions' );

/** Base URL for the Google Generative Language API. */
define( 'AI_SEO_FILLER_GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' );

/** Number of items processed per WP-Cron bulk batch. */
define( 'AI_SEO_FILLER_BULK_BATCH_SIZE', 10 );

/** Option name for the encrypted OpenAI API key. */
define( 'AI_SEO_FILLER_OPTION_OPENAI_API_KEY', AI_SEO_FILLER_OPTION_PREFIX . 'openai_api_key' );

/** Option name for the OpenAI model slug. */
define( 'AI_SEO_FILLER_OPTION_OPENAI_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'openai_model' );

/** Default OpenAI model. */
define( 'AI_SEO_FILLER_OPENAI_MODEL_DEFAULT', 'gpt-4o-mini' );

/** OpenAI Chat Completions API endpoint. */
define( 'AI_SEO_FILLER_OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions' );

/** Default OpenAI image model. */
define( 'AI_SEO_FILLER_OPENAI_IMAGE_MODEL_DEFAULT', 'dall-e-3' );

/** Option name for the OpenAI image model. */
define( 'AI_SEO_FILLER_OPTION_OPENAI_IMAGE_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'openai_image_model' );

/** Default Gemini native image model (generateContent API). */
define( 'AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT', 'gemini-2.5-flash-image' );

/** Option name for the Gemini image model. */
define( 'AI_SEO_FILLER_OPTION_GEMINI_IMAGE_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'gemini_image_model' );

/** Option name for the image generation provider (auto, flux, openai, gemini). */
define( 'AI_SEO_FILLER_OPTION_IMAGE_PROVIDER', AI_SEO_FILLER_OPTION_PREFIX . 'image_provider' );

/** Pollinations image API base URL (Flux free tier). */
define( 'AI_SEO_FILLER_POLLINATIONS_IMAGE_URL', 'https://image.pollinations.ai/prompt/' );

/** Default Pollinations / Flux model. */
define( 'AI_SEO_FILLER_FLUX_MODEL_DEFAULT', 'flux' );

/** Option name for the Flux / Pollinations model. */
define( 'AI_SEO_FILLER_OPTION_FLUX_MODEL', AI_SEO_FILLER_OPTION_PREFIX . 'flux_model' );

/** Default minimum content word count for Rank Math. */
define( 'AI_SEO_FILLER_MIN_WORD_COUNT_DEFAULT', 600 );

require_once AI_SEO_FILLER_PLUGIN_DIR . 'includes/interface-ai-seo-provider.php';

/**
 * Simplified PSR-4 autoloader for the AiSeoFiller\ namespace.
 *
 * Maps AiSeoFiller\Gemini to includes/class-ai-seo-gemini.php
 * and AiSeoFiller\Admin\Admin to admin/class-ai-seo-admin.php.
 *
 * @param string $class Fully-qualified class name.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AiSeoFiller\\';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );

		// Admin namespace classes live under admin/.
		if ( strncmp( 'Admin\\', $relative_class, strlen( 'Admin\\' ) ) === 0 ) {
			$relative_class = substr( $relative_class, strlen( 'Admin\\' ) );
			$base_dir       = AI_SEO_FILLER_PLUGIN_DIR . 'admin/';
		} else {
			$base_dir = AI_SEO_FILLER_PLUGIN_DIR . 'includes/';
		}

		// Core -> core, AI_Content -> ai-content, RankMath -> rankmath.
		$slug = strtolower( str_replace( array( '\\', '_' ), array( '-', '-' ), $relative_class ) );
		$file = $base_dir . 'class-ai-seo-' . $slug . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Bootstrap class — single entry point for the plugin.
 */
final class AI_SEO_Filler_Bootstrap {

	/**
	 * Singleton instance of the Core class.
	 *
	 * @var \AiSeoFiller\Core|null
	 */
	private static $core = null;

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 10 );
	}

	/**
	 * Boot the main plugin class after WordPress has loaded.
	 */
	public static function boot() {
		if ( null === self::$core ) {
			self::$core = \AiSeoFiller\Core::instance();
		}

		self::$core->init();
		\AiSeoFiller\CLI::register();
	}

	/**
	 * Plugin activation tasks.
	 */
	public static function activate() {
		\AiSeoFiller\Bulk::register_cron_schedule();

		if ( ! wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' ) ) {
			wp_schedule_event( time(), 'ai_seo_filler_every_minute', 'ai_seo_filler_process_bulk_queue' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation tasks.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ai_seo_filler_process_bulk_queue' );
		}

		flush_rewrite_rules();
	}
}

AI_SEO_Filler_Bootstrap::init();
