<?php
/**
 * WP-CLI commands for AI SEO Filler.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI integration.
 */
class CLI {

	/**
	 * Registers WP-CLI commands when available.
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'ai-seo', self::class );
	}

	/**
	 * Generates SEO for a post.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Post ID to process.
	 *
	 * [--post-type=<type>]
	 * : Bulk generate for post type.
	 *
	 * [--only-empty]
	 * : Skip posts that already have SEO meta.
	 *
	 * [--dry-run]
	 * : Generate without saving.
	 *
	 * [--meta-only]
	 * : Generate meta fields only (no content rewrite).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-seo generate --post-id=42
	 *     wp ai-seo generate --post-type=product --only-empty
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function generate( $args, $assoc_args ) {
		$core     = Core::instance();
		$dry_run  = isset( $assoc_args['dry-run'] );
		$only_empty = isset( $assoc_args['only-empty'] );
		$meta_only  = isset( $assoc_args['meta-only'] );

		$gen_args = array(
			'mode' => $meta_only ? 'meta_only' : 'full',
		);

		if ( ! empty( $assoc_args['post-id'] ) ) {
			$post_id = absint( $assoc_args['post-id'] );

			if ( $only_empty && SEO_Checker::has_existing_seo( $post_id ) ) {
				\WP_CLI::warning( "Post #{$post_id} already has SEO. Skipping." );
				return;
			}

			if ( $dry_run ) {
				$result = AI_Provider::generate_seo_data( $post_id, $gen_args );
			} else {
				$result = $core->generate_and_save_seo( $post_id, $gen_args );
			}

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( $dry_run ? 'Generated (dry run).' : "SEO saved for post #{$post_id}." );
			return;
		}

		if ( empty( $assoc_args['post-type'] ) ) {
			\WP_CLI::error( 'Provide --post-id or --post-type.' );
		}

		$post_type = sanitize_text_field( $assoc_args['post-type'] );

		if ( ! Settings::is_post_type_enabled( $post_type ) ) {
			\WP_CLI::error( 'Post type is not enabled in AI SEO Filler settings: ' . $post_type );
		}

		$post_ids  = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$processed = 0;
		$skipped   = 0;
		$errors    = 0;

		foreach ( $post_ids as $post_id ) {
			if ( $only_empty && SEO_Checker::has_existing_seo( $post_id ) ) {
				++$skipped;
				continue;
			}

			$result = $dry_run
				? AI_Provider::generate_seo_data( $post_id, $gen_args )
				: $core->generate_and_save_seo( $post_id, $gen_args );

			if ( is_wp_error( $result ) ) {
				++$errors;
				\WP_CLI::warning( "Post #{$post_id}: " . $result->get_error_message() );
				continue;
			}

			++$processed;
		}

		\WP_CLI::success( "Done. Processed: {$processed}, skipped: {$skipped}, errors: {$errors}." );
	}

	/**
	 * Tests the active AI provider API connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-seo test-api
	 */
	public function test_api() {
		$result = AI_Provider::test_active_connection();

		if ( is_wp_error( $result ) ) {
			if ( 'fallback_provider_ok' === $result->get_error_code() ) {
				\WP_CLI::success( $result->get_error_message() );
			}

			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'API connection OK.' );
	}
}
