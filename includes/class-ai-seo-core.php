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

	private static $instance = null;
	private $settings;
	private $bulk;
	private $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->bulk     = new Bulk( $this );
	}

	public function init() {
		$this->load_textdomain();
		$this->settings->init();
		$this->bulk->init();
		RankMath::init();

		add_action( 'wp_ajax_ai_seo_filler_generate_single', array( $this, 'ajax_generate_single' ) );
		add_action( 'wp_ajax_ai_seo_filler_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_ai_seo_filler_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_ai_seo_filler_generate_images', array( $this, 'ajax_generate_images' ) );
		add_action( 'wp_ajax_ai_seo_filler_apply_images', array( $this, 'ajax_apply_images' ) );
		add_action( 'wp_ajax_ai_seo_filler_discard_images', array( $this, 'ajax_discard_images' ) );
		add_action( 'wp_ajax_ai_seo_filler_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_ai_seo_filler_test_api', array( $this, 'ajax_test_api' ) );
		add_action( 'wp_ajax_ai_seo_filler_save_openai_key', array( $this, 'ajax_save_openai_key' ) );
		add_action( 'wp_ajax_ai_seo_filler_test_openai_key', array( $this, 'ajax_test_openai_key' ) );
		add_action( 'wp_ajax_ai_seo_filler_export_log', array( $this, 'ajax_export_log' ) );

		if ( is_admin() ) {
			$this->admin = new Admin\Admin( $this );
			$this->admin->init();
		}
	}

	public function load_textdomain() {
		add_filter( 'load_textdomain_mofile', array( $this, 'filter_textdomain_mofile' ), 10, 2 );

		load_plugin_textdomain(
			'ai-seo-filler',
			false,
			dirname( AI_SEO_FILLER_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Falls back to es_ES for other Spanish locales when a specific MO is missing.
	 *
	 * @param string $mofile Path to the .mo file.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function filter_textdomain_mofile( $mofile, $domain ) {
		if ( 'ai-seo-filler' !== $domain ) {
			return $mofile;
		}

		if ( is_readable( $mofile ) ) {
			return $mofile;
		}

		$locale = determine_locale();

		if ( 0 === strpos( $locale, 'es_' ) && 'es_ES' !== $locale ) {
			$fallback = AI_SEO_FILLER_PLUGIN_DIR . 'languages/ai-seo-filler-es_ES.mo';

			if ( is_readable( $fallback ) ) {
				return $fallback;
			}
		}

		return $mofile;
	}

	public static function detect_seo_plugin() {
		$forced = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin', 'auto' );
		if ( 'rankmath' === $forced ) {
			return 'rankmath';
		}
		if ( 'yoast' === $forced ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		return 'none';
	}

	/**
	 * AJAX: generate (preview or direct save based on settings).
	 */
	public function ajax_generate_single() {
		$post_id = $this->verify_editor_request();
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'full';
		$args = array( 'mode' => in_array( $mode, array( 'full', 'meta_only' ), true ) ? $mode : 'full' );

		if ( Settings::is_preview_mode() ) {
			$this->ajax_preview_internal( $post_id, $args );
			return;
		}

		$result = $this->generate_and_save_seo( $post_id, $args );
		$this->send_success_response( $post_id, $result );
	}

	/**
	 * AJAX: preview without saving.
	 */
	public function ajax_preview() {
		$post_id = $this->verify_editor_request();
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'full';
		$args = array( 'mode' => in_array( $mode, array( 'full', 'meta_only' ), true ) ? $mode : 'full' );

		$this->ajax_preview_internal( $post_id, $args );
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $args    Generation args.
	 */
	private function ajax_preview_internal( $post_id, $args ) {
		if ( Settings::is_only_if_empty() && SEO_Checker::has_existing_seo( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This post already has SEO fields. Disable "Only if empty" in settings to overwrite.', 'ai-seo-filler' ) ), 400 );
		}

		$result = $this->generate_seo( $post_id, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$preview_key = $this->store_preview( $post_id, $result, $args );
		$diff        = $this->build_diff( $post_id, $result );
		$checklist   = SEO_Checker::build_checklist( $result, $post_id );
		$editor_meta = array();

		if ( 'rankmath' === Core::detect_seo_plugin() ) {
			$editor_meta = RankMath::build_editor_meta( $result );
		} elseif ( 'yoast' === Core::detect_seo_plugin() ) {
			$editor_meta = Yoast::build_editor_meta( $result );
		}

		wp_send_json_success( array(
			'preview'     => true,
			'previewKey'  => $preview_key,
			'data'        => $result,
			'editorMeta'  => $editor_meta,
			'diff'        => $diff,
			'checklist'   => $checklist,
			'alternatives' => $result['keyword_alternatives'] ?? array(),
			'message'     => __( 'Preview ready. Review and apply changes.', 'ai-seo-filler' ),
		) );
	}

	/**
	 * AJAX: apply previewed SEO data.
	 */
	public function ajax_apply() {
		$post_id = $this->verify_editor_request();
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$preview_key    = isset( $_POST['preview_key'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_key'] ) ) : '';
		$focus_keyword  = isset( $_POST['focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) : '';
		$stored         = get_transient( 'ai_seo_fill_' . $preview_key );

		if ( empty( $stored ) || (int) $stored['post_id'] !== $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Preview expired. Generate again.', 'ai-seo-filler' ) ), 400 );
		}

		$seo_data = $stored['data'];
		$args     = $stored['args'] ?? array();

		if ( $focus_keyword ) {
			$seo_data['focus_keyword'] = $focus_keyword;
			$seo_data = AI_Content::enforce_rankmath_rules( $seo_data );
		}

		$result = $this->apply_seo_data( $post_id, $seo_data, $args );
		delete_transient( 'ai_seo_fill_' . $preview_key );

		$this->send_success_response( $post_id, $result );
	}

	/**
	 * AJAX: generate featured + gallery images with AI (staged for selection).
	 */
	public function ajax_generate_images() {
		$post_id = $this->verify_editor_request();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$result = AI_Images::generate_for_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$is_product = ! empty( $result['is_product'] );
		$message    = $is_product
			? __( 'Images ready. Choose the featured image; the rest will go to the product gallery.', 'ai-seo-filler' )
			: __( 'Images ready. Choose which one to use as the featured image.', 'ai-seo-filler' );

		wp_send_json_success(
			array(
				'message'            => $message,
				'staging_key'        => $result['staging_key'] ?? '',
				'images'             => $result['images'] ?? array(),
				'suggested_featured' => $result['suggested_featured'] ?? 0,
				'provider'           => $result['provider'] ?? '',
				'is_product'         => $is_product,
				'count'              => $result['count'] ?? 0,
				'needs_selection'    => true,
			)
		);
	}

	/**
	 * AJAX: apply featured + gallery selection from staged images.
	 */
	public function ajax_apply_images() {
		$post_id = $this->verify_editor_request();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$staging_key = isset( $_POST['staging_key'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_key'] ) ) : '';
		$featured_id = isset( $_POST['featured_id'] ) ? absint( $_POST['featured_id'] ) : 0;
		$gallery_ids = array();

		if ( isset( $_POST['gallery_ids'] ) ) {
			$raw = wp_unslash( $_POST['gallery_ids'] );

			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
			}

			$gallery_ids = array_map( 'absint', (array) $raw );
		}

		$result = AI_Images::apply_selection( $post_id, $staging_key, $featured_id, $gallery_ids );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$is_product = ! empty( $result['is_product'] );
		$message    = $is_product
			? sprintf(
				/* translators: %d: gallery image count */
				__( 'Featured image set and %d gallery images assigned.', 'ai-seo-filler' ),
				count( $result['gallery_ids'] ?? array() )
			)
			: __( 'Featured image assigned successfully.', 'ai-seo-filler' );

		wp_send_json_success(
			array(
				'message'     => $message,
				'images'      => $result['images'] ?? array(),
				'featured_id' => $result['featured_id'] ?? 0,
				'gallery_ids' => $result['gallery_ids'] ?? array(),
				'provider'    => $result['provider'] ?? '',
				'is_product'  => ! empty( $result['is_product'] ),
				'editor'      => $result['editor'] ?? array(),
				'can_undo'    => History::can_undo( $post_id ),
			)
		);
	}

	/**
	 * AJAX: discard staged images without applying.
	 */
	public function ajax_discard_images() {
		$post_id = $this->verify_editor_request();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$staging_key = isset( $_POST['staging_key'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_key'] ) ) : '';
		$result      = AI_Images::discard_staged( $post_id, $staging_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Staged images discarded.', 'ai-seo-filler' ) ) );
	}

	/**
	 * AJAX: undo the last SEO or image apply for a post.
	 */
	public function ajax_undo() {
		$post_id = $this->verify_editor_request();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$result = History::undo_last( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$type    = $result['type'] ?? 'seo';
		$message = 'images' === $type
			? __( 'Images restored to the previous state.', 'ai-seo-filler' )
			: __( 'Previous SEO state restored.', 'ai-seo-filler' );

		wp_send_json_success(
			array_merge(
				$result,
				array(
					'message'  => $message,
					'can_undo' => History::can_undo( $post_id ),
				)
			)
		);
	}

	/**
	 * AJAX: test API connection.
	 */
	public function ajax_test_api() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}

		$result = AI_Provider::test_active_connection();

		if ( is_wp_error( $result ) ) {
			if ( 'fallback_provider_ok' === $result->get_error_code() ) {
				wp_send_json_success( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'API connection successful.', 'ai-seo-filler' ) ) );
	}

	/**
	 * AJAX: save OpenAI API key without relying on the settings form field name.
	 */
	public function ajax_save_openai_key() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? Settings::normalize_secret_key( wp_unslash( $_POST['key'] ) ) : '';

		$result = Settings::persist_openai_api_key( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'OpenAI API key saved.', 'ai-seo-filler' ),
				'configured'  => Settings::has_openai_api_key(),
			)
		);
	}

	/**
	 * AJAX: test OpenAI API key (field value or stored key).
	 */
	public function ajax_test_openai_key() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? Settings::normalize_secret_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( Settings::is_secret_placeholder( $key ) ) {
			$key = Settings::get_openai_api_key();
		}

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter your OpenAI API key first.', 'ai-seo-filler' ) ), 400 );
		}

		if ( ! Settings::looks_like_openai_api_key( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid OpenAI API key. Keys must start with "sk-" (for example sk-proj-…).', 'ai-seo-filler' ) ), 400 );
		}

		$openai = new OpenAI();
		$result = $openai->test_connection( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'OpenAI API connection successful.', 'ai-seo-filler' ) ) );
	}

	/**
	 * AJAX: export bulk error log as CSV.
	 */
	public function ajax_export_log() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}

		$queue  = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );
		$errors = $queue['errors'] ?? array();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ai-seo-filler-errors.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'post_id', 'message', 'timestamp' ) );

		foreach ( $errors as $error ) {
			fputcsv( $out, array( $error['post_id'] ?? '', $error['message'] ?? '', isset( $error['time'] ) ? gmdate( 'c', $error['time'] ) : '' ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Generates SEO without saving.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Args.
	 * @return array|\WP_Error
	 */
	public function generate_seo( $post_id, $args = array() ) {
		if ( Settings::is_only_if_empty() && SEO_Checker::has_existing_seo( $post_id ) ) {
			return new \WP_Error( 'seo_exists', __( 'Post already has SEO fields.', 'ai-seo-filler' ) );
		}

		return AI_Provider::generate_seo_data( $post_id, $args );
	}

	/**
	 * Saves SEO data to post and SEO plugin.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_data SEO data.
	 * @param array $args     Args.
	 * @return array|\WP_Error
	 */
	public function apply_seo_data( $post_id, $seo_data, $args = array() ) {
		$seo_data = $this->filter_by_settings( $seo_data, $args );
		$seo_data = AI_Content::enforce_rankmath_rules( $seo_data );
		$seo_plugin = self::detect_seo_plugin();

		if ( 'none' === $seo_plugin ) {
			return new \WP_Error( 'no_seo_plugin', __( 'No compatible SEO plugin detected.', 'ai-seo-filler' ) );
		}

		Backup::maybe_create_revision( $post_id );

		$before = History::capture_before_seo( $post_id, $seo_data );

		if ( Settings::should_generate_meta() ) {
			if ( 'rankmath' === $seo_plugin ) {
				( new RankMath() )->save_seo_data( $post_id, $seo_data );
			} else {
				( new Yoast() )->save_seo_data( $post_id, $seo_data );
			}
		}

		$post_updates = array( 'ID' => $post_id );

		if ( Settings::should_generate_slug() && ! empty( $seo_data['slug'] ) ) {
			$keyword = AI_Content::primary_focus_keyword( $seo_data['focus_keyword'] ?? '' );
			$post_updates['post_name'] = AI_Content::ensure_slug_has_keyword( $seo_data['slug'], $keyword );
			$seo_data['slug']          = $post_updates['post_name'];
		}

		if ( Settings::should_generate_content() && ! empty( $seo_data['optimized_content'] ) ) {
			$post_updates['post_content'] = $seo_data['optimized_content'];
		}

		if ( count( $post_updates ) > 1 ) {
			wp_update_post( $post_updates );
		}

		if ( Settings::should_generate_short_desc() && ! empty( $seo_data['short_description'] ) ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_excerpt' => sanitize_textarea_field( $seo_data['short_description'] ),
			) );
		}

		History::record(
			$post_id,
			$seo_data,
			array(
				'mode'   => $args['mode'] ?? 'full',
				'action' => 'apply_seo',
				'before' => $before,
			)
		);

		/**
		 * Fires after SEO data is saved to a post.
		 *
		 * @param int   $post_id  Post ID.
		 * @param array $seo_data Saved SEO data.
		 */
		do_action( 'ai_seo_filler_after_save', $post_id, $seo_data );

		return $seo_data;
	}

	/**
	 * Full generate + save pipeline.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Args.
	 * @return array|\WP_Error
	 */
	public function generate_and_save_seo( $post_id, $args = array() ) {
		$seo_data = $this->generate_seo( $post_id, $args );

		if ( is_wp_error( $seo_data ) ) {
			return $seo_data;
		}

		return $this->apply_seo_data( $post_id, $seo_data, $args );
	}

	/**
	 * @param int                $post_id Post ID.
	 * @param array|\WP_Error    $result  Result.
	 */
	private function send_success_response( $post_id, $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		$seo_plugin  = self::detect_seo_plugin();
		$editor_meta = array();

		if ( 'rankmath' === $seo_plugin ) {
			$editor_meta = RankMath::build_editor_meta( $result );
		} elseif ( 'yoast' === $seo_plugin ) {
			$editor_meta = Yoast::build_editor_meta( $result );
		}

		wp_send_json_success( array(
			'message'    => __( 'SEO fields generated and saved successfully.', 'ai-seo-filler' ),
			'data'       => $result,
			'editorMeta' => $editor_meta,
			'seoPlugin'  => $seo_plugin,
			'checklist'  => SEO_Checker::build_checklist( $result, $post_id ),
			'can_undo'   => History::can_undo( $post_id ),
		) );
	}

	/**
	 * @return int|\WP_Error
	 */
	private function verify_editor_request() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'ai-seo-filler' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid post.', 'ai-seo-filler' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit this post.', 'ai-seo-filler' ) );
		}

		if ( ! Settings::is_post_type_enabled( get_post_type( $post_id ) ) ) {
			return new \WP_Error( 'unsupported_type', __( 'This content type is not enabled in AI SEO Filler settings.', 'ai-seo-filler' ) );
		}

		return $post_id;
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $data    SEO data.
	 * @param array $args    Args.
	 * @return string Preview key.
	 */
	private function store_preview( $post_id, $data, $args ) {
		$key = wp_generate_password( 20, false );
		set_transient( 'ai_seo_fill_' . $key, array(
			'post_id' => $post_id,
			'data'    => $data,
			'args'    => $args,
			'user_id' => get_current_user_id(),
		), HOUR_IN_SECONDS );
		return $key;
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $seo_data New SEO data.
	 * @return array
	 */
	private function build_diff( $post_id, $seo_data ) {
		$post   = get_post( $post_id );
		$plugin = self::detect_seo_plugin();

		$current_title = '';
		$current_desc  = '';

		if ( 'rankmath' === $plugin ) {
			$current_title = get_post_meta( $post_id, 'rank_math_title', true );
			$current_desc  = get_post_meta( $post_id, 'rank_math_description', true );
		} elseif ( 'yoast' === $plugin ) {
			$current_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$current_desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}

		return array(
			'meta_title'       => array( 'old' => $current_title, 'new' => $seo_data['meta_title'] ?? '' ),
			'meta_description' => array( 'old' => $current_desc, 'new' => $seo_data['meta_description'] ?? '' ),
			'slug'             => array( 'old' => $post->post_name, 'new' => $seo_data['slug'] ?? '' ),
			'focus_keyword'    => array( 'old' => '', 'new' => $seo_data['focus_keyword'] ?? '' ),
			'word_count'       => array(
				'old' => AI_Content::count_words( $post->post_content ),
				'new' => AI_Content::count_words( $seo_data['optimized_content'] ?? '' ),
			),
		);
	}

	/**
	 * @param array $seo_data SEO data.
	 * @param array $args     Args.
	 * @return array
	 */
	private function filter_by_settings( $seo_data, $args ) {
		$mode = $args['mode'] ?? 'full';

		if ( 'meta_only' === $mode || ! Settings::should_generate_content() ) {
			unset( $seo_data['optimized_content'] );
		}

		if ( ! Settings::should_generate_slug() ) {
			unset( $seo_data['slug'] );
		}

		if ( ! Settings::should_generate_short_desc() ) {
			unset( $seo_data['short_description'] );
		}

		if ( ! Settings::should_generate_image_alts() ) {
			$seo_data['image_alts'] = array();
		}

		if ( ! Settings::should_generate_meta() ) {
			foreach ( array( 'meta_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description' ) as $k ) {
				unset( $seo_data[ $k ] );
			}
		}

		return $seo_data;
	}

	private function __clone() {}

	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize a singleton.' );
	}
}
