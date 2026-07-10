<?php
/**
 * Creates revision backups before SEO overwrites.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Revision backup helper.
 */
class Backup {

	/**
	 * Creates a revision of the post before changes when enabled in settings.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Revision ID or false.
	 */
	public static function maybe_create_revision( $post_id ) {
		if ( ! Settings::is_revision_backup_enabled() ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post || wp_is_post_revision( $post ) ) {
			return false;
		}

		if ( ! wp_revisions_enabled( $post ) ) {
			return false;
		}

		$revision_id = wp_save_post_revision( $post_id );

		if ( $revision_id ) {
			Logger::info( 'Revision backup created', array( 'post_id' => $post_id, 'revision_id' => $revision_id ) );
		}

		return $revision_id;
	}
}
