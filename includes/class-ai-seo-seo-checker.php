<?php
/**
 * SEO field checks and Rank Math score checklist.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Validates SEO data and builds a Rank Math-style checklist.
 */
class SEO_Checker {

	/**
	 * Returns whether a post already has SEO meta filled.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_existing_seo( $post_id ) {
		$plugin = Core::detect_seo_plugin();

		if ( 'rankmath' === $plugin ) {
			$title = get_post_meta( $post_id, 'rank_math_title', true );
			$kw    = get_post_meta( $post_id, 'rank_math_focus_keyword', true );

			return ! empty( $title ) || ! empty( $kw );
		}

		if ( 'yoast' === $plugin ) {
			$title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$kw    = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );

			return ! empty( $title ) || ! empty( $kw );
		}

		return false;
	}

	/**
	 * Builds a fallback checklist (client-side Rank Math analyzer is preferred for score).
	 *
	 * @param array $seo_data Generated SEO data.
	 * @param int   $post_id  Post ID (for context).
	 * @return array<int, array{label:string, pass:bool}>
	 */
	public static function build_checklist( $seo_data, $post_id = 0 ) {
		$keyword    = trim( $seo_data['focus_keyword'] ?? '' );
		$min_words  = Settings::get_min_word_count( $post_id );
		$content    = $seo_data['optimized_content'] ?? '';
		$word_count = AI_Content::count_words( $content );
		$slug       = $seo_data['slug'] ?? '';
		$has_images = $post_id ? self::post_has_images( $post_id ) : ! empty( $seo_data['image_alts'] );

		$tests = array(
			array(
				'label' => __( 'Focus keyword in SEO title', 'ai-seo-filler' ),
				'pass'  => $keyword && AI_Content::text_contains_keyword( $seo_data['meta_title'] ?? '', $keyword ),
			),
			array(
				'label' => __( 'Focus keyword in meta description', 'ai-seo-filler' ),
				'pass'  => $keyword && AI_Content::text_contains_keyword( $seo_data['meta_description'] ?? '', $keyword ),
			),
			array(
				'label' => __( 'Focus keyword in URL slug', 'ai-seo-filler' ),
				'pass'  => $keyword && self::slug_has_keyword( $slug, $keyword ),
			),
			array(
				'label' => __( 'Focus keyword at beginning of content', 'ai-seo-filler' ),
				'pass'  => $keyword && self::keyword_at_content_start( $content, $keyword ),
			),
			array(
				'label' => __( 'Focus keyword used 3+ times in content', 'ai-seo-filler' ),
				'pass'  => $keyword && self::keyword_count_in_content( $content, $keyword ) >= 3,
			),
			array(
				/* translators: %d: minimum word count */
				'label' => sprintf( __( 'Content at least %d words', 'ai-seo-filler' ), $min_words ),
				'pass'  => $word_count >= $min_words,
			),
			array(
				'label' => __( 'Subheadings (H2/H3) in content', 'ai-seo-filler' ),
				'pass'  => (bool) preg_match( '/<h[23][^>]*>/i', $content ),
			),
		);

		if ( $has_images ) {
			$tests[] = array(
				'label' => __( 'Image alt texts provided', 'ai-seo-filler' ),
				'pass'  => self::image_alts_complete( $seo_data['image_alts'] ?? array(), $post_id ),
			);
		}

		$passed = count( array_filter( wp_list_pluck( $tests, 'pass' ) ) );

		return array(
			'tests'    => $tests,
			'score'    => count( $tests ) > 0 ? (int) round( ( $passed / count( $tests ) ) * 100 ) : 0,
			'passed'   => $passed,
			'total'    => count( $tests ),
			'words'    => $word_count,
			'estimate' => true,
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function post_has_images( $post_id ) {
		if ( get_post_thumbnail_id( $post_id ) ) {
			return true;
		}

		$post = get_post( $post_id );

		return $post && preg_match( '/wp-image-\d+/', $post->post_content );
	}

	/**
	 * @param array $image_alts Image alt map.
	 * @param int   $post_id    Post ID.
	 * @return bool
	 */
	private static function image_alts_complete( $image_alts, $post_id ) {
		if ( empty( $image_alts ) || ! is_array( $image_alts ) ) {
			return false;
		}

		foreach ( $image_alts as $alt ) {
			if ( is_array( $alt ) ) {
				$alt = $alt['alt'] ?? '';
			}
			if ( '' === trim( (string) $alt ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $slug    Post slug.
	 * @param string $keyword Focus keyword.
	 * @return bool
	 */
	private static function slug_has_keyword( $slug, $keyword ) {
		$keyword_slug = sanitize_title( $keyword );

		return $keyword_slug && false !== strpos( $slug, $keyword_slug );
	}

	/**
	 * @param string $content HTML content.
	 * @param string $keyword Focus keyword.
	 * @return bool
	 */
	private static function keyword_at_content_start( $content, $keyword ) {
		$plain = wp_strip_all_tags( $content );
		$plain = ltrim( $plain );

		return AI_Content::text_contains_keyword( mb_substr( $plain, 0, 120 ), $keyword );
	}

	/**
	 * @param string $content HTML content.
	 * @param string $keyword Focus keyword.
	 * @return int
	 */
	private static function keyword_count_in_content( $content, $keyword ) {
		$plain = mb_strtolower( wp_strip_all_tags( $content ) );
		$kw    = mb_strtolower( $keyword );

		if ( '' === $kw ) {
			return 0;
		}

		return substr_count( $plain, $kw );
	}
}
