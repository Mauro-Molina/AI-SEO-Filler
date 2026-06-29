<?php
/**
 * Bulk SEO processing: queue management and WP-Cron batch execution.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Handles bulk SEO generation via a queued WP-Cron workflow.
 */
class Bulk {

	/**
	 * Core instance used to generate and save SEO data.
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
	 * Registers bulk-related hooks.
	 */
	public function init() {
		self::register_cron_schedule();

		add_action( 'wp_ajax_ai_seo_filler_start_bulk', array( $this, 'ajax_start_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_bulk_status', array( $this, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_ai_seo_filler_cancel_bulk', array( $this, 'ajax_cancel_bulk' ) );
		add_action( 'ai_seo_filler_process_bulk_queue', array( $this, 'process_queue' ) );
	}

	/**
	 * Registers the custom WP-Cron interval for bulk processing.
	 */
	public static function register_cron_schedule() {
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				if ( ! isset( $schedules['ai_seo_filler_every_minute'] ) ) {
					$schedules['ai_seo_filler_every_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						'display'  => __( 'Every minute (AI SEO Filler)', 'ai-seo-filler' ),
					);
				}

				return $schedules;
			}
		);
	}

	/**
	 * Returns the list of post types available for bulk processing.
	 *
	 * @return string[]
	 */
	public static function get_allowed_post_types() {
		$types = array( 'post', 'page' );

		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}

		return $types;
	}

	/**
	 * AJAX handler: enqueues posts for bulk SEO generation.
	 */
	public function ajax_start_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ),
				403
			);
		}

		if ( ! Settings::has_active_provider_configured() ) {
			wp_send_json_error(
				array( 'message' => Settings::get_missing_provider_message() ),
				400
			);
		}

		if ( 'none' === Core::detect_seo_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'No compatible SEO plugin detected.', 'ai-seo-filler' ) ),
				400
			);
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$status    = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';

		if ( ! in_array( $post_type, self::get_allowed_post_types(), true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid content type.', 'ai-seo-filler' ) ),
				400
			);
		}

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid post status.', 'ai-seo-filler' ) ),
				400
			);
		}

		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $post_ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No posts found to process.', 'ai-seo-filler' ) ),
				404
			);
		}

		update_option(
			AI_SEO_FILLER_OPTION_BULK_QUEUE,
			array(
				'pending'   => $post_ids,
				'processed' => array(),
				'errors'    => array(),
				'total'     => count( $post_ids ),
				'started'   => time(),
				'post_type' => $post_type,
				'status'    => $status,
			),
			false
		);

		// Ensure the cron event is scheduled.
		if ( ! wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' ) ) {
			wp_schedule_event( time(), 'ai_seo_filler_every_minute', 'ai_seo_filler_process_bulk_queue' );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of queued posts */
					__( '%d posts queued for processing.', 'ai-seo-filler' ),
					count( $post_ids )
				),
				'total'   => count( $post_ids ),
			)
		);
	}

	/**
	 * AJAX handler: returns the current bulk queue status.
	 */
	public function ajax_bulk_status() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ),
				403
			);
		}

		wp_send_json_success( $this->get_queue_status() );
	}

	/**
	 * AJAX handler: cancels the current bulk queue.
	 */
	public function ajax_cancel_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ),
				403
			);
		}

		delete_option( AI_SEO_FILLER_OPTION_BULK_QUEUE );

		wp_send_json_success(
			array( 'message' => __( 'Bulk processing cancelled.', 'ai-seo-filler' ) )
		);
	}

	/**
	 * Returns a normalized snapshot of the bulk queue state.
	 *
	 * @return array
	 */
	public function get_queue_status() {
		$queue = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );

		if ( empty( $queue ) || ! isset( $queue['total'] ) ) {
			return array(
				'status'    => 'idle',
				'total'     => 0,
				'processed' => 0,
				'pending'   => 0,
				'errors'    => 0,
				'error_log' => array(),
			);
		}

		return array(
			'status'    => empty( $queue['pending'] ) ? 'completed' : 'processing',
			'total'     => (int) $queue['total'],
			'processed' => count( $queue['processed'] ),
			'pending'   => count( $queue['pending'] ),
			'errors'    => count( $queue['errors'] ),
			'error_log' => array_slice( $queue['errors'], -10 ),
		);
	}

	/**
	 * Processes one batch from the bulk queue via WP-Cron.
	 */
	public function process_queue() {
		$queue = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );

		if ( empty( $queue['pending'] ) ) {
			return;
		}

		$batch = array_splice( $queue['pending'], 0, AI_SEO_FILLER_BULK_BATCH_SIZE );

		foreach ( $batch as $post_id ) {
			$result = $this->core->generate_and_save_seo( (int) $post_id );

			if ( is_wp_error( $result ) ) {
				$queue['errors'][] = array(
					'post_id' => (int) $post_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$queue['processed'][] = (int) $post_id;
			}
		}

		update_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, $queue, false );
	}
}
