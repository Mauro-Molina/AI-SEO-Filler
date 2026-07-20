<?php
/**
 * Generation history per post (with undo snapshots).
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves SEO generation history, including restore snapshots.
 */
class History {

	const META_KEY = '_ai_seo_filler_history';

	const MAX_ENTRIES = 20;

	/**
	 * Records a generation event.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_data Generated data snapshot (keys only stored in fields).
	 * @param array $meta     Extra metadata (provider, mode, before, etc.).
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

		// Keep the before-snapshot only on the latest entry (single-level undo).
		for ( $i = 1, $count = count( $history ); $i < $count; $i++ ) {
			unset( $history[ $i ]['before'] );
		}

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

	/**
	 * Whether the latest history entry can be undone.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_undo( $post_id ) {
		return null !== self::get_undoable_entry( $post_id );
	}

	/**
	 * Returns the latest undoable history entry, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_undoable_entry( $post_id ) {
		$history = self::get_for_post( $post_id );

		if ( empty( $history[0] ) || ! is_array( $history[0] ) ) {
			return null;
		}

		$entry = $history[0];

		if ( empty( $entry['before'] ) || ! is_array( $entry['before'] ) || ! empty( $entry['undone'] ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * Captures post + SEO plugin state before an SEO apply.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_data Incoming SEO data (used to know which alts to snapshot).
	 * @return array
	 */
	public static function capture_before_seo( $post_id, $seo_data = array() ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$seo_plugin = Core::detect_seo_plugin();
		$meta       = array();

		foreach ( self::get_seo_meta_keys( $seo_plugin ) as $meta_key ) {
			$value            = get_post_meta( $post_id, $meta_key, true );
			$meta[ $meta_key ] = is_string( $value ) ? $value : (string) $value;
		}

		$image_alts = array();

		if ( ! empty( $seo_data['image_alts'] ) && is_array( $seo_data['image_alts'] ) ) {
			foreach ( array_keys( $seo_data['image_alts'] ) as $attachment_id ) {
				$attachment_id = absint( $attachment_id );

				if ( ! $attachment_id ) {
					continue;
				}

				$alt                          = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				$image_alts[ $attachment_id ] = is_string( $alt ) ? $alt : '';
			}
		}

		return array(
			'type'         => 'seo',
			'seo_plugin'   => $seo_plugin,
			'meta'         => $meta,
			'post_name'    => $post->post_name,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'image_alts'   => $image_alts,
		);
	}

	/**
	 * Captures featured/gallery state before an image apply.
	 *
	 * @param int  $post_id    Post ID.
	 * @param bool $is_product Whether the post is a WooCommerce product.
	 * @return array
	 */
	public static function capture_before_images( $post_id, $is_product = false ) {
		$post_id     = absint( $post_id );
		$featured_id = (int) get_post_thumbnail_id( $post_id );
		$gallery_ids = array();

		if ( $is_product && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product ) {
				$featured_id = (int) $product->get_image_id();
				$gallery_ids = array_values( array_map( 'intval', $product->get_gallery_image_ids() ) );
			}
		}

