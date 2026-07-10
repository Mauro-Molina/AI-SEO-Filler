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
use AiSeoFiller\History;
use AiSeoFiller\Settings;
use AiSeoFiller\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all wp-admin UI for the plugin.
 */
class Admin {

	private $core;

	public function __construct( Core $core ) {
		$this->core = $core;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_ai_seo_filler_generate', array( $this, 'handle_row_action_generate' ) );
	}

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

		add_submenu_page( 'ai-seo-filler', __( 'Settings', 'ai-seo-filler' ), __( 'Settings', 'ai-seo-filler' ), 'manage_options', 'ai-seo-filler', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'ai-seo-filler', __( 'Bulk Processing', 'ai-seo-filler' ), __( 'Bulk Processing', 'ai-seo-filler' ), 'manage_options', 'ai-seo-filler-bulk', array( $this, 'render_bulk_page' ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}
		include AI_SEO_FILLER_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}
		include AI_SEO_FILLER_PLUGIN_DIR . 'admin/views/bulk-page.php';
	}

	public function enqueue_assets( $hook_suffix ) {
		$plugin_screens = array( 'toplevel_page_ai-seo-filler', 'ai-seo-filler_page_ai-seo-filler-bulk' );
		$list_screens   = array( 'edit.php', 'edit-tags.php' );
		$is_plugin      = in_array( $hook_suffix, $plugin_screens, true );
		$is_editor      = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		$is_list        = in_array( $hook_suffix, $list_screens, true );

		if ( ! $is_plugin && ! $is_editor && ! $is_list ) {
			return;
		}

		wp_enqueue_style( 'ai-seo-filler-admin', AI_SEO_FILLER_PLUGIN_URL . 'admin/css/admin.css', array(), AI_SEO_FILLER_VERSION );

		$deps = array( 'jquery' );
		if ( $is_editor ) {
			$deps[] = 'wp-data';
			$deps[] = 'wp-edit-post';

			foreach ( array( 'rank-math-analyzer', 'rank-math', 'rank-math-editor', 'rank-math-app' ) as $rm_handle ) {
				if ( wp_script_is( $rm_handle, 'registered' ) ) {
					$deps[] = $rm_handle;
					break;
				}
			}

			if ( wp_script_is( 'wp-hooks', 'registered' ) ) {
				$deps[] = 'wp-hooks';
			}
		}

		wp_enqueue_script( 'ai-seo-filler-admin', AI_SEO_FILLER_PLUGIN_URL . 'admin/js/admin.js', $deps, AI_SEO_FILLER_VERSION, true );
		wp_localize_script( 'ai-seo-filler-admin', 'aiSeoFiller', $this->get_script_config() );
	}

	public function enqueue_block_editor_assets() {
		wp_enqueue_style(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			AI_SEO_FILLER_VERSION
		);

		wp_enqueue_script(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery', 'wp-data', 'wp-edit-post' ),
			AI_SEO_FILLER_VERSION,
			true
		);
		wp_localize_script( 'ai-seo-filler-admin', 'aiSeoFiller', $this->get_script_config() );

		wp_enqueue_script(
			'ai-seo-filler-gutenberg',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/js/gutenberg-sidebar.js',
			array( 'ai-seo-filler-admin', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			AI_SEO_FILLER_VERSION,
			true
		);
	}

	/**
	 * @return array
	 */
	private function get_script_config() {
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'ai_seo_filler_nonce' ),
			'seoPlugin' => Core::detect_seo_plugin(),
			'preview'   => Settings::is_preview_mode(),
			'i18n'      => array(
				'generating'    => __( 'Generating SEO fields…', 'ai-seo-filler' ),
				'success'       => __( 'SEO fields generated successfully.', 'ai-seo-filler' ),
				'synced'        => __( 'SEO fields updated in the editor.', 'ai-seo-filler' ),
				'error'         => __( 'Failed to generate SEO fields.', 'ai-seo-filler' ),
				'processing'    => __( 'Processing…', 'ai-seo-filler' ),
				'completed'     => __( 'Bulk processing completed.', 'ai-seo-filler' ),
				'cancelled'     => __( 'Bulk processing cancelled.', 'ai-seo-filler' ),
				'confirmBulk'   => __( 'Start bulk SEO generation for the selected content?', 'ai-seo-filler' ),
				'confirmCancel' => __( 'Cancel the current bulk queue?', 'ai-seo-filler' ),
				'previewTitle'  => __( 'SEO Preview', 'ai-seo-filler' ),
				'previewReady'  => __( 'Preview ready. Review and apply.', 'ai-seo-filler' ),
				'apply'         => __( 'Apply changes', 'ai-seo-filler' ),
				'cancel'        => __( 'Cancel', 'ai-seo-filler' ),
				'applying'      => __( 'Applying changes…', 'ai-seo-filler' ),
				'loading'       => __( 'Loading…', 'ai-seo-filler' ),
				'pausing'       => __( 'Pausing…', 'ai-seo-filler' ),
				'resuming'      => __( 'Resuming…', 'ai-seo-filler' ),
				'cancelling'    => __( 'Cancelling…', 'ai-seo-filler' ),
				'score'         => __( 'Rank Math score', 'ai-seo-filler' ),
				'scoreCalculating' => __( 'Calculating…', 'ai-seo-filler' ),
				'scoreRankMathNote' => __( 'Same analyzer as the Rank Math panel.', 'ai-seo-filler' ),
				'focusKeyword'  => __( 'Focus keyword', 'ai-seo-filler' ),
				'testing'       => __( 'Testing API…', 'ai-seo-filler' ),
				'savingKey'     => __( 'Saving key…', 'ai-seo-filler' ),
				'openaiKeySaved' => __( 'OpenAI API key saved.', 'ai-seo-filler' ),
				'openaiKeyOk'   => __( 'OpenAI configured', 'ai-seo-filler' ),
				'showKey'       => __( 'Show', 'ai-seo-filler' ),
				'hideKey'       => __( 'Hide', 'ai-seo-filler' ),
				'copyKey'       => __( 'Copy', 'ai-seo-filler' ),
				'copiedKey'     => __( 'Copied!', 'ai-seo-filler' ),
				'copyFailed'    => __( 'Could not copy the key.', 'ai-seo-filler' ),
				'alertSuccess'  => __( 'Success', 'ai-seo-filler' ),
				'alertError'    => __( 'Error', 'ai-seo-filler' ),
				'alertInfo'     => __( 'AI SEO Filler', 'ai-seo-filler' ),
				'close'         => __( 'Close', 'ai-seo-filler' ),
				'paused'        => __( 'Bulk processing paused.', 'ai-seo-filler' ),
				'resumed'       => __( 'Bulk processing resumed.', 'ai-seo-filler' ),
				'keywordHint'   => __( 'Choose which keyword to save when you apply.', 'ai-seo-filler' ),
				'changesTitle'  => __( 'Changes', 'ai-seo-filler' ),
				'generatingImages' => __( 'Generating images with AI… This may take a minute.', 'ai-seo-filler' ),
				'imagesSuccess' => __( 'Images generated and assigned successfully.', 'ai-seo-filler' ),
				'confirmImages' => __( 'Generate AI images for this item? You will choose the featured image before they are applied.', 'ai-seo-filler' ),
				'imagesSelectTitle' => __( 'Choose featured image', 'ai-seo-filler' ),
				'imagesSelectHint' => __( 'Click an image to set it as featured. The others will be added to the product gallery.', 'ai-seo-filler' ),
				'imagesSelectHintPost' => __( 'Click an image to set it as the featured image.', 'ai-seo-filler' ),
				'imagesFeaturedBadge' => __( 'Featured', 'ai-seo-filler' ),
				'imagesGalleryBadge' => __( 'Gallery', 'ai-seo-filler' ),
				'imagesApply' => __( 'Apply selection', 'ai-seo-filler' ),
				'imagesDiscard' => __( 'Discard', 'ai-seo-filler' ),
				'imagesApplying' => __( 'Applying images…', 'ai-seo-filler' ),
				'imagesSelectRequired' => __( 'Select a featured image first.', 'ai-seo-filler' ),
				'emptyValue'    => __( '(empty)', 'ai-seo-filler' ),
				'diffLabels'    => array(
					'meta_title'       => __( 'SEO title', 'ai-seo-filler' ),
					'meta_description' => __( 'Meta description', 'ai-seo-filler' ),
					'slug'             => __( 'URL slug', 'ai-seo-filler' ),
					'focus_keyword'    => __( 'Focus keyword', 'ai-seo-filler' ),
					'word_count'       => __( 'Word count', 'ai-seo-filler' ),
				),
			),
		);
	}

	public function register_meta_box() {
		foreach ( Bulk::get_allowed_post_types() as $post_type ) {
			add_meta_box( 'ai-seo-filler-metabox', __( 'AI SEO Filler', 'ai-seo-filler' ), array( $this, 'render_meta_box' ), $post_type, 'side', 'high' );
		}
	}

	public function render_meta_box( $post ) {
		$seo_plugin = Core::detect_seo_plugin();
		$has_api    = Settings::has_active_provider_configured();

		echo '<div class="ai-seo-filler-metabox">';

		if ( ! $has_api ) {
			echo '<p class="description">' . esc_html__( 'Configure your AI provider API key under AI SEO Filler → Settings.', 'ai-seo-filler' ) . '</p>';
		} elseif ( 'none' === $seo_plugin ) {
			echo '<p class="description">' . esc_html__( 'No active Rank Math or Yoast SEO plugin detected.', 'ai-seo-filler' ) . '</p>';
		} else {
			printf( '<p class="description">%s</p>', esc_html( sprintf( __( 'Provider: %s', 'ai-seo-filler' ), AI_Provider::get_active_provider_label() ) ) );

			echo '<div class="ai-seo-filler-metabox-actions">';
			printf( '<button type="button" class="button button-primary ai-seo-filler-generate" data-post-id="%d" data-mode="full">%s</button>', (int) $post->ID, esc_html__( 'Generate all SEO', 'ai-seo-filler' ) );
			printf( '<button type="button" class="button ai-seo-filler-generate-meta" data-post-id="%d">%s</button>', (int) $post->ID, esc_html__( 'Meta only', 'ai-seo-filler' ) );
			echo '</div>';

			echo '<div class="ai-seo-filler-metabox-images">';
			echo '<p class="description">' . esc_html__( 'Generate product images separately using the title and description. You will choose the featured image before applying.', 'ai-seo-filler' ) . '</p>';

			if ( Settings::has_image_provider_configured() ) {
				$image_label = WooCommerce::is_product( $post )
					? __( 'Generate images (featured + 3 gallery)', 'ai-seo-filler' )
					: __( 'Generate featured image', 'ai-seo-filler' );
				printf(
					'<button type="button" class="button ai-seo-filler-generate-images" data-post-id="%d" data-is-product="%d">%s</button>',
					(int) $post->ID,
					WooCommerce::is_product( $post ) ? 1 : 0,
					esc_html( $image_label )
				);
				echo '<div class="ai-seo-filler-images-status" aria-live="polite"></div>';
			} else {
				echo '<p class="description">' . esc_html__( 'Configure Flux (free) or an OpenAI/Gemini API key to generate images.', 'ai-seo-filler' ) . '</p>';
			}

			echo '</div>';

			echo '<div class="ai-seo-filler-status" aria-live="polite"></div>';
			echo '<div class="ai-seo-filler-checklist"></div>';

			$history = History::get_for_post( $post->ID );
			if ( ! empty( $history ) ) {
				echo '<p class="description" style="margin-top:10px;"><strong>' . esc_html__( 'Last run:', 'ai-seo-filler' ) . '</strong> ';
				echo esc_html( gmdate( 'Y-m-d H:i', $history[0]['timestamp'] ) );
				echo ' — ' . esc_html( $history[0]['provider'] ?? '' ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( ! in_array( $post->post_type, Bulk::get_allowed_post_types(), true ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) || ! Settings::has_active_provider_configured() ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=ai_seo_filler_generate&post_id=' . $post->ID ),
			'ai_seo_filler_row_' . $post->ID
		);

		$actions['ai_seo_filler'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Generate SEO', 'ai-seo-filler' ) . '</a>';
		return $actions;
	}

	/**
	 * Handles list-table row action generation.
	 */
	public function handle_row_action_generate() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'ai_seo_filler_row_' . $post_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'ai-seo-filler' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}

		$result = $this->core->generate_and_save_seo( $post_id );

		$redirect = get_edit_post_link( $post_id, 'raw' );
		$redirect = add_query_arg(
			'ai_seo_filler_notice',
			is_wp_error( $result ) ? 'error' : 'success',
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
