<?php
/**
 * Plugin Name:     AI SEO Filler
 * Plugin URI:      https://yourwebsite.com/ai-seo-filler
 * Description:     Genera automáticamente los campos SEO de productos WooCommerce, entradas y páginas usando Gemini Flash, y los escribe en Rank Math y Yoast SEO.
 * Author:          Mauro Molina Mazón
 * Author URI:      https://yourwebsite.com
 * Text Domain:     ai-seo-filler
 * Domain Path:     /languages
 * Version:         0.1.0
 * Requires at least: 6.0
 * Requires PHP:    7.4
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AiSeoFiller
 */

defined( 'ABSPATH' ) || exit;

/**
 * Versión actual del plugin.
 */
define( 'AI_SEO_FILLER_VERSION', '0.1.0' );

/**
 * Ruta absoluta al directorio del plugin.
 */
define( 'AI_SEO_FILLER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL pública del directorio del plugin.
 */
define( 'AI_SEO_FILLER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Basename del plugin (útil para hooks de activación).
 */
define( 'AI_SEO_FILLER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Prefijo de opciones guardadas en la base de datos.
 */
define( 'AI_SEO_FILLER_OPTION_PREFIX', 'ai_seo_filler_' );

/**
 * Nombre de la opción que almacena la API key de Gemini.
 */
define( 'AI_SEO_FILLER_OPTION_API_KEY', AI_SEO_FILLER_OPTION_PREFIX . 'gemini_api_key' );

/**
 * Modelo de Gemini Flash utilizado para la generación.
 */
define( 'AI_SEO_FILLER_GEMINI_MODEL', 'gemini-2.0-flash' );

/**
 * URL base de la API de Google Generative Language.
 */
define( 'AI_SEO_FILLER_GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' );

/**
 * Tamaño de lote para el procesamiento masivo vía WP-Cron.
 */
define( 'AI_SEO_FILLER_BULK_BATCH_SIZE', 10 );

/**
 * Autoloader PSR-4 simplificado para el namespace AiSeoFiller\.
 *
 * Convierte AiSeoFiller\Gemini en includes/class-ai-seo-gemini.php
 * y AiSeoFiller\Admin\Admin en admin/class-ai-seo-admin.php.
 *
 * @param string $class Nombre completo de la clase con namespace.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AiSeoFiller\\';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );

		// Las clases del namespace Admin\ viven en admin/.
		if ( strncmp( 'Admin\\', $relative_class, strlen( 'Admin\\' ) ) === 0 ) {
			$relative_class = substr( $relative_class, strlen( 'Admin\\' ) );
			$base_dir         = AI_SEO_FILLER_PLUGIN_DIR . 'admin/';
		} else {
			$base_dir = AI_SEO_FILLER_PLUGIN_DIR . 'includes/';
		}

		// Core -> core, RankMath -> rankmath, WooCommerce -> woocommerce.
		$slug = strtolower( str_replace( '\\', '-', $relative_class ) );
		$file = $base_dir . 'class-ai-seo-' . $slug . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Clase bootstrap: punto de entrada único del plugin.
 */
final class AI_SEO_Filler_Bootstrap {

	/**
	 * Instancia singleton de la clase Core.
	 *
	 * @var \AiSeoFiller\Core|null
	 */
	private static $core = null;

	/**
	 * Inicializa el plugin.
	 */
	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 10 );
	}

	/**
	 * Arranca la clase principal una vez cargado WordPress.
	 */
	public static function boot() {
		if ( null === self::$core ) {
			self::$core = \AiSeoFiller\Core::instance();
		}

		self::$core->init();
	}

	/**
	 * Tareas de activación del plugin.
	 */
	public static function activate() {
		// Programar evento de cron para el procesamiento masivo si no existe.
		if ( ! wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' ) ) {
			wp_schedule_event( time(), 'ai_seo_filler_every_minute', 'ai_seo_filler_process_bulk_queue' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Tareas de desactivación del plugin.
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
