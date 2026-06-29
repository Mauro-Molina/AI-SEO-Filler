<?php
/**
 * Writes generated SEO data to Yoast SEO meta fields.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO meta field writer.
 */
class Yoast {

	/**
	 * Yoast post meta keys mapped to internal SEO data keys.
	 *
	 * @var array<string, string>
	 */
	private static $meta_map = array(
		'_yoast_wpseo_title'                  => 'meta_title',
		'_yoast_wpseo_metadesc'               => 'meta_description',
		'_yoast_wpseo_focuskw'                => 'focus_keyword',
		'_yoast_wpseo_opengraph-title'        => 'og_title',
		'_yoast_wpseo_opengraph-description'  => 'og_description',
		'_yoast_wpseo_twitter-title'          => 'og_title',
		'_yoast_wpseo_twitter-description'    => 'og_description',
	);

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
	 * Saves SEO data to Yoast SEO post meta fields.
	 *
	 * @param int   $post_id  Post or product ID.
	 * @param array $seo_data Structured SEO data from Gemini.
	 */
	public function save_seo_data( $post_id, $seo_data ) {
		$post_id = absint( $post_id );

		if ( ! empty( $seo_data['meta_title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_data['meta_title'] );
		}

		if ( ! empty( $seo_data['meta_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_data['meta_description'] );
		}

		if ( ! empty( $seo_data['focus_keyword'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_data['focus_keyword'] );
		}

		if ( ! empty( $seo_data['og_title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $seo_data['og_title'] );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $seo_data['og_title'] );
		}

		if ( ! empty( $seo_data['og_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $seo_data['og_description'] );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-description', $seo_data['og_description'] );
		}

		$this->save_image_alts( $seo_data );
	}

	/**
	 * Updates attachment alt text meta for each image in the SEO data.
	 *
	 * @param array $seo_data Structured SEO data from Gemini.
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
