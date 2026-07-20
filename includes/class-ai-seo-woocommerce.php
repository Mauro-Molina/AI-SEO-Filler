<?php
/**
 * Reads WooCommerce product data for SEO content gathering.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce product data reader.
 */
class WooCommerce {

	public static function is_product( $post ) {
		return 'product' === $post->post_type && function_exists( 'wc_get_product' );
	}

	/**
	 * @param \WP_Post $post Product post.
	 * @return array|null
	 */
	public static function gather_product_data( $post ) {
		if ( ! self::is_product( $post ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return null;
		}

		$categories = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$tags = wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}

		$brands = wp_get_post_terms( $post->ID, 'product_brand', array( 'fields' => 'names' ) );
		if ( is_wp_error( $brands ) ) {
			$brands = array();
		}

		$short_description = $product->get_short_description();
		$content_body      = $post->post_content;

		if ( ! empty( $short_description ) ) {
			$content_body = $short_description . "\n\n" . $content_body;
		}

		$attributes_lines = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $post->ID, $attribute->get_name(), array( 'fields' => 'names' ) );
				$attributes_lines[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', $terms );
			} else {
				$attributes_lines[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', $attribute->get_options() );
			}
		}

		$variation_summary = '';
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices();
			if ( ! empty( $prices['regular_price'] ) ) {
				$variation_summary = sprintf(
					'Price range: %s - %s',
					wc_price( min( $prices['regular_price'] ) ),
					wc_price( max( $prices['regular_price'] ) )
				);
			}
		}

		return array(
			'categories'         => $categories,
			'tags'               => $tags,
			'content'            => $content_body,
			'price'              => $product->get_price(),
			'sku'                => $product->get_sku(),
			'gallery_ids'        => $product->get_gallery_image_ids(),
			'product_brand'      => implode( ', ', $brands ),
			'product_attributes' => implode( "\n", $attributes_lines ) . ( $variation_summary ? "\n" . $variation_summary : '' ),
			'review_summary'     => self::get_review_summary( $product ),
		);
	}

	/**
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function get_review_summary( $product ) {
		$count   = $product->get_review_count();
		$average = $product->get_average_rating();

		if ( ! $count ) {
			return '';
		}

		return sprintf( '%s stars average from %d reviews', $average, $count );
	}

	/**
	 * @param \WP_Post $post Product post.
	 * @param array    $existing_images Known images.
	 * @return array
	 */
	public static function gather_gallery_images( $post, $existing_images = array() ) {
		$images    = array();
		$product   = self::gather_product_data( $post );
		$known_ids = wp_list_pluck( $existing_images, 'id' );

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

	/**
	 * Assigns featured image and product gallery.
	 *
	 * @param int   $post_id    Product ID.
	 * @param int   $featured_id Featured attachment ID.
	 * @param int[] $gallery_ids Gallery attachment IDs.
	 * @return true|\WP_Error
	 */
	public static function assign_product_images( $post_id, $featured_id, $gallery_ids ) {
		$post = get_post( $post_id );

		if ( ! $post || ! self::is_product( $post ) ) {
			return new \WP_Error( 'not_product', __( 'This post is not a WooCommerce product.', 'ai-seo-filler' ) );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return new \WP_Error( 'invalid_product', __( 'Could not load WooCommerce product.', 'ai-seo-filler' ) );
		}

		$featured_id = absint( $featured_id );
		$gallery_ids = array_values( array_filter( array_map( 'absint', (array) $gallery_ids ) ) );
		$gallery_ids = array_values( array_diff( $gallery_ids, array( $featured_id ) ) );

		if ( $featured_id ) {
			$product->set_image_id( $featured_id );
			set_post_thumbnail( $post_id, $featured_id );
		} else {
			$product->set_image_id( 0 );
			delete_post_thumbnail( $post_id );
		}

		$product->set_gallery_image_ids( $gallery_ids );
		$product->save();

		// Keep classic editor / legacy meta in sync so a later "Update" does not wipe images.
		update_post_meta( $post_id, '_thumbnail_id', $featured_id ? (string) $featured_id : '' );
		update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );

		clean_post_cache( $post_id );
		wc_delete_product_transients( $post_id );

		return true;
	}
}
