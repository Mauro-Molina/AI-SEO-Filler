<?php
/**
 * Writes generated SEO data to Rank Math meta fields.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Math SEO meta field writer.
 */
class RankMath {

	/**
	 * Rank Math post meta keys mapped to internal SEO data keys.
	 *
	 * @var array<string, string>
	 */
	private static $meta_map = array(
		'rank_math_title'                => 'meta_title',
		'rank_math_description'          => 'meta_description',
		'rank_math_focus_keyword'        => 'focus_keyword',
		'rank_math_facebook_title'       => 'og_title',
		'rank_math_facebook_description' => 'og_description',
		'rank_math_twitter_title'        => 'og_title',
		'rank_math_twitter_description' => 'og_description',
	);

	/**
	 * Registers hooks for Rank Math integration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_meta' ) );
	}

	/**
	 * Ensures Rank Math meta keys are available to the block editor REST API.
	 */
	public static function register_rest_meta() {
		$meta_keys = array_keys( self::$meta_map );

		foreach ( $meta_keys as $meta_key ) {
			if ( registered_meta_key_exists( 'post', $meta_key ) ) {
				continue;
			}

			register_meta(
				'post',
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/**
	 * Saves SEO data to Rank Math post meta fields.
	 *
	 * @param int   $post_id  Post or product ID.
	 * @param array $seo_data Structured SEO data from the AI provider.
	 */
	public function save_seo_data( $post_id, $seo_data ) {
		$post_id = $this->resolve_post_id( $post_id );

		foreach ( self::$meta_map as $meta_key => $data_key ) {
			if ( empty( $seo_data[ $data_key ] ) ) {
				continue;
			}

			$value = $seo_data[ $data_key ];

			if ( in_array( $meta_key, array( 'rank_math_description', 'rank_math_facebook_description', 'rank_math_twitter_description' ), true ) ) {
				$value = sanitize_textarea_field( $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
		}

		// Clear cached score so Rank Math recalculates on next editor load.
		delete_post_meta( $post_id, 'rank_math_seo_score' );

		$this->save_image_alts( $seo_data );
		clean_post_cache( $post_id );
	}

	/**
	 * Returns Rank Math meta keys for syncing with the block editor.
	 *
	 * @return string[]
	 */
	public static function get_editor_meta_keys() {
		return array_keys( self::$meta_map );
	}

	/**
	 * Builds a meta object for the block editor from SEO data.
	 *
	 * @param array $seo_data Structured SEO data.
	 * @return array<string, string>
	 */
	public static function build_editor_meta( $seo_data ) {
		$meta = array();

		foreach ( self::$meta_map as $meta_key => $data_key ) {
			if ( ! empty( $seo_data[ $data_key ] ) ) {
				$meta[ $meta_key ] = $seo_data[ $data_key ];
			}
		}

		return $meta;
	}

	/**
	 * Resolves revisions/autosaves to the parent post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function resolve_post_id( $post_id ) {
		$post_id = absint( $post_id );
		$parent  = wp_is_post_revision( $post_id );

		return $parent ? (int) $parent : $post_id;
	}

	/**
	 * Updates attachment alt text meta for each image in the SEO data.
	 *
	 * @param array $seo_data Structured SEO data.
	 */
	private function save_image_alts( $seo_data ) {
		if ( empty( $seo_data['image_alts'] ) || ! is_array( $seo_data['image_alts'] ) ) {
			return;
		}

		foreach ( $seo_data['image_alts'] as $attachment_id => $alt_text ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}
	}
}
