<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes options, post meta, cron events, and Action Scheduler jobs.
 *
 * @package AiSeoFiller
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Runs uninstall cleanup (scoped to avoid global variable prefix warnings).
 */
function aiseofiller_uninstall() {
	global $wpdb;

	$option_like = $wpdb->esc_like( 'ai_seo_filler_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$option_like
		)
	);

	if ( is_multisite() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

		if ( $site_ids ) {
			foreach ( $site_ids as $blog_id ) {
				switch_to_blog( (int) $blog_id );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
						$option_like
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
						'_ai_seo_filler_history',
						'_ai_seo_filler_staged',
						'_ai_seo_filler_suggested_role'
					)"
				);

				wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_queue' );
				wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_batch' );

				restore_current_blog();
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$option_like
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
				'_ai_seo_filler_history',
				'_ai_seo_filler_staged',
				'_ai_seo_filler_suggested_role'
			)"
		);
	}

	$transient_like         = $wpdb->esc_like( '_transient_ai_seo_filler_' ) . '%';
	$transient_timeout_like = $wpdb->esc_like( '_transient_timeout_ai_seo_filler_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$transient_timeout_like
		)
	);

	wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_queue' );
	wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_batch' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'ai_seo_filler_process_bulk_batch', array(), 'ai-seo-filler' );
	}
}

aiseofiller_uninstall();
