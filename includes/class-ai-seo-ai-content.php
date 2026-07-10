<?php
/**
 * Shared post content gathering and SEO prompt building for AI providers.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Utilities used by Gemini, Groq, and future AI providers.
 */
class AI_Content {

	/**
	 * Gathers all relevant post content for an AI prompt.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Structured content data.
	 */
	public static function gather_post_content( $post ) {
		$categories   = array();
		$tags         = array();
		$content_body = $post->post_content;
		$price        = '';
		$sku          = '';

		$product_data = WooCommerce::gather_product_data( $post );

		if ( null !== $product_data ) {
			$categories   = $product_data['categories'];
			$tags         = $product_data['tags'];
			$content_body = $product_data['content'];
			$price        = $product_data['price'];
			$sku          = $product_data['sku'];
		} else {
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		}

		$images = self::gather_images( $post );

		$excerpt = $post->post_excerpt;

		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $content_body ), 55, '…' );
		}

		$data = array(
			'post_id'    => $post->ID,
			'post_type'  => $post->post_type,
			'title'      => $post->post_title,
			'content'    => wp_strip_all_tags( $content_body ),
			'excerpt'    => wp_strip_all_tags( $excerpt ),
			'word_count' => self::count_words( $content_body ),
			'categories' => $categories,
			'tags'       => $tags,
			'images'     => $images,
			'language'   => Settings::get_content_language(),
			'site_name'  => get_bloginfo( 'name' ),
		);

		if ( ! empty( $price ) ) {
			$data['price'] = $price;
		}

		if ( ! empty( $sku ) ) {
			$data['sku'] = $sku;
		}

		if ( null !== $product_data ) {
			if ( ! empty( $product_data['product_attributes'] ) ) {
				$data['product_attributes'] = $product_data['product_attributes'];
			}
			if ( ! empty( $product_data['product_brand'] ) ) {
				$data['product_brand'] = $product_data['product_brand'];
			}
			if ( ! empty( $product_data['review_summary'] ) ) {
				$data['review_summary'] = $product_data['review_summary'];
			}
		}

		return $data;
	}

	/**
	 * Collects images associated with a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array List of images with ID, URL, and current alt text.
	 */
	public static function gather_images( $post ) {
		$images = array();

		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( $thumbnail_id ) {
			$images[] = array(
				'id'          => (int) $thumbnail_id,
				'url'         => wp_get_attachment_url( $thumbnail_id ),
				'alt'         => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
				'is_featured' => true,
			);
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
			$embedded_ids = array_unique( array_map( 'absint', $matches[1] ) );

			foreach ( $embedded_ids as $attachment_id ) {
				if ( $attachment_id === (int) $thumbnail_id ) {
					continue;
				}

				$images[] = array(
					'id'          => $attachment_id,
					'url'         => wp_get_attachment_url( $attachment_id ),
					'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
					'is_featured' => false,
				);
			}
		}

		$gallery_images = WooCommerce::gather_gallery_images( $post, $images );

		return array_merge( $images, $gallery_images );
	}

	/**
	 * Builds the SEO generation prompt sent to any AI provider.
	 *
	 * @param array $content_data Gathered post content.
	 * @param array $args         Optional generation args (mode, focus_keyword).
	 * @return string Plain-text prompt.
	 */
	public static function build_prompt( $content_data, $args = array() ) {
		if ( ! empty( $content_data['images'] ) ) {
			$image_lines = array();

			foreach ( $content_data['images'] as $image ) {
				$image_lines[] = sprintf(
					'- ID %d (featured: %s): current alt "%s"',
					$image['id'],
					$image['is_featured'] ? 'yes' : 'no',
					$image['alt']
				);
			}

			$images_description = implode( "\n", $image_lines );
		} else {
			$images_description = '(no images)';
		}

		$prompt  = "You are a WordPress SEO expert. Analyze the following content and generate optimized SEO fields.\n\n";
		$prompt .= "RESPONSE LANGUAGE: {$content_data['language']}\n";
		$prompt .= "WEBSITE: {$content_data['site_name']}\n";
		$prompt .= "CONTENT TYPE: {$content_data['post_type']}\n\n";
		$prompt .= "TITLE: {$content_data['title']}\n\n";
		$prompt .= "EXCERPT: {$content_data['excerpt']}\n\n";
		$content_excerpt = $content_data['content'];
		if ( function_exists( 'mb_substr' ) ) {
			$content_excerpt = mb_substr( $content_excerpt, 0, 4000 );
		} else {
			$content_excerpt = substr( $content_excerpt, 0, 4000 );
		}

		$prompt .= "CONTENT:\n{$content_excerpt}\n\n";

		if ( ! empty( $content_data['categories'] ) ) {
			$prompt .= 'CATEGORIES: ' . implode( ', ', $content_data['categories'] ) . "\n";
		}

		if ( ! empty( $content_data['tags'] ) ) {
			$prompt .= 'TAGS: ' . implode( ', ', $content_data['tags'] ) . "\n";
		}

		if ( ! empty( $content_data['price'] ) ) {
			$prompt .= "PRICE: {$content_data['price']}\n";
		}

		if ( ! empty( $content_data['sku'] ) ) {
			$prompt .= "SKU: {$content_data['sku']}\n";
		}

		$prompt .= "\nIMAGES:\n{$images_description}\n\n";

		if ( ! empty( $content_data['product_attributes'] ) ) {
			$prompt .= "PRODUCT ATTRIBUTES:\n" . $content_data['product_attributes'] . "\n\n";
		}

		if ( ! empty( $content_data['product_brand'] ) ) {
			$prompt .= "BRAND: {$content_data['product_brand']}\n";
		}

		if ( ! empty( $content_data['review_summary'] ) ) {
			$prompt .= "REVIEWS SUMMARY: {$content_data['review_summary']}\n";
		}

		$prompt .= self::build_rankmath_rules_section( $content_data );
		$prompt .= self::build_readability_rules_section();
		$prompt .= self::build_tone_section();

		$mode = $args['mode'] ?? 'full';
		if ( 'meta_only' === $mode || ! self::should_generate_body_content( $args ) ) {
			$prompt .= "\nMODE: meta_only — return empty strings for optimized_content and short_description.\n";
		} else {
			$prompt .= "\nNOTE: Post body is generated in a separate API step. Return empty strings for optimized_content and short_description.\n";
		}

		if ( ! empty( $args['focus_keyword'] ) ) {
			$prompt .= "\nUSE THIS EXACT FOCUS KEYWORD: {$args['focus_keyword']}\n";
		}

		$prompt .= "\nRespond ONLY with a valid JSON object (no markdown, no explanations) with this exact structure:\n";
		$prompt .= "{\n";
		$prompt .= '  "focus_keyword": "primary focus keyword (choose this FIRST — all other fields depend on it)",' . "\n";
		$prompt .= '  "keyword_alternatives": ["alt keyword 1", "alt keyword 2"],' . "\n";
		$prompt .= '  "meta_title": "SEO title starting with focus_keyword (max 60 characters)",' . "\n";
		$prompt .= '  "meta_description": "meta description with focus_keyword in the first sentence (max 160 characters)",' . "\n";
		$prompt .= '  "slug": "url-slug-containing-focus-keyword-words",' . "\n";
		$prompt .= '  "og_title": "Open Graph title including focus_keyword",' . "\n";
		$prompt .= '  "og_description": "Open Graph description including focus_keyword",' . "\n";
		$prompt .= '  "optimized_content": "",' . "\n";
		$prompt .= '  "short_description": "",' . "\n";
		$prompt .= '  "image_alts": [' . "\n";
		$prompt .= '    {"id": 123, "alt": "alt text including focus_keyword when relevant"}' . "\n";
		$prompt .= "  ]\n";
		$prompt .= "}\n\n";
		$prompt .= 'Provide exactly 2 keyword_alternatives. In "image_alts", use one object per image with "id" (numeric) and "alt" (string).';

		return $prompt;
	}

	/**
	 * Whether the request should generate post body content (separate from meta).
	 *
	 * @param array $args Generation args.
	 * @return bool
	 */
	public static function should_generate_body_content( $args = array() ) {
		if ( ( $args['mode'] ?? 'full' ) === 'meta_only' ) {
			return false;
		}

		return Settings::should_generate_content();
	}

	/**
	 * Builds the prompt for the dedicated body-content API call.
	 *
	 * @param array $content_data Source post data.
	 * @param array $seo_data     Parsed meta SEO data.
	 * @param int   $post_id      Post ID.
	 * @param bool  $retry        Whether this is a retry with stricter instructions.
	 * @return string
	 */
	public static function build_content_prompt( $content_data, $seo_data, $post_id, $retry = false ) {
		$keyword = $seo_data['focus_keyword'] ?? '';
		$min     = Settings::get_min_word_count( $post_id );

		$prompt  = "You are a WordPress SEO content writer. Write the full post/product body in HTML.\n\n";
		$prompt .= "RESPONSE LANGUAGE: {$content_data['language']}\n";
		$prompt .= "FOCUS KEYWORD (mandatory): {$keyword}\n";
		$prompt .= "MINIMUM WORD COUNT: {$min} words\n\n";
		$prompt .= "TITLE: {$content_data['title']}\n\n";

		$source = $content_data['content'];
		if ( function_exists( 'mb_substr' ) ) {
			$source = mb_substr( $source, 0, 4000 );
		} else {
			$source = substr( $source, 0, 4000 );
		}
		$prompt .= "SOURCE CONTENT:\n{$source}\n\n";

		$prompt .= "RULES:\n";
		$prompt .= "- Return ONLY raw HTML (no JSON, no markdown fences).\n";
		$prompt .= "- Use <p> for paragraphs and <h2>/<h3> for subheadings.\n";
		$prompt .= "- First sentence MUST start with the focus keyword.\n";
		$prompt .= "- Include the focus keyword at least 3 more times naturally.\n";
		$prompt .= "- Write at least {$min} words. Expand with features, benefits, FAQs.\n";
		$prompt .= self::build_tone_section();

		if ( $retry ) {
			$prompt .= "\nIMPORTANT: Your previous response was too short. Write a LONGER article of at least {$min} words.\n";
		}

		return $prompt;
	}

	/**
	 * Sanitizes HTML body content from a plain-text AI response.
	 *
	 * @param string $raw Raw AI output.
	 * @return string
	 */
	public static function sanitize_body_content( $raw ) {
		$html = trim( (string) $raw );
		$html = preg_replace( '/^```(?:html)?\s*/im', '', $html );
		$html = preg_replace( '/\s*```\s*$/im', '', $html );
		$html = trim( $html );

		if ( '' === $html ) {
			return '';
		}

		if ( $html === wp_strip_all_tags( $html ) ) {
			$paragraphs = preg_split( '/\n\s*\n/', $html );
			$parts      = array();
			foreach ( $paragraphs as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$parts[] = '<p>' . esc_html( $para ) . '</p>';
				}
			}
			$html = implode( "\n", $parts );
		}

		return wp_kses_post( $html );
	}

	/**
	 * Builds a short description excerpt from HTML content.
	 *
	 * @param string $html    HTML content.
	 * @param string $keyword Focus keyword.
	 * @return string
	 */
	public static function build_short_description_from_content( $html, $keyword = '' ) {
		$plain = wp_strip_all_tags( $html );
		$short = wp_trim_words( $plain, 40, '…' );

		if ( $keyword && ! self::text_contains_keyword( $short, $keyword ) ) {
			$short = $keyword . '. ' . $short;
		}

		if ( function_exists( 'mb_substr' ) && mb_strlen( $short ) > 300 ) {
			return mb_substr( $short, 0, 297 ) . '…';
		}

		if ( strlen( $short ) > 300 ) {
			return substr( $short, 0, 297 ) . '…';
		}

		return $short;
	}

	/**
	 * Fetches body content via callback and merges into SEO data.
	 *
	 * @param int      $post_id      Post ID.
	 * @param array    $seo_data     Parsed meta SEO data.
	 * @param array    $content_data Source post data.
	 * @param array    $args         Generation args.
	 * @param callable $fetcher      function( string $prompt ): string|\WP_Error
	 * @return array|\WP_Error
	 */
	public static function complete_with_body_content( $post_id, $seo_data, $content_data, $args, $fetcher ) {
		if ( ! self::should_generate_body_content( $args ) ) {
			unset( $seo_data['optimized_content'], $seo_data['short_description'] );
			return $seo_data;
		}

		$min       = Settings::get_min_word_count( $post_id );
		$last_html = '';

		for ( $i = 0; $i < 2; $i++ ) {
			$prompt = self::build_content_prompt( $content_data, $seo_data, $post_id, $i > 0 );
			$raw    = call_user_func( $fetcher, $prompt );

			if ( is_wp_error( $raw ) ) {
				return $raw;
			}

			$last_html = self::sanitize_body_content( $raw );
			$count     = self::count_words( $last_html );

			if ( $count >= $min ) {
				$seo_data['optimized_content'] = $last_html;
				$seo_data['_word_count']       = $count;

				if ( Settings::should_generate_short_desc() ) {
					$seo_data['short_description'] = self::build_short_description_from_content(
						$last_html,
						$seo_data['focus_keyword'] ?? ''
					);
				}

				return self::enforce_rankmath_rules( $seo_data );
			}

			Logger::warning( 'Body content too short, retrying', array( 'words' => $count, 'min' => $min ) );
		}

		return new \WP_Error(
			'word_count_low',
			sprintf(
				/* translators: 1: actual word count, 2: required minimum */
				__( 'Generated content has only %1$d words (minimum %2$d). Try again or use Meta only mode.', 'ai-seo-filler' ),
				self::count_words( $last_html ),
				$min
			)
		);
	}

	/**
	 * Minimum word count required by Rank Math content-length test.
	 *
	 * @var int
	 */
	const RANKMATH_MIN_WORD_COUNT = 600;

	/**
	 * Builds the Rank Math optimization rules block for the AI prompt.
	 *
	 * @param array $content_data Gathered post content.
	 * @return string
	 */
	public static function build_rankmath_rules_section( $content_data ) {
		$rules  = "RANK MATH SEO SCORING RULES (MANDATORY — follow ALL strictly to maximize score):\n\n";
		$rules .= "1. Choose focus_keyword FIRST. Every other field MUST be built around that exact phrase.\n";
		$rules .= "2. meta_title MUST include the exact focus_keyword, preferably at the very beginning. Max 60 characters.\n";
		$rules .= "3. meta_description MUST include the exact focus_keyword naturally in the first sentence. Max 160 characters.\n";
		$rules .= "4. slug MUST contain the significant words from focus_keyword as lowercase hyphenated segments.\n";
		$rules .= "5. optimized_content and short_description are generated in a separate step — leave both empty in this JSON.\n";
		$rules .= "6. og_title and og_description MUST also include the focus_keyword.\n";
		$rules .= "7. Each image alt in image_alts SHOULD include the focus_keyword when it reads naturally.\n";

		return $rules;
	}

	/**
	 * Additional readability rules for Rank Math.
	 *
	 * @return string
	 */
	public static function build_readability_rules_section() {
		return "TITLE & CONTENT READABILITY RULES:\n"
			. "- SEO title: no filler words at the start; keep under 60 chars.\n"
			. "- Use short paragraphs (max 150 words each).\n"
			. "- Prefer active voice and clear, direct sentences.\n"
			. "- Distribute focus keyword naturally; avoid keyword stuffing.\n\n";
	}

	/**
	 * Content tone instruction block.
	 *
	 * @return string
	 */
	public static function build_tone_section() {
		$tone = Settings::get_content_tone();
		$map  = array(
			'commercial' => 'Write in a persuasive, benefit-driven commercial tone.',
			'technical'  => 'Write in a precise, technical tone with specifications.',
			'neutral'    => 'Write in a neutral, informative tone.',
		);

		return "CONTENT TONE: " . ( $map[ $tone ] ?? $map['neutral'] ) . "\n\n";
	}

	/**
	 * Counts words in a text string.
	 *
	 * @param string $text Plain or HTML text.
	 * @return int
	 */
	public static function count_words( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( '' === $text ) {
			return 0;
		}

		// preg_split — mb_split no interpreta \s correctamente y devuelve 1 palabra para todo el texto.
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Post-processes SEO data to enforce Rank Math scoring requirements.
	 *
	 * @param array $seo_data Parsed SEO fields.
	 * @return array
	 */
	public static function enforce_rankmath_rules( $seo_data ) {
		$keyword = trim( $seo_data['focus_keyword'] ?? '' );

		if ( '' === $keyword ) {
			return $seo_data;
		}

		if ( ! self::contains_keyword( $seo_data['meta_title'] ?? '', $keyword ) ) {
			$seo_data['meta_title'] = self::trim_to_length( $keyword . ' | ' . ( $seo_data['meta_title'] ?? '' ), 60 );
		} elseif ( 0 !== stripos( $seo_data['meta_title'], $keyword ) ) {
			$remainder = trim( preg_replace( '/\b' . preg_quote( $keyword, '/' ) . '\b/ui', '', $seo_data['meta_title'] ) );
			$remainder = trim( preg_replace( '/^[|\-–—:\s]+|[|\-–—:\s]+$/u', '', $remainder ) );
			$seo_data['meta_title'] = self::trim_to_length( $keyword . ( $remainder ? ' | ' . $remainder : '' ), 60 );
		}

		if ( ! self::contains_keyword( $seo_data['meta_description'] ?? '', $keyword ) ) {
			$seo_data['meta_description'] = self::trim_to_length( $keyword . '. ' . ( $seo_data['meta_description'] ?? '' ), 160 );
		}

		$keyword_slug = sanitize_title( $keyword );
		$current_slug = $seo_data['slug'] ?? '';

		if ( $keyword_slug && ! self::slug_contains_keyword( $current_slug, $keyword_slug ) ) {
			$seo_data['slug'] = $keyword_slug;
		}

		if ( ! self::contains_keyword( $seo_data['og_title'] ?? '', $keyword ) ) {
			$seo_data['og_title'] = self::trim_to_length( $keyword . ' — ' . ( $seo_data['og_title'] ?? '' ), 70 );
		}

		if ( ! self::contains_keyword( $seo_data['og_description'] ?? '', $keyword ) ) {
			$seo_data['og_description'] = self::trim_to_length( $keyword . '. ' . ( $seo_data['og_description'] ?? '' ), 200 );
		}

		if ( ! empty( $seo_data['optimized_content'] ) && ! self::contains_keyword( $seo_data['optimized_content'], $keyword ) ) {
			$seo_data['optimized_content'] = '<p>' . esc_html( $keyword ) . '.</p>' . $seo_data['optimized_content'];
		}

		return $seo_data;
	}

	/**
	 * Checks whether a text contains the focus keyword (case-insensitive).
	 *
	 * @param string $text    Haystack text.
	 * @param string $keyword Focus keyword.
	 * @return bool
	 */
	private static function contains_keyword( $text, $keyword ) {
		if ( '' === trim( (string) $text ) || '' === trim( $keyword ) ) {
			return false;
		}

		return false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( (string) $text, $keyword ) : stripos( (string) $text, $keyword ) );
	}

	/**
	 * Checks whether a slug contains the keyword slug segments.
	 *
	 * @param string $slug         Post slug.
	 * @param string $keyword_slug Sanitized keyword slug.
	 * @return bool
	 */
	private static function slug_contains_keyword( $slug, $keyword_slug ) {
		if ( '' === $slug || '' === $keyword_slug ) {
			return false;
		}

		return false !== strpos( $slug, $keyword_slug );
	}

	/**
	 * Trims a string to a maximum character length without breaking multibyte chars.
	 *
	 * @param string $text   Input text.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function trim_to_length( $text, $length ) {
		$text = trim( (string) $text );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
		}

		if ( strlen( $text ) > $length ) {
			return rtrim( substr( $text, 0, $length - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * Public wrapper for keyword containment check.
	 *
	 * @param string $text    Haystack.
	 * @param string $keyword Focus keyword.
	 * @return bool
	 */
	public static function text_contains_keyword( $text, $keyword ) {
		return self::contains_keyword( $text, $keyword );
	}

	/**
	 * Parses and validates a JSON SEO response from any AI provider.
	 *
	 * @param string $raw_text Raw JSON text from the AI.
	 * @param int    $post_id  Optional post ID for context.
	 * @return array|\WP_Error Structured SEO fields.
	 */
	public static function parse_seo_response( $raw_text, $post_id = 0 ) {
		$json_string = self::extract_json_string( $raw_text );

		if ( '' === $json_string ) {
			return new \WP_Error(
				'ai_parse_error',
				__( 'Could not parse AI JSON response: no JSON object found in the response.', 'ai-seo-filler' )
			);
		}

		$data = json_decode( $json_string, true );

		if ( ! is_array( $data ) ) {
			$json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '';

			return new \WP_Error(
				'ai_parse_error',
				sprintf(
					/* translators: %s: JSON parser error message */
					__( 'Could not parse AI JSON response: %s', 'ai-seo-filler' ),
					$json_error ? $json_error : __( 'invalid JSON syntax', 'ai-seo-filler' )
				)
			);
		}

		$image_alts = self::normalize_image_alts( $data );

		$alternatives = array();
		if ( ! empty( $data['keyword_alternatives'] ) && is_array( $data['keyword_alternatives'] ) ) {
			foreach ( array_slice( $data['keyword_alternatives'], 0, 2 ) as $alt ) {
				$alt = sanitize_text_field( $alt );
				if ( $alt ) {
					$alternatives[] = $alt;
				}
			}
		}

		$seo_data = array(
			'meta_title'           => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description'     => isset( $data['meta_description'] ) ? sanitize_textarea_field( $data['meta_description'] ) : '',
			'focus_keyword'        => isset( $data['focus_keyword'] ) ? sanitize_text_field( $data['focus_keyword'] ) : '',
			'keyword_alternatives' => $alternatives,
			'slug'                 => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'og_title'             => isset( $data['og_title'] ) ? sanitize_text_field( $data['og_title'] ) : '',
			'og_description'       => isset( $data['og_description'] ) ? sanitize_textarea_field( $data['og_description'] ) : '',
			'short_description'    => isset( $data['short_description'] ) ? sanitize_textarea_field( $data['short_description'] ) : '',
			'optimized_content'    => isset( $data['optimized_content'] ) ? wp_kses_post( $data['optimized_content'] ) : '',
			'image_alts'           => $image_alts,
		);

		$seo_plugin = Core::detect_seo_plugin();
		if ( 'yoast' === $seo_plugin ) {
			$seo_data = self::enforce_yoast_rules( $seo_data );
		} else {
			$seo_data = self::enforce_rankmath_rules( $seo_data );
		}

		return $seo_data;
	}

	/**
	 * Yoast-specific post-processing (mirrors Rank Math enforcement).
	 *
	 * @param array $seo_data Parsed data.
	 * @return array
	 */
	public static function enforce_yoast_rules( $seo_data ) {
		return self::enforce_rankmath_rules( $seo_data );
	}

	/**
	 * Extracts a JSON object string from AI output that may include markdown or extra text.
	 *
	 * @param string $raw_text Raw AI output.
	 * @return string JSON object string or empty string.
	 */
	public static function extract_json_string( $raw_text ) {
		$text = trim( (string) $raw_text );

		if ( '' === $text ) {
			return '';
		}

		// Remove UTF-8 BOM.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		// Strip markdown code fences (including multiline).
		$text = preg_replace( '/^```(?:json)?\s*/im', '', $text );
		$text = preg_replace( '/\s*```\s*$/im', '', $text );
		$text = trim( $text );

		// If the model double-encoded JSON, decode once.
		if ( '"' === substr( $text, 0, 1 ) && '"' === substr( $text, -1 ) ) {
			$decoded = json_decode( $text, true );
			if ( is_string( $decoded ) ) {
				$text = trim( $decoded );
			}
		}

		// Direct parse when the full string is JSON.
		if ( '{' === substr( $text, 0, 1 ) ) {
			$direct = self::normalize_json_string( $text );
			if ( null !== json_decode( $direct, true ) ) {
				return $direct;
			}

			$balanced = self::extract_balanced_json_object( $text );
			if ( '' !== $balanced ) {
				return $balanced;
			}
		}

		// Find the first JSON object embedded in surrounding text.
		$start = strpos( $text, '{' );
		if ( false !== $start ) {
			$embedded = self::extract_balanced_json_object( substr( $text, $start ) );
			if ( '' !== $embedded ) {
				return $embedded;
			}
		}

		return '';
	}

	/**
	 * Extracts a balanced {...} JSON object from a string starting with "{".
	 *
	 * @param string $text Text that begins with an opening brace.
	 * @return string Extracted JSON or empty string.
	 */
	private static function extract_balanced_json_object( $text ) {
		$length  = strlen( $text );
		$depth   = 0;
		$in_str  = false;
		$escape  = false;
		$started = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $in_str ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_str = true;
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
				$started = true;
				continue;
			}

			if ( '}' === $char && $started ) {
				$depth--;
				if ( 0 === $depth ) {
					return self::normalize_json_string( substr( $text, 0, $i + 1 ) );
				}
			}
		}

		// Truncated JSON — try normalizing anyway (may still parse partial fields).
		if ( $started && $depth > 0 ) {
			$padded = self::normalize_json_string( $text . str_repeat( '}', $depth ) );
			if ( null !== json_decode( $padded, true ) ) {
				return $padded;
			}
		}

		return '';
	}

	/**
	 * Normalizes common JSON syntax issues from LLM output.
	 *
	 * @param string $json JSON string.
	 * @return string Normalized JSON string.
	 */
	private static function normalize_json_string( $json ) {
		// Remove trailing commas before } or ].
		$json = preg_replace( '/,\s*([}\]])/', '$1', $json );

		return trim( $json );
	}

	/**
	 * Normalizes image alt data from object or array AI response formats.
	 *
	 * @param array $data Decoded SEO JSON.
	 * @return array<int, string> Map of attachment ID => alt text.
	 */
	private static function normalize_image_alts( $data ) {
		$image_alts = array();

		if ( empty( $data['image_alts'] ) || ! is_array( $data['image_alts'] ) ) {
			return $image_alts;
		}

		// Array format: [{"id": 123, "alt": "text"}, ...]
		if ( isset( $data['image_alts'][0] ) && is_array( $data['image_alts'][0] ) ) {
			foreach ( $data['image_alts'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) {
					continue;
				}
				$image_alts[ absint( $item['id'] ) ] = sanitize_text_field( $item['alt'] ?? '' );
			}

			return $image_alts;
		}

		// Object format: {"123": "text", ...}
		foreach ( $data['image_alts'] as $image_id => $alt_text ) {
			if ( is_array( $alt_text ) ) {
				continue;
			}
			$image_alts[ absint( $image_id ) ] = sanitize_text_field( $alt_text );
		}

		return $image_alts;
	}

	/**
	 * Returns the Gemini responseSchema for structured SEO JSON output.
	 *
	 * Uses OpenAPI 3.0 subset supported by responseSchema (no additionalProperties).
	 *
	 * @return array JSON schema for the Generative Language API.
	 */
	public static function get_seo_response_schema() {
		return self::get_seo_meta_schema();
	}

	/**
	 * JSON schema for meta-only responses (body content is a separate API call).
	 *
	 * @return array
	 */
	public static function get_seo_meta_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'meta_title'           => array( 'type' => 'string' ),
				'meta_description'     => array( 'type' => 'string' ),
				'focus_keyword'        => array( 'type' => 'string' ),
				'keyword_alternatives' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'slug'             => array( 'type' => 'string' ),
				'og_title'         => array( 'type' => 'string' ),
				'og_description'   => array( 'type' => 'string' ),
				'optimized_content' => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
				'image_alts'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'  => array( 'type' => 'integer' ),
							'alt' => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'alt' ),
					),
				),
			),
			'required'   => array(
				'meta_title',
				'meta_description',
				'focus_keyword',
				'keyword_alternatives',
				'slug',
				'og_title',
				'og_description',
				'image_alts',
			),
		);
	}

	/**
	 * Validates a post ID and returns the post object.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	public static function get_valid_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new \WP_Error(
				'invalid_post_id',
				__( 'Invalid post ID.', 'ai-seo-filler' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'ai-seo-filler' )
			);
		}

		return $post;
	}
}
