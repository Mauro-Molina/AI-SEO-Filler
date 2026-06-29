<?php
/**
 * Admin area: menus, metaboxes, and asset loading.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller\Admin;

use AiSeoFiller\AI_Provider;
use AiSeoFiller\Bulk;
use AiSeoFiller\Core;
use AiSeoFiller\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all wp-admin UI for the plugin.
 */
class Admin {

	/**
	 * Main plugin core instance.
	 *
	 * @var Core
	 */
	private $core;

	/**
	 * @param Core $core Main plugin core instance.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * Registers admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Registers top-level and submenu pages.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AI SEO Filler', 'ai-seo-filler' ),
			__( 'AI SEO Filler', 'ai-seo-filler' ),
			'manage_options',
			'ai-seo-filler',
			array( $this, 'render_settings_page' ),
			'dashicons-search',
			80
		);

		add_submenu_page(
			'ai-seo-filler',
			__( 'Settings', 'ai-seo-filler' ),
			__( 'Settings', 'ai-seo-filler' ),
			'manage_options',
			'ai-seo-filler',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'ai-seo-filler',
			__( 'Bulk Processing', 'ai-seo-filler' ),
			__( 'Bulk Processing', 'ai-seo-filler' ),
			'manage_options',
			'ai-seo-filler-bulk',
			array( $this, 'render_bulk_page' )
		);
	}

	/**
	 * Renders the settings page view.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}

		include AI_SEO_FILLER_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Renders the bulk processing page view.
	 */
	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}

		include AI_SEO_FILLER_PLUGIN_DIR . 'admin/views/bulk-page.php';
	}

	/**
	 * Enqueues admin CSS and JS on plugin and editor screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$plugin_screens = array(
			'toplevel_page_ai-seo-filler',
			'ai-seo-filler_page_ai-seo-filler-bulk',
		);

		$is_plugin_screen = in_array( $hook_suffix, $plugin_screens, true );
		$is_editor_screen = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_screen && ! $is_editor_screen ) {
			return;
		}

		wp_enqueue_style(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			AI_SEO_FILLER_VERSION
		);

		$script_deps = array( 'jquery' );

		if ( $is_editor_screen ) {
			$script_deps[] = 'wp-data';
			$script_deps[] = 'wp-edit-post';
		}

		wp_enqueue_script(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/js/admin.js',
			$script_deps,
			AI_SEO_FILLER_VERSION,
			true
		);

		wp_localize_script(
			'ai-seo-filler-admin',
			'aiSeoFiller',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ai_seo_filler_nonce' ),
				'seoPlugin'  => Core::detect_seo_plugin(),
				'i18n'       => array(
					'generating'  => __( 'Generating SEO fields…', 'ai-seo-filler' ),
					'success'     => __( 'SEO fields generated successfully.', 'ai-seo-filler' ),
					'synced'      => __( 'SEO fields updated in the editor.', 'ai-seo-filler' ),
					'error'       => __( 'Failed to generate SEO fields.', 'ai-seo-filler' ),
					'processing'  => __( 'Processing…', 'ai-seo-filler' ),
					'completed'   => __( 'Bulk processing completed.', 'ai-seo-filler' ),
					'cancelled'   => __( 'Bulk processing cancelled.', 'ai-seo-filler' ),
					'confirmBulk' => __( 'Start bulk SEO generation for the selected content?', 'ai-seo-filler' ),
					'confirmCancel' => __( 'Cancel the current bulk queue?', 'ai-seo-filler' ),
				),
			)
		);
	}

	/**
	 * Registers the metabox on supported post types.
	 */
	public function register_meta_box() {
		$post_types = Bulk::get_allowed_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ai-seo-filler-metabox',
				__( 'AI SEO Filler', 'ai-seo-filler' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders the editor metabox content.
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ai_seo_filler_metabox', 'ai_seo_filler_metabox_nonce' );

		$seo_plugin = Core::detect_seo_plugin();
		$has_api    = Settings::has_active_provider_configured();
		$provider   = AI_Provider::get_active_provider_label();

		echo '<div class="ai-seo-filler-metabox">';

		if ( ! $has_api ) {
			echo '<p class="description">';
			echo esc_html__( 'Configure your AI provider API key under AI SEO Filler → Settings.', 'ai-seo-filler' );
			echo '</p>';
		} elseif ( 'none' === $seo_plugin ) {
			echo '<p class="description">';
			echo esc_html__( 'No active Rank Math or Yoast SEO plugin detected.', 'ai-seo-filler' );
			echo '</p>';
		} else {
			$plugin_label = 'rankmath' === $seo_plugin ? 'Rank Math' : 'Yoast SEO';

			printf(
				'<p class="description">%s<br>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: active AI provider name */
						__( 'AI provider: %s', 'ai-seo-filler' ),
						$provider
					)
				),
				esc_html(
					sprintf(
						/* translators: %s: detected SEO plugin name */
						__( 'SEO plugin: %s', 'ai-seo-filler' ),
						$plugin_label
					)
				)
			);

			printf(
				'<button type="button" class="button button-primary ai-seo-filler-generate" data-post-id="%d">%s</button>',
				(int) $post->ID,
				esc_html__( 'Generate SEO with AI', 'ai-seo-filler' )
			);

			echo '<div class="ai-seo-filler-status" aria-live="polite"></div>';
		}

		echo '</div>';
	}
}