		return array(
			'type'        => 'images',
			'is_product'  => (bool) $is_product,
			'featured_id' => $featured_id,
			'gallery_ids' => $gallery_ids,
		);
	}

	/**
	 * Restores the state captured before the last apply (SEO or images).
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error Restored payload for the editor, or error.
	 */
	public static function undo_last( $post_id ) {
		$post_id = absint( $post_id );
		$entry   = self::get_undoable_entry( $post_id );

		if ( ! $entry ) {
			return new \WP_Error( 'nothing_to_undo', __( 'Nothing to undo for this post.', 'ai-seo-filler' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit this post.', 'ai-seo-filler' ) );
		}

		$before = $entry['before'];
		$type   = $before['type'] ?? ( isset( $entry['action'] ) && 'apply_images' === $entry['action'] ? 'images' : 'seo' );

		if ( 'images' === $type ) {
			$result = self::restore_images( $post_id, $before, $entry );
		} else {
			$result = self::restore_seo( $post_id, $before );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_undone( $post_id );

		Logger::info(
			'Undo applied',
			array(
				'post_id' => $post_id,
				'type'    => $type,
			)
		);

		/**
		 * Fires after an undo restores a previous state.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $result  Restored payload.
		 * @param array $entry   History entry that was undone.
		 */
		do_action( 'ai_seo_filler_after_undo', $post_id, $result, $entry );

		return $result;
	}

	/**
	 * Meta keys managed by the active SEO plugin.
	 *
	 * @param string $seo_plugin Plugin slug.
	 * @return string[]
	 */
	private static function get_seo_meta_keys( $seo_plugin ) {
		if ( 'rankmath' === $seo_plugin ) {
			return RankMath::get_editor_meta_keys();
		}

		if ( 'yoast' === $seo_plugin ) {
			return array_keys( Yoast::get_meta_map() );
		}

		return array();
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $before  Snapshot.
	 * @return array|\WP_Error
	 */
	private static function restore_seo( $post_id, $before ) {
		$seo_plugin = $before['seo_plugin'] ?? Core::detect_seo_plugin();

		if ( 'none' === $seo_plugin ) {
			return new \WP_Error( 'no_seo_plugin', __( 'No compatible SEO plugin detected.', 'ai-seo-filler' ) );
		}

		$meta = isset( $before['meta'] ) && is_array( $before['meta'] ) ? $before['meta'] : array();

		foreach ( $meta as $meta_key => $value ) {
			$meta_key = sanitize_key( $meta_key );

			if ( '' === $meta_key ) {
				continue;
			}

			$value = is_string( $value ) ? $value : (string) $value;

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
			}
		}

		if ( 'rankmath' === $seo_plugin ) {
			delete_post_meta( $post_id, 'rank_math_seo_score' );
		}

		$post_updates = array(
			'ID'           => $post_id,
			'post_name'    => sanitize_title( $before['post_name'] ?? '' ),
			'post_content' => isset( $before['post_content'] ) ? $before['post_content'] : '',
			'post_excerpt' => isset( $before['post_excerpt'] ) ? $before['post_excerpt'] : '',
		);

		$updated = wp_update_post( wp_slash( $post_updates ), true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( ! empty( $before['image_alts'] ) && is_array( $before['image_alts'] ) ) {
			foreach ( $before['image_alts'] as $attachment_id => $alt_text ) {
				$attachment_id = absint( $attachment_id );

				if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
					continue;
				}

				$alt_text = sanitize_text_field( (string) $alt_text );

				if ( '' === $alt_text ) {
					delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
				} else {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
				}
			}
		}

		clean_post_cache( $post_id );

		$seo_data    = self::snapshot_to_seo_data( $before );
		$editor_meta = array();

		if ( 'rankmath' === $seo_plugin ) {
			$editor_meta = RankMath::build_editor_meta( $seo_data );
		} elseif ( 'yoast' === $seo_plugin ) {
			$editor_meta = Yoast::build_editor_meta( $seo_data );
		}

		// Include empty keys so the editor can clear fields that were reverted.
		foreach ( self::get_seo_meta_keys( $seo_plugin ) as $meta_key ) {
			if ( ! array_key_exists( $meta_key, $editor_meta ) ) {
				$editor_meta[ $meta_key ] = isset( $meta[ $meta_key ] ) ? (string) $meta[ $meta_key ] : '';
			}
		}

		return array(
			'type'       => 'seo',
			'data'       => $seo_data,
			'editorMeta' => $editor_meta,
			'seoPlugin'  => $seo_plugin,
			'checklist'  => SEO_Checker::build_checklist( $seo_data, $post_id ),
			'can_undo'   => false,
		);
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $before  Snapshot.
	 * @param array $entry   History entry (includes new featured/gallery).
	 * @return array|\WP_Error
	 */
	private static function restore_images( $post_id, $before, $entry ) {
		$is_product  = ! empty( $before['is_product'] );
		$featured_id = absint( $before['featured_id'] ?? 0 );
		$gallery_ids = array_values( array_filter( array_map( 'absint', (array) ( $before['gallery_ids'] ?? array() ) ) ) );

		$applied_ids = array_merge(
			array( absint( $entry['featured'] ?? 0 ) ),
			array_map( 'absint', (array) ( $entry['gallery'] ?? array() ) )
		);
		$applied_ids = array_values( array_filter( $applied_ids ) );
		$keep_ids    = array_merge( array( $featured_id ), $gallery_ids );
		$trash_ids   = array_values( array_diff( $applied_ids, $keep_ids ) );

		if ( $is_product ) {
			$assigned = WooCommerce::assign_product_images( $post_id, $featured_id, $gallery_ids );

			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		} else {
			if ( $featured_id ) {
				set_post_thumbnail( $post_id, $featured_id );
				update_post_meta( $post_id, '_thumbnail_id', (string) $featured_id );
			} else {
				delete_post_thumbnail( $post_id );
				delete_post_meta( $post_id, '_thumbnail_id' );
			}
		}

		foreach ( $trash_ids as $attachment_id ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				wp_trash_post( $attachment_id );
			}
		}

		clean_post_cache( $post_id );

		$summary_ids = array_values( array_filter( array_merge( array( $featured_id ), $gallery_ids ) ) );

		return array(
			'type'        => 'images',
			'featured_id' => $featured_id,
			'gallery_ids' => $gallery_ids,
			'images'      => AI_Images::format_images_for_response( $summary_ids ),
			'is_product'  => $is_product,
			'editor'      => AI_Images::get_editor_sync_payload( $featured_id, $gallery_ids ),
			'can_undo'    => false,
		);
	}

	/**
	 * Converts a SEO before-snapshot into the internal SEO data shape.
	 *
	 * @param array $before Snapshot.
	 * @return array
	 */
	private static function snapshot_to_seo_data( $before ) {
		$seo_plugin = $before['seo_plugin'] ?? Core::detect_seo_plugin();
		$meta       = isset( $before['meta'] ) && is_array( $before['meta'] ) ? $before['meta'] : array();
		$data       = array(
			'slug'              => $before['post_name'] ?? '',
			'optimized_content' => $before['post_content'] ?? '',
			'short_description' => $before['post_excerpt'] ?? '',
			'image_alts'        => isset( $before['image_alts'] ) && is_array( $before['image_alts'] ) ? $before['image_alts'] : array(),
		);

		$map = array();

		if ( 'rankmath' === $seo_plugin ) {
			$map = RankMath::get_meta_map();
		} elseif ( 'yoast' === $seo_plugin ) {
			$map = Yoast::get_meta_map();
		}

		foreach ( $map as $meta_key => $data_key ) {
			if ( ! isset( $data[ $data_key ] ) && isset( $meta[ $meta_key ] ) && '' !== $meta[ $meta_key ] ) {
				$data[ $data_key ] = $meta[ $meta_key ];
			}
		}

		return $data;
	}

	/**
	 * Marks the latest history entry as undone.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function mark_undone( $post_id ) {
		$history = self::get_for_post( $post_id );

		if ( empty( $history[0] ) || ! is_array( $history[0] ) ) {
			return;
		}

		$history[0]['undone']         = true;
		$history[0]['undone_at']      = time();
		$history[0]['undone_user_id'] = get_current_user_id();

		update_post_meta( $post_id, self::META_KEY, $history );
	}

	/**
	 * Queries flattened history entries across posts (newest first).
	 *
	 * @param array $args {
	 *     @type int    $per_page  Entries per page. Default 20.
	 *     @type int    $page      Page number (1-based). Default 1.
	 *     @type string $post_type Post type slug or empty for all.
	 *     @type string $action    apply_seo|apply_images|undone|empty for all.
	 *     @type string $search    Title search.
	 * }
	 * @return array{items: array<int, array>, total: int, pages: int, page: int, per_page: int}
	 */
	public static function query_entries( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'per_page'  => 20,
				'page'      => 1,
				'post_type' => '',
				'action'    => '',
				'search'    => '',
			)
		);

		$per_page  = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$page      = max( 1, absint( $args['page'] ) );
		$post_type = sanitize_key( $args['post_type'] );
		$action    = sanitize_key( $args['action'] );
		$search    = sanitize_text_field( $args['search'] );

		$query_args = array(
			'post_type'              => $post_type ? $post_type : Bulk::get_allowed_post_types(),
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'meta_key'               => self::META_KEY,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$post_ids = get_posts( $query_args );
		$entries  = array();

		foreach ( $post_ids as $post_id ) {
			$post_id  = absint( $post_id );
			$history  = self::get_for_post( $post_id );
			$can_undo = self::can_undo( $post_id );
			$post     = get_post( $post_id );

			if ( ! $post || empty( $history ) ) {
				continue;
			}

			foreach ( $history as $index => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$entry_action = self::normalize_action( $entry );

				if ( 'undone' === $action ) {
					if ( empty( $entry['undone'] ) ) {
						continue;
					}
				} elseif ( $action && $entry_action !== $action ) {
					continue;
				}

				$entries[] = array(
					'post_id'    => $post_id,
					'post_title' => get_the_title( $post ),
					'post_type'  => $post->post_type,
					'edit_link'  => get_edit_post_link( $post_id, 'raw' ),
					'permalink'  => get_permalink( $post_id ),
					'index'      => (int) $index,
					'can_undo'   => ( 0 === (int) $index && $can_undo ),
					'timestamp'  => (int) ( $entry['timestamp'] ?? 0 ),
					'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
					'provider'   => (string) ( $entry['provider'] ?? '' ),
					'model'      => (string) ( $entry['model'] ?? '' ),
					'action'     => $entry_action,
					'mode'       => (string) ( $entry['mode'] ?? '' ),
					'fields'     => isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? $entry['fields'] : array(),
					'undone'     => ! empty( $entry['undone'] ),
					'featured'   => isset( $entry['featured'] ) ? absint( $entry['featured'] ) : 0,
					'gallery'    => isset( $entry['gallery'] ) && is_array( $entry['gallery'] ) ? array_map( 'absint', $entry['gallery'] ) : array(),
				);
			}
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 );
			}
		);

		$total = count( $entries );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( $page, $pages );
		$slice = array_slice( $entries, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'    => $slice,
			'total'    => $total,
			'pages'    => $pages,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Human-readable action label for an entry.
	 *
	 * @param array|string $entry Entry array or action slug.
	 * @return string
	 */
	public static function get_action_label( $entry ) {
		$action = is_array( $entry ) ? self::normalize_action( $entry ) : sanitize_key( $entry );

		if ( is_array( $entry ) && ! empty( $entry['undone'] ) ) {
			return __( 'Undone', 'ai-seo-filler' );
		}

		switch ( $action ) {
			case 'apply_images':
				return __( 'Images', 'ai-seo-filler' );
			case 'apply_seo':
			default:
				if ( is_array( $entry ) && isset( $entry['mode'] ) && 'meta_only' === $entry['mode'] ) {
					return __( 'SEO (meta only)', 'ai-seo-filler' );
				}
				return __( 'SEO', 'ai-seo-filler' );
		}
	}

	/**
	 * @param array $entry History entry.
	 * @return string
	 */
	private static function normalize_action( $entry ) {
		if ( ! empty( $entry['action'] ) ) {
			return sanitize_key( $entry['action'] );
		}

		return 'apply_seo';
	}
}
