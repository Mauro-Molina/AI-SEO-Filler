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
			'language'   => get_option( AI_SEO_FILLER_OPTION_PREFIX . 'language', get_locale() ),
			'site_name'  => get_bloginfo( 'name' ),
		);

		if ( ! empty( $price ) ) {
			$data['price'] = $price;
		}

		if ( ! empty( $sku ) ) {
			$data['sku'] = $sku;
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
	 * @return string Plain-text prompt.
	 */
	public static function build_prompt( $content_data ) {
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
		$prompt .= self::build_rankmath_rules_section( $content_data );
		$prompt .= "\nRespond ONLY with a valid JSON object (no markdown, no explanations) with this exact structure:\n";
		$prompt .= "{\n";
		$prompt .= '  "focus_keyword": "primary focus keyword (choose this FIRST — all other fields depend on it)",' . "\n";
		$prompt .= '  "meta_title": "SEO title starting with focus_keyword (max 60 characters)",' . "\n";
		$prompt .= '  "meta_description": "meta description with focus_keyword in the first sentence (max 160 characters)",' . "\n";
		$prompt .= '  "slug": "url-slug-containing-focus-keyword-words",' . "\n";
		$prompt .= '  "og_title": "Open Graph title including focus_keyword",' . "\n";
		$prompt .= '  "og_description": "Open Graph description including focus_keyword",' . "\n";
		$prompt .= '  "optimized_content": "Full rewritten HTML body: use <p> tags, min 600 words, focus_keyword in first sentence and 3+ times in body",' . "\n";
		$prompt .= '  "image_alts": [' . "\n";
		$prompt .= '    {"id": 123, "alt": "alt text including focus_keyword when relevant"}' . "\n";
		$prompt .= "  ]\n";
		$prompt .= "}\n\n";
		$prompt .= 'In "image_alts", use one object per image with "id" (numeric) and "alt" (string).';

		return $prompt;
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
		$word_count = isset( $content_data['word_count'] ) ? (int) $content_data['word_count'] : 0;
		$minimum    = self::RANKMATH_MIN_WORD_COUNT;

		$rules  = "RANK MATH SEO SCORING RULES (MANDATORY — follow ALL strictly to maximize score):\n\n";
		$rules .= "1. Choose focus_keyword FIRST. Every other field MUST be built around that exact phrase.\n";
		$rules .= "2. meta_title MUST include the exact focus_keyword, preferably at the very beginning. Max 60 characters.\n";
		$rules .= "3. meta_description MUST include the exact focus_keyword naturally in the first sentence. Max 160 characters.\n";
		$rules .= "4. slug MUST contain the significant words from focus_keyword as lowercase hyphenated segments.\n";
		$rules .= "5. optimized_content MUST:\n";
		$rules .= "   - Start with the focus_keyword in the very first sentence (first 1–3 words ideal).\n";
		$rules .= "   - Include the focus_keyword at least 3 more times naturally throughout the body.\n";
		$rules .= "   - Be at least {$minimum} words (current content is only {$word_count} words — expand with relevant, factual detail).\n";
		$rules .= "   - Use semantic HTML: wrap each paragraph in <p>...</p> tags only (no markdown).\n";
		$rules .= "   - Preserve accuracy; expand with features, benefits, use cases, and FAQs based on the source content.\n";
		$rules .= "6. og_title and og_description MUST also include the focus_keyword.\n";
		$rules .= "7. Each image alt in image_alts SHOULD include the focus_keyword when it reads naturally.\n";

		return $rules;
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

		if ( function_exists( 'mb_split' ) ) {
			$words = mb_split( '/\s+/u', $text );
			return is_array( $words ) ? count( array_filter( $words ) ) : 0;
		}

		return str_word_count( $text );
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
	 * Parses and validates a JSON SEO response from any AI provider.
	 *
	 * @param string $raw_text Raw JSON text from the AI.
	 * @return array|\WP_Error Structured SEO fields.
	 */
	public static function parse_seo_response( $raw_text ) {
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

		$seo_data = array(
			'meta_title'        => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description'  => isset( $data['meta_description'] ) ? sanitize_textarea_field( $data['meta_description'] ) : '',
			'focus_keyword'     => isset( $data['focus_keyword'] ) ? sanitize_text_field( $data['focus_keyword'] ) : '',
			'slug'              => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'og_title'          => isset( $data['og_title'] ) ? sanitize_text_field( $data['og_title'] ) : '',
			'og_description'    => isset( $data['og_description'] ) ? sanitize_textarea_field( $data['og_description'] ) : '',
			'optimized_content' => isset( $data['optimized_content'] ) ? wp_kses_post( $data['optimized_content'] ) : '',
			'image_alts'        => $image_alts,
		);

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
		return array(
			'type'       => 'object',
			'properties' => array(
				'meta_title'       => array( 'type' => 'string' ),
				'meta_description' => array( 'type' => 'string' ),
				'focus_keyword'    => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string' ),
				'og_title'          => array( 'type' => 'string' ),
				'og_description'    => array( 'type' => 'string' ),
				'optimized_content' => array( 'type' => 'string' ),
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
				'slug',
				'og_title',
				'og_description',
				'optimized_content',
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
