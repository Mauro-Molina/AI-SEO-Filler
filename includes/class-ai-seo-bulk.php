<?php
/**
 * Bulk SEO processing: queue management and batch execution.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Handles bulk SEO generation via Action Scheduler or WP-Cron.
 */
class Bulk {

	const AS_HOOK = 'ai_seo_filler_process_bulk_batch';

	private $core;

	public function __construct( Core $core ) {
		$this->core = $core;
	}

	public function init() {
		self::register_cron_schedule();

		add_action( 'wp_ajax_ai_seo_filler_start_bulk', array( $this, 'ajax_start_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_bulk_status', array( $this, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_ai_seo_filler_cancel_bulk', array( $this, 'ajax_cancel_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_pause_bulk', array( $this, 'ajax_pause_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_resume_bulk', array( $this, 'ajax_resume_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_bulk_estimate', array( $this, 'ajax_bulk_estimate' ) );

		add_action( 'ai_seo_filler_process_bulk_queue', array( $this, 'process_queue' ) );
		add_action( self::AS_HOOK, array( $this, 'process_queue' ) );
	}

	public static function register_cron_schedule() {
		add_filter( 'cron_schedules', function ( $schedules ) {
			if ( ! isset( $schedules['ai_seo_filler_every_minute'] ) ) {
				$schedules['ai_seo_filler_every_minute'] = array(
					'interval' => MINUTE_IN_SECONDS,
					'display'  => __( 'Every minute (AI SEO Filler)', 'ai-seo-filler' ),
				);
			}
			return $schedules;
		} );
	}

	public static function get_allowed_post_types() {
		$types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	public function ajax_start_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}

		if ( ! Settings::has_active_provider_configured() ) {
			wp_send_json_error( array( 'message' => Settings::get_missing_provider_message() ), 400 );
		}

		if ( 'none' === Core::detect_seo_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'No compatible SEO plugin detected.', 'ai-seo-filler' ) ), 400 );
		}

		$post_type   = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$status      = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';
		$only_empty  = ! empty( $_POST['only_empty'] );
		$days        = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 0;
		$category    = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;

		if ( ! in_array( $post_type, self::get_allowed_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid content type.', 'ai-seo-filler' ) ), 400 );
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( $days > 0 ) {
			$query_args['date_query'] = array(
				array( 'after' => $days . ' days ago' ),
			);
		}

		if ( $category && 'product' === $post_type ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category,
				),
			);
		} elseif ( $category && 'post' === $post_type ) {
			$query_args['cat'] = $category;
		}

		$post_ids = get_posts( $query_args );

		if ( $only_empty || Settings::is_only_if_empty() ) {
			$post_ids = array_values( array_filter( $post_ids, function ( $id ) {
				return ! SEO_Checker::has_existing_seo( $id );
			} ) );
		}

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No posts found to process.', 'ai-seo-filler' ) ), 404 );
		}

		update_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array(
			'pending'    => $post_ids,
			'processed'  => array(),
			'errors'     => array(),
			'total'      => count( $post_ids ),
			'started'    => time(),
			'post_type'  => $post_type,
			'status'     => 'processing',
			'paused'     => false,
		), false );

		$this->schedule_next_batch( 0 );

		wp_send_json_success( array(
			'message' => sprintf( __( '%d posts queued for processing.', 'ai-seo-filler' ), count( $post_ids ) ),
			'total'   => count( $post_ids ),
			'estimate_minutes' => $this->estimate_minutes( count( $post_ids ) ),
		) );
	}

	public function ajax_bulk_estimate() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );
		$total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
		wp_send_json_success( array( 'estimate_minutes' => $this->estimate_minutes( $total ) ) );
	}

	public function ajax_pause_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}
		$queue = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );
		$queue['paused'] = true;
		$queue['status'] = 'paused';
		update_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, $queue, false );
		wp_send_json_success( array( 'message' => __( 'Bulk processing paused.', 'ai-seo-filler' ) ) );
	}

	public function ajax_resume_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}
		$queue = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );
		$queue['paused'] = false;
		$queue['status'] = 'processing';
		update_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, $queue, false );
		$this->schedule_next_batch( 0 );
		wp_send_json_success( array( 'message' => __( 'Bulk processing resumed.', 'ai-seo-filler' ) ) );
	}

	public function ajax_bulk_status() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}
		wp_send_json_success( $this->get_queue_status() );
	}

	public function ajax_cancel_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-seo-filler' ) ), 403 );
		}
		delete_option( AI_SEO_FILLER_OPTION_BULK_QUEUE );
		wp_send_json_success( array( 'message' => __( 'Bulk processing cancelled.', 'ai-seo-filler' ) ) );
	}

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
				'paused'    => false,
			);
		}

		$status = 'processing';
		if ( ! empty( $queue['paused'] ) ) {
			$status = 'paused';
		} elseif ( empty( $queue['pending'] ) ) {
			$status = 'completed';
		}

		return array(
			'status'    => $status,
			'total'     => (int) $queue['total'],
			'processed' => count( $queue['processed'] ),
			'pending'   => count( $queue['pending'] ),
			'errors'    => count( $queue['errors'] ),
			'error_log' => array_slice( $queue['errors'], -10 ),
			'paused'    => ! empty( $queue['paused'] ),
		);
	}

	public function process_queue() {
		$queue = get_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, array() );

		if ( empty( $queue['pending'] ) || ! empty( $queue['paused'] ) ) {
			return;
		}

		$batch = array_splice( $queue['pending'], 0, AI_SEO_FILLER_BULK_BATCH_SIZE );
		$delay = Settings::get_bulk_rate_limit();

		foreach ( $batch as $index => $post_id ) {
			if ( $index > 0 && $delay > 0 ) {
				sleep( $delay );
			}

			$result = $this->core->generate_and_save_seo( (int) $post_id );

			if ( is_wp_error( $result ) ) {
				$queue['errors'][] = array(
					'post_id' => (int) $post_id,
					'message' => $result->get_error_message(),
					'time'    => time(),
				);
			} else {
				$queue['processed'][] = (int) $post_id;
			}
		}

		$queue['status'] = empty( $queue['pending'] ) ? 'completed' : 'processing';
		update_option( AI_SEO_FILLER_OPTION_BULK_QUEUE, $queue, false );

		if ( ! empty( $queue['pending'] ) && empty( $queue['paused'] ) ) {
			$this->schedule_next_batch( 5 );
		}
	}

	/**
	 * @param int $delay Seconds before next batch.
	 */
	private function schedule_next_batch( $delay = 5 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::AS_HOOK, array(), 'ai-seo-filler' );
			return;
		}

		if ( ! wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' ) ) {
			wp_schedule_event( time() + $delay, 'ai_seo_filler_every_minute', 'ai_seo_filler_process_bulk_queue' );
		}
	}

	/**
	 * @param int $count Post count.
	 * @return int Estimated minutes.
	 */
	private function estimate_minutes( $count ) {
		$batch_size = AI_SEO_FILLER_BULK_BATCH_SIZE;
		$rate       = max( 1, Settings::get_bulk_rate_limit() );
		$batches    = ceil( $count / $batch_size );
		return (int) ceil( ( $batches * ( 30 + $batch_size * $rate ) ) / 60 );
	}
}
