<?php
/**
 * Main plugin class: registers hooks and coordinates components.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Core singleton that orchestrates the entire plugin.
 */
class Core {

	/**
	 * Singleton instance.
	 *
	 * @var Core|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Bulk processing handler.
	 *
	 * @var Bulk
	 */
	private $bulk;

	/**
	 * Admin UI handler.
	 *
	 * @var Admin\Admin|null
	 */
	private $admin;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for the singleton pattern.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->bulk     = new Bulk( $this );
	}

	/**
	 * Registers all WordPress hooks for the plugin.
	 */
	public function init() {
		$this->load_textdomain();
		$this->settings->init();
		$this->bulk->init();
		RankMath::init();

		// AJAX — single-item generation from the post editor.
		add_action( 'wp_ajax_ai_seo_filler_generate_single', array( $this, 'ajax_generate_single' ) );

		// Admin UI (menu, metabox, assets).
		if ( is_admin() ) {
			$this->admin = new Admin\Admin( $this );
			$this->admin->init();
		}
	}

	/**
	 * Loads the plugin text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ai-seo-filler',
			false,
			dirname( AI_SEO_FILLER_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Detects which SEO plugin is active.
	 *
	 * @return string 'rankmath', 'yoast', or 'none'.
	 */
	public static function detect_seo_plugin() {
		$forced = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin', 'auto' );

		if ( 'rankmath' === $forced ) {
			return 'rankmath';
		}

		if ( 'yoast' === $forced ) {
			return 'yoast';
		}

		// Automatic detection.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}

		return 'none';
	}

	/**
	 * Handles the AJAX request for single-item SEO generation.
	 */
	public function ajax_generate_single() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ),
				403
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid post.', 'ai-seo-filler' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You cannot edit this post.', 'ai-seo-filler' ) ),
				403
			);
		}

		$seo_plugin = self::detect_seo_plugin();
		$result     = $this->generate_and_save_seo( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		$editor_meta = array();

		if ( 'rankmath' === $seo_plugin ) {
			$editor_meta = RankMath::build_editor_meta( $result );
		} elseif ( 'yoast' === $seo_plugin ) {
			$editor_meta = Yoast::build_editor_meta( $result );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'SEO fields generated and saved successfully.', 'ai-seo-filler' ),
				'data'       => $result,
				'editorMeta' => $editor_meta,
				'seoPlugin'  => $seo_plugin,
			)
		);
	}

	/**
	 * Generates SEO data via the active AI provider and writes it to the SEO plugin.
	 *
	 * @param int $post_id Post or product ID.
	 * @return array|\WP_Error Saved SEO data or an error.
	 */
	public function generate_and_save_seo( $post_id ) {
		$seo_data = AI_Provider::generate_seo_data( $post_id );

		if ( is_wp_error( $seo_data ) ) {
			return $seo_data;
		}

		$seo_plugin = self::detect_seo_plugin();

		if ( 'none' === $seo_plugin ) {
			return new \WP_Error(
				'no_seo_plugin',
				__( 'No compatible SEO plugin detected.', 'ai-seo-filler' )
			);
		}

		if ( 'rankmath' === $seo_plugin ) {
			$writer = new RankMath();
			$writer->save_seo_data( $post_id, $seo_data );
		} elseif ( 'yoast' === $seo_plugin ) {
			$writer = new Yoast();
			$writer->save_seo_data( $post_id, $seo_data );
		}

		// Update post slug and optimized body content when provided.
		$post_updates = array( 'ID' => $post_id );

		if ( ! empty( $seo_data['slug'] ) ) {
			$post_updates['post_name'] = sanitize_title( $seo_data['slug'] );
		}

		if ( ! empty( $seo_data['optimized_content'] ) ) {
			$post_updates['post_content'] = $seo_data['optimized_content'];
		}

		if ( count( $post_updates ) > 1 ) {
			wp_update_post( $post_updates );
		}

		return $seo_data;
	}

	/**
	 * Prevents cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevents unserialization of the singleton.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize a singleton.' );
	}
}
