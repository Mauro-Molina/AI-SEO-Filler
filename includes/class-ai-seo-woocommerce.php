<?php
/**
 * Reads WooCommerce product data for SEO content gathering.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product data reader used by the Gemini content pipeline.
 */
class WooCommerce {

	/**
	 * Returns whether WooCommerce is active and the post is a product.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_product( $post ) {
		return 'product' === $post->post_type && function_exists( 'wc_get_product' );
	}

	/**
	 * Gathers WooCommerce-specific content for a product post.
	 *
	 * @param \WP_Post $post Product post object.
	 * @return array|null Product data array, or null when not a valid product.
	 */
	public static function gather_product_data( $post ) {
		if ( ! self::is_product( $post ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return null;
		}

		$categories = wp_get_post_terms(
			$post->ID,
			'product_cat',
			array( 'fields' => 'names' )
		);

		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$tags = wp_get_post_terms(
			$post->ID,
			'product_tag',
			array( 'fields' => 'names' )
		);

		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}

		$short_description = $product->get_short_description();
		$content_body    = $post->post_content;

		if ( ! empty( $short_description ) ) {
			$content_body = $short_description . "\n\n" . $content_body;
		}

		return array(
			'categories' => $categories,
			'tags'       => $tags,
			'content'    => $content_body,
			'price'      => $product->get_price(),
			'sku'        => $product->get_sku(),
			'gallery_ids' => $product->get_gallery_image_ids(),
		);
	}

	/**
	 * Gathers gallery images for a WooCommerce product.
	 *
	 * @param \WP_Post $post           Product post object.
	 * @param array    $existing_images Images already collected (to avoid duplicates).
	 * @return array Additional image entries.
	 */
	public static function gather_gallery_images( $post, $existing_images = array() ) {
		$images     = array();
		$product    = self::gather_product_data( $post );
		$known_ids  = wp_list_pluck( $existing_images, 'id' );

		if ( null === $product || empty( $product['gallery_ids'] ) ) {
			return $images;
		}

		foreach ( $product['gallery_ids'] as $gallery_id ) {
			if ( in_array( (int) $gallery_id, $known_ids, true ) ) {
				continue;
			}

			$images[] = array(
				'id'          => (int) $gallery_id,
				'url'         => wp_get_attachment_url( $gallery_id ),
				'alt'         => get_post_meta( $gallery_id, '_wp_attachment_image_alt', true ),
				'is_featured' => false,
			);
		}

		return $images;
	}
}
