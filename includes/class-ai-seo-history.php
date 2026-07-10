<?php
/**
 * Generation history per post.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves SEO generation history.
 */
class History {

	const META_KEY = '_ai_seo_filler_history';

	const MAX_ENTRIES = 20;

	/**
	 * Records a generation event.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_data Generated data snapshot.
	 * @param array $meta     Extra metadata (provider, mode, etc.).
	 */
	public static function record( $post_id, $seo_data, $meta = array() ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		$history = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry = array_merge(
			array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'provider'  => Settings::get_ai_provider(),
				'model'     => Settings::get_active_model(),
				'fields'    => array_keys( array_filter( (array) $seo_data ) ),
			),
			$meta
		);

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );

		update_post_meta( $post_id, self::META_KEY, $history );
	}

	/**
	 * Returns history entries for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_for_post( $post_id ) {
		$history = get_post_meta( absint( $post_id ), self::META_KEY, true );

		return is_array( $history ) ? $history : array();
	}
}
