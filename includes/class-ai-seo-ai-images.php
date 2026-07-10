<?php
/**
 * AI product image generation: featured + gallery with SEO metadata.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Generates product images via AI and assigns them to WooCommerce products.
 */
class AI_Images {

	const GALLERY_COUNT = 3;

	/**
	 * Generates images, uploads them to the Media Library, and returns them for user selection.
	 * Does not assign featured/gallery until apply_selection() is called.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error Result summary with staged images.
	 */
	public static function generate_for_post( $post_id ) {
		if ( ! Settings::has_image_provider_configured() ) {
			return new \WP_Error(
				'no_image_provider',
				__( 'No image generation provider configured. Choose Flux (free) or add an OpenAI/Gemini API key in Settings.', 'ai-seo-filler' )
			);
		}

		$post = AI_Content::get_valid_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot upload images for this post.', 'ai-seo-filler' ) );
		}

		@set_time_limit( 300 );

		$context    = self::gather_image_context( $post );
		$is_product = WooCommerce::is_product( $post );
		$plan       = self::build_image_plan( $context, $is_product );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$provider      = Settings::get_image_provider();
		$used_provider = $provider;
		$created       = array();

		foreach ( $plan['images'] as $index => $image_plan ) {
			if ( $index > 0 && 'flux' === ( $used_provider ?: $provider ) ) {
				// Pollinations anonymous free tier is rate-limited; pause between images.
				sleep( 3 );
			}

			$binary_result = self::generate_image_binary_with_fallback( $image_plan['generation_prompt'], $provider );

			if ( is_wp_error( $binary_result ) ) {
				self::cleanup_attachments( $created );
				return $binary_result;
			}

			$binary        = $binary_result['binary'];
			$used_provider = $binary_result['provider'];

			$attachment_id = self::upload_attachment( $binary, $image_plan, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				self::cleanup_attachments( $created );
				return $attachment_id;
			}

			update_post_meta( $attachment_id, '_ai_seo_filler_staged', 1 );
			update_post_meta( $attachment_id, '_ai_seo_filler_suggested_role', sanitize_key( $image_plan['role'] ) );

			$created[] = $attachment_id;

			Logger::info(
				'AI image staged',
				array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
					'role'          => $image_plan['role'],
					'provider'      => $used_provider,
				)
			);
		}

		$staging_key = 'ai_seo_img_' . wp_generate_password( 12, false );
		set_transient(
			$staging_key,
			array(
				'post_id'    => (int) $post_id,
				'user_id'    => get_current_user_id(),
				'ids'        => array_map( 'intval', $created ),
				'provider'   => $used_provider,
				'is_product' => $is_product,
				'created'    => time(),
			),
			HOUR_IN_SECONDS
		);

		$images = self::format_images_summary( $created );
		$suggested_featured = 0;

		foreach ( $images as $image ) {
			if ( 'featured' === ( $image['suggested_role'] ?? '' ) ) {
				$suggested_featured = (int) $image['id'];
				break;
			}
		}

		if ( ! $suggested_featured && ! empty( $images[0]['id'] ) ) {
			$suggested_featured = (int) $images[0]['id'];
		}

		return array(
			'staging_key'        => $staging_key,
			'images'             => $images,
			'suggested_featured' => $suggested_featured,
			'provider'           => $used_provider,
			'is_product'         => $is_product,
			'count'              => count( $created ),
		);
	}

	/**
	 * Applies the user's featured/gallery selection to the post or product.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $staging_key Transient staging key.
	 * @param int    $featured_id Selected featured attachment ID.
	 * @param int[]  $gallery_ids Selected gallery attachment IDs.
	 * @return array|\WP_Error
	 */
	public static function apply_selection( $post_id, $staging_key, $featured_id, $gallery_ids = array() ) {
		$post_id     = absint( $post_id );
		$featured_id = absint( $featured_id );
		$gallery_ids = array_values( array_filter( array_map( 'absint', (array) $gallery_ids ) ) );

		$staged = get_transient( $staging_key );

		if ( ! is_array( $staged ) || (int) ( $staged['post_id'] ?? 0 ) !== $post_id ) {
			return new \WP_Error( 'invalid_staging', __( 'Image selection expired. Generate images again.', 'ai-seo-filler' ) );
		}

		if ( (int) ( $staged['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'forbidden', __( 'You cannot apply these images.', 'ai-seo-filler' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit this post.', 'ai-seo-filler' ) );
		}

		$allowed = array_map( 'intval', $staged['ids'] ?? array() );

		if ( ! $featured_id || ! in_array( $featured_id, $allowed, true ) ) {
			return new \WP_Error( 'invalid_featured', __( 'Select a valid featured image.', 'ai-seo-filler' ) );
		}

		$gallery_ids = array_values( array_diff( $gallery_ids, array( $featured_id ) ) );
		$gallery_ids = array_values( array_intersect( $gallery_ids, $allowed ) );

		$post       = get_post( $post_id );
		$is_product = $post ? WooCommerce::is_product( $post ) : ! empty( $staged['is_product'] );

		if ( $is_product && empty( $gallery_ids ) ) {
			$gallery_ids = array_values( array_diff( $allowed, array( $featured_id ) ) );
		}

		if ( $is_product ) {
			$assigned = WooCommerce::assign_product_images( $post_id, $featured_id, $gallery_ids );

			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		} else {
			set_post_thumbnail( $post_id, $featured_id );
			update_post_meta( $post_id, '_thumbnail_id', (string) $featured_id );
			$unused = array_values( array_diff( $allowed, array( $featured_id ) ) );
			self::cleanup_attachments( $unused );
			$gallery_ids = array();
		}

		foreach ( array_merge( array( $featured_id ), $gallery_ids ) as $attachment_id ) {
			delete_post_meta( $attachment_id, '_ai_seo_filler_staged' );
			delete_post_meta( $attachment_id, '_ai_seo_filler_suggested_role' );
		}

		delete_transient( $staging_key );

		History::record(
			$post_id,
			array(),
			array(
				'action'   => 'apply_images',
				'provider' => $staged['provider'] ?? '',
				'featured' => $featured_id,
				'gallery'  => $gallery_ids,
			)
		);

		/**
		 * Fires after AI images are assigned.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $result  Summary data.
		 */
		do_action(
			'ai_seo_filler_after_generate_images',
			$post_id,
			array(
				'featured_id' => $featured_id,
				'gallery_ids' => $gallery_ids,
				'provider'    => $staged['provider'] ?? '',
			)
		);

		$kept = array_merge( array( $featured_id ), $gallery_ids );

		return array(
			'featured_id' => $featured_id,
			'gallery_ids' => $gallery_ids,
			'images'      => self::format_images_summary( $kept ),
			'provider'    => $staged['provider'] ?? '',
			'is_product'  => $is_product,
			'editor'      => self::build_editor_sync_payload( $featured_id, $gallery_ids ),
		);
	}

	/**
	 * Payload used by the classic editor to refresh Product image / gallery metaboxes.
	 *
	 * @param int   $featured_id Featured attachment ID.
	 * @param int[] $gallery_ids Gallery attachment IDs.
	 * @return array<string, mixed>
	 */
	private static function build_editor_sync_payload( $featured_id, $gallery_ids ) {
		$featured_id = absint( $featured_id );
		$gallery_ids = array_values( array_filter( array_map( 'absint', (array) $gallery_ids ) ) );

		$featured_thumb = $featured_id ? wp_get_attachment_image_src( $featured_id, 'full' ) : null;
		$featured_html  = '';

		if ( $featured_id ) {
			$featured_html = wp_get_attachment_image(
				$featured_id,
				array( 266, 266 ),
				false,
				array(
					'style' => 'max-width:100%;height:auto;',
					'alt'   => get_post_meta( $featured_id, '_wp_attachment_image_alt', true ),
				)
			);
		}

		$gallery = array();

		foreach ( $gallery_ids as $gallery_id ) {
			$thumb = wp_get_attachment_image_src( $gallery_id, 'thumbnail' );
			$gallery[] = array(
				'id'    => $gallery_id,
				'thumb' => ( $thumb && ! empty( $thumb[0] ) ) ? $thumb[0] : wp_get_attachment_url( $gallery_id ),
				'html'  => wp_get_attachment_image( $gallery_id, 'thumbnail' ),
			);
		}

		return array(
			'featured_id'    => $featured_id,
			'featured_thumb' => ( $featured_thumb && ! empty( $featured_thumb[0] ) ) ? $featured_thumb[0] : '',
			'featured_html'  => $featured_html,
			'gallery_ids'    => $gallery_ids,
			'gallery'        => $gallery,
			'remove_label'   => __( 'Remove product image', 'woocommerce' ),
			'delete_label'   => __( 'Delete', 'woocommerce' ),
		);
	}

	/**
	 * Discards staged images (e.g. user closed the modal without applying).
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $staging_key Transient staging key.
	 * @return true|\WP_Error
	 */
	public static function discard_staged( $post_id, $staging_key ) {
		$post_id = absint( $post_id );
		$staged  = get_transient( $staging_key );

		if ( ! is_array( $staged ) || (int) ( $staged['post_id'] ?? 0 ) !== $post_id ) {
			return true;
		}

		if ( (int) ( $staged['user_id'] ?? 0 ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot discard these images.', 'ai-seo-filler' ) );
		}

		self::cleanup_attachments( $staged['ids'] ?? array() );
		delete_transient( $staging_key );

		return true;
	}

	/**
	 * @param \WP_Post $post Post.
	 * @return array{title:string,description:string,language:string,focus_keyword:string}
	 */
	private static function gather_image_context( $post ) {
		$title       = $post->post_title;
		$description = '';
		$keyword     = '';

		if ( WooCommerce::is_product( $post ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$description = $product->get_short_description();

				if ( empty( $description ) ) {
					$description = wp_strip_all_tags( $post->post_content );
				}
			}
		} else {
			$description = $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( $post->post_content );
		}

		$description = wp_trim_words( wp_strip_all_tags( $description ), 120, '…' );

		$plugin = Core::detect_seo_plugin();

		if ( 'rankmath' === $plugin ) {
			$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
			$keyword   = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );

			if ( empty( $description ) && ! empty( $meta_desc ) ) {
				$description = $meta_desc;
			}
		} elseif ( 'yoast' === $plugin ) {
			$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			$keyword   = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );

			if ( empty( $description ) && ! empty( $meta_desc ) ) {
				$description = $meta_desc;
			}
		}

		if ( empty( $description ) ) {
			$description = $title;
		}

		if ( ! empty( $keyword ) ) {
			$keyword = explode( ',', $keyword )[0];
			$keyword = trim( $keyword );
		}

		return array(
			'title'         => $title,
			'description'   => $description,
			'language'      => Settings::get_content_language(),
			'focus_keyword' => $keyword,
		);
	}

	/**
	 * @param array $context    Post context.
	 * @param bool  $is_product Whether this is a WooCommerce product.
	 * @return array|\WP_Error
	 */
	private static function build_image_plan( $context, $is_product ) {
		$image_count = $is_product ? ( 1 + self::GALLERY_COUNT ) : 1;
		$prompt      = self::build_plan_prompt( $context, $image_count, $is_product );
		$raw         = self::call_text_json( $prompt );

		if ( is_wp_error( $raw ) ) {
			Logger::warning( 'Image plan AI failed, using local fallback', array( 'error' => $raw->get_error_message() ) );
			return self::build_local_image_plan( $context, $image_count, $is_product );
		}

		$json_string = AI_Content::extract_json_string( $raw );

		if ( '' === $json_string ) {
			return self::build_local_image_plan( $context, $image_count, $is_product );
		}

		$data = json_decode( $json_string, true );

		if ( ! is_array( $data ) || empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
			return self::build_local_image_plan( $context, $image_count, $is_product );
		}

		$normalized = self::normalize_image_plan( $data, $image_count, $context );

		if ( is_wp_error( $normalized ) ) {
			return self::build_local_image_plan( $context, $image_count, $is_product );
		}

		return $normalized;
	}

	/**
	 * Builds a deterministic image plan without calling a text AI.
	 *
	 * @param array $context     Context.
	 * @param int   $image_count Number of images.
	 * @param bool  $is_product  Product flag.
	 * @return array
	 */
	private static function build_local_image_plan( $context, $image_count, $is_product ) {
		$title   = $context['title'];
		$desc    = wp_strip_all_tags( (string) $context['description'] );
		$keyword = (string) ( $context['focus_keyword'] ?? '' );
		$base    = trim( $title . ( $desc ? '. ' . wp_trim_words( $desc, 40, '' ) : '' ) );
		$slug    = sanitize_title( $title );

		$angles = array(
			'featured' => 'hero product photo, centered, clean white background, studio lighting, photorealistic ecommerce shot',
			'gallery'  => array(
				'three-quarter angle product photo, soft shadows, photorealistic',
				'close-up detail product photo, sharp focus, photorealistic',
				'lifestyle product photo in a realistic use context, photorealistic',
			),
		);

		$images   = array();
		$images[] = array(
			'role'              => 'featured',
			'generation_prompt' => $base . '. ' . $angles['featured'] . '. No text overlays, no watermarks.',
			'alt'               => sanitize_text_field( $keyword ? $title . ' — ' . $keyword : $title ),
			'title'             => sanitize_text_field( $title ),
			'caption'           => sanitize_text_field( wp_trim_words( $desc, 20, '' ) ),
			'filename_slug'     => ( $slug ? $slug : 'ai-image' ) . '-featured',
		);

		if ( $is_product ) {
			for ( $i = 0; $i < self::GALLERY_COUNT; $i++ ) {
				$images[] = array(
					'role'              => 'gallery',
					'generation_prompt' => $base . '. ' . $angles['gallery'][ $i ] . '. No text overlays, no watermarks.',
					'alt'               => sanitize_text_field( $title . ' — view ' . ( $i + 1 ) ),
					'title'             => sanitize_text_field( $title . ' ' . ( $i + 1 ) ),
					'caption'           => '',
					'filename_slug'     => ( $slug ? $slug : 'ai-image' ) . '-gallery-' . ( $i + 1 ),
				);
			}
		}

		return array( 'images' => array_slice( $images, 0, $image_count ) );
	}

	/**
	 * @param array $context     Context.
	 * @param int   $image_count Number of images.
	 * @param bool  $is_product  Product flag.
	 * @return string
	 */
	private static function build_plan_prompt( $context, $image_count, $is_product ) {
		$roles = $is_product
			? '1 featured image (role "featured") and exactly ' . self::GALLERY_COUNT . ' gallery images (role "gallery")'
			: '1 featured image (role "featured")';

		$prompt  = "You are an e-commerce photographer and SEO specialist.\n";
		$prompt .= 'Based on the product/post title and description below, plan exactly ' . $image_count . " AI-generated images: {$roles}.\n\n";
		$prompt .= 'Title: ' . $context['title'] . "\n";
		$prompt .= 'Description: ' . $context['description'] . "\n";

		if ( ! empty( $context['focus_keyword'] ) ) {
			$prompt .= 'Focus keyword: ' . $context['focus_keyword'] . "\n";
		}

		$prompt .= 'Language for alt/title/caption: ' . $context['language'] . "\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- generation_prompt: detailed English prompt for a photorealistic product image (white/neutral background for featured; varied angles/contexts for gallery).\n";
		$prompt .= "- alt: concise SEO alt text in the content language (max 125 chars), include focus keyword when natural.\n";
		$prompt .= "- title: short media title in the content language.\n";
		$prompt .= "- caption: optional short caption in the content language.\n";
		$prompt .= "- filename_slug: lowercase ASCII slug without extension (e.g. macbook-pro-hero).\n";
		$prompt .= "- No text overlays or watermarks in generation_prompt.\n";
		$prompt .= "- Gallery images must show different angles or use cases.\n\n";
		$prompt .= "Respond ONLY with valid JSON:\n";
		$prompt .= "{\n";
		$prompt .= '  "images": [' . "\n";
		$prompt .= '    {"role":"featured","generation_prompt":"...","alt":"...","title":"...","caption":"...","filename_slug":"..."},' . "\n";
		$prompt .= '    {"role":"gallery","generation_prompt":"...","alt":"...","title":"...","caption":"...","filename_slug":"..."}' . "\n";
		$prompt .= "  ]\n";
		$prompt .= "}\n";

		return $prompt;
	}

	/**
	 * @param array $data        Raw AI data.
	 * @param int   $image_count Expected count.
	 * @param array $context     Context for fallbacks.
	 * @return array|\WP_Error
	 */
	private static function normalize_image_plan( $data, $image_count, $context ) {
		$images      = array();
		$has_featured = false;
		$gallery      = 0;

		foreach ( $data['images'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$role = isset( $item['role'] ) ? sanitize_key( $item['role'] ) : '';

			if ( ! in_array( $role, array( 'featured', 'gallery' ), true ) ) {
				continue;
			}

			if ( 'featured' === $role ) {
				if ( $has_featured ) {
					continue;
				}
				$has_featured = true;
			} else {
				if ( $gallery >= self::GALLERY_COUNT ) {
					continue;
				}
				$gallery++;
			}

			$prompt_text = trim( (string) ( $item['generation_prompt'] ?? '' ) );

			if ( '' === $prompt_text ) {
				continue;
			}

			$slug = sanitize_title( (string) ( $item['filename_slug'] ?? $context['title'] . '-' . $role . '-' . ( $gallery + 1 ) ) );

			$images[] = array(
				'role'              => $role,
				'generation_prompt' => $prompt_text,
				'alt'               => sanitize_text_field( (string) ( $item['alt'] ?? $context['title'] ) ),
				'title'             => sanitize_text_field( (string) ( $item['title'] ?? $context['title'] ) ),
				'caption'           => sanitize_text_field( (string) ( $item['caption'] ?? '' ) ),
				'filename_slug'     => $slug ? $slug : 'ai-image-' . wp_generate_password( 6, false ),
			);
		}

		if ( ! $has_featured ) {
			return new \WP_Error( 'image_plan_missing_featured', __( 'AI plan did not include a featured image.', 'ai-seo-filler' ) );
		}

		if ( $image_count > 1 && $gallery < self::GALLERY_COUNT ) {
			return new \WP_Error(
				'image_plan_missing_gallery',
				sprintf(
					/* translators: %d: required gallery image count */
					__( 'AI plan must include %d gallery images.', 'ai-seo-filler' ),
					self::GALLERY_COUNT
				)
			);
		}

		return array( 'images' => $images );
	}

	/**
	 * @param string $prompt   User prompt.
	 * @return string|\WP_Error
	 */
	private static function call_text_json( $prompt ) {
		$provider_slug = Settings::get_ai_provider();
		$chain         = array_unique( array_merge(
			array( $provider_slug ),
			Settings::is_fallback_enabled() ? array( 'gemini', 'groq', 'openai' ) : array()
		) );

		$last_error = null;

		foreach ( $chain as $slug ) {
			$result = self::call_text_json_provider( $prompt, $slug );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( ! AI_Provider::is_recoverable_error( $result ) ) {
				break;
			}
		}

		return $last_error ? $last_error : new \WP_Error( 'no_text_provider', __( 'No text AI provider available for image planning.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $prompt        Prompt.
	 * @param string $provider_slug Provider slug.
	 * @return string|\WP_Error
	 */
	private static function call_text_json_provider( $prompt, $provider_slug ) {
		if ( 'openai' === $provider_slug ) {
			if ( ! Settings::has_openai_api_key() ) {
				return new \WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'ai-seo-filler' ) );
			}

			return self::call_openai_text( $prompt );
		}

		if ( 'groq' === $provider_slug ) {
			if ( ! Settings::has_groq_api_key() ) {
				return new \WP_Error( 'missing_api_key', __( 'Groq API key is not configured.', 'ai-seo-filler' ) );
			}

			$groq = new Groq();
			return $groq->call_text_api( Settings::get_groq_api_key(), $prompt );
		}

		if ( ! Settings::has_api_key() ) {
			return new \WP_Error( 'missing_api_key', __( 'Gemini API key is not configured.', 'ai-seo-filler' ) );
		}

		$gemini = new Gemini();
		return $gemini->call_json_api( Settings::get_api_key(), $prompt );
	}

	/**
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function call_openai_text( $prompt ) {
		$api_key = Settings::get_openai_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'ai-seo-filler' ) );
		}

		$body = array(
			'model'           => Settings::get_openai_model(),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'Respond only with valid JSON.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.5,
			'max_tokens'      => 4096,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			AI_SEO_FILLER_OPENAI_API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'OpenAI API error while planning images.', 'ai-seo-filler' );
			return new \WP_Error( 'openai_api_error', $message );
		}

		if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'openai_empty', __( 'OpenAI returned no content.', 'ai-seo-filler' ) );
		}

		return trim( $decoded['choices'][0]['message']['content'] );
	}

	/**
	 * Whether an OpenAI image model uses the GPT Image API (not DALL-E).
	 *
	 * @param string $model Model slug.
	 * @return bool
	 */
	private static function is_gpt_image_model( $model ) {
		return is_string( $model ) && str_starts_with( $model, 'gpt-image' );
	}

	/**
	 * Builds a request body for OpenAI image generation.
	 *
	 * @param string $model  Model slug.
	 * @param string $prompt Image prompt.
	 * @return array<string, mixed>
	 */
	private static function build_openai_image_request_body( $model, $prompt ) {
		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => '1024x1024',
		);

		if ( self::is_gpt_image_model( $model ) ) {
			$body['quality'] = 'medium';

			return $body;
		}

		$body['quality']         = 'standard';
		$body['response_format'] = 'b64_json';

		return $body;
	}

	/**
	 * Extracts binary image data from an OpenAI images API response.
	 *
	 * @param array<string, mixed> $decoded API JSON body.
	 * @return string|\WP_Error
	 */
	private static function extract_openai_image_binary( $decoded ) {
		if ( ! empty( $decoded['data'][0]['b64_json'] ) ) {
			$binary = base64_decode( $decoded['data'][0]['b64_json'], true );

			if ( false !== $binary && '' !== $binary ) {
				return $binary;
			}
		}

		if ( ! empty( $decoded['data'][0]['url'] ) ) {
			$image_response = wp_remote_get( $decoded['data'][0]['url'], array( 'timeout' => 60 ) );

			if ( is_wp_error( $image_response ) ) {
				return $image_response;
			}

			$image_code = wp_remote_retrieve_response_code( $image_response );

			if ( $image_code < 200 || $image_code >= 300 ) {
				return new \WP_Error( 'openai_image_download', __( 'Could not download the generated OpenAI image.', 'ai-seo-filler' ) );
			}

			$binary = wp_remote_retrieve_body( $image_response );

			if ( '' !== $binary ) {
				return $binary;
			}
		}

		return new \WP_Error( 'openai_image_empty', __( 'OpenAI returned no image data.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $prompt   Image prompt.
	 * @param string $provider Primary provider slug.
	 * @return array{binary:string,provider:string}|\WP_Error
	 */
	private static function generate_image_binary_with_fallback( $prompt, $provider ) {
		$chain      = Settings::get_image_provider_chain();
		$last_error = null;

		if ( ! $chain ) {
			return new \WP_Error( 'no_image_provider', __( 'No image generation provider configured.', 'ai-seo-filler' ) );
		}

		if ( ! in_array( $provider, $chain, true ) ) {
			array_unshift( $chain, $provider );
			$chain = array_values( array_unique( $chain ) );
		}

		foreach ( $chain as $slug ) {
			$result = self::generate_image_binary( $prompt, $slug );

			if ( ! is_wp_error( $result ) ) {
				return array(
					'binary'   => $result,
					'provider' => $slug,
				);
			}

			$last_error = $result;

			if ( ! AI_Provider::is_recoverable_error( $result ) ) {
				break;
			}

			Logger::warning(
				'Image provider failed, trying fallback',
				array(
					'provider' => $slug,
					'error'    => $result->get_error_message(),
				)
			);
		}

		return $last_error ? $last_error : new \WP_Error( 'image_generation_failed', __( 'Image generation failed.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $prompt   Image prompt.
	 * @param string $provider openai|gemini|flux.
	 * @return string|\WP_Error Binary image data.
	 */
	private static function generate_image_binary( $prompt, $provider ) {
		if ( 'flux' === $provider ) {
			return self::generate_with_flux( $prompt );
		}

		if ( 'gemini' === $provider ) {
			return self::generate_with_gemini( $prompt );
		}

		return self::generate_with_openai( $prompt );
	}

	/**
	 * Generates an image via Pollinations Flux (free, no API key).
	 *
	 * @param string $prompt Image prompt.
	 * @return string|\WP_Error Binary image data.
	 */
	private static function generate_with_flux( $prompt ) {
		$prompt = trim( wp_strip_all_tags( (string) $prompt ) );

		if ( '' === $prompt ) {
			return new \WP_Error( 'flux_empty_prompt', __( 'Image prompt is empty.', 'ai-seo-filler' ) );
		}

		// Keep URL length reasonable for Pollinations path encoding.
		if ( strlen( $prompt ) > 900 ) {
			$prompt = substr( $prompt, 0, 900 );
		}

		$model = Settings::get_flux_model();
		$query = array(
			'model'  => $model,
			'width'  => 1024,
			'height' => 1024,
			'nologo' => 'true',
			'seed'   => wp_rand( 1, 999999 ),
		);

		$url = AI_SEO_FILLER_POLLINATIONS_IMAGE_URL . rawurlencode( $prompt ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		$attempt = 0;
		$max     = 3;
		$delay   = 5;

		while ( $attempt < $max ) {
			$attempt++;

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 120,
					'headers' => array(
						'Accept' => 'image/*',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}

				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 429 === (int) $code || 503 === (int) $code ) {
				if ( $attempt < $max ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}

				return new \WP_Error(
					'flux_rate_limited',
					__( 'Flux / Pollinations rate limit reached. Wait a moment and try again.', 'ai-seo-filler' )
				);
			}

			if ( $code < 200 || $code >= 300 || '' === $body ) {
				return new \WP_Error(
					'flux_image_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Flux / Pollinations image error (HTTP %d).', 'ai-seo-filler' ),
						(int) $code
					)
				);
			}

			$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

			if ( $content_type && false === stripos( $content_type, 'image' ) && false === stripos( $content_type, 'octet-stream' ) ) {
				return new \WP_Error( 'flux_invalid_response', __( 'Flux / Pollinations did not return an image.', 'ai-seo-filler' ) );
			}

			return $body;
		}

		return new \WP_Error( 'flux_image_error', __( 'Flux / Pollinations image generation failed.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function generate_with_openai( $prompt ) {
		$api_key = Settings::get_openai_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'OpenAI API key is not configured.', 'ai-seo-filler' ) );
		}

		$model = Settings::get_openai_image_model();
		$body  = self::build_openai_image_request_body( $model, $prompt );

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'OpenAI image API error.', 'ai-seo-filler' );

			// Retry without response_format when an older client sends it to GPT Image models.
			if ( ! self::is_gpt_image_model( $model )
				&& str_contains( strtolower( $message ), 'response_format' )
				&& isset( $body['response_format'] ) ) {
				unset( $body['response_format'] );
				$response = wp_remote_post(
					'https://api.openai.com/v1/images/generations',
					array(
						'timeout' => 120,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $api_key,
						),
						'body'    => wp_json_encode( $body ),
					)
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$code    = wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( $code < 200 || $code >= 300 ) {
					$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'OpenAI image API error.', 'ai-seo-filler' );
					return new \WP_Error( 'openai_image_error', $message );
				}
			} else {
				return new \WP_Error( 'openai_image_error', $message );
			}
		}

		return self::extract_openai_image_binary( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function generate_with_gemini( $prompt ) {
		$api_key = Settings::get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'Gemini API key is not configured.', 'ai-seo-filler' ) );
		}

		$models   = array_unique( array_filter( array(
			Settings::get_gemini_image_model(),
			AI_SEO_FILLER_GEMINI_IMAGE_MODEL_DEFAULT,
			'gemini-3.1-flash-image-preview',
		) ) );
		$api_key  = trim( $api_key );
		$last_err = null;

		foreach ( $models as $model ) {
			$result = self::request_gemini_image( $api_key, $model, $prompt );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_err = $result;

			if ( ! self::is_gemini_model_not_found_error( $result ) ) {
				return $result;
			}
		}

		return $last_err ? $last_err : new \WP_Error( 'gemini_image_error', __( 'Gemini image API error.', 'ai-seo-filler' ) );
	}

	/**
	 * @param string $api_key API key.
	 * @param string $model   Model slug.
	 * @param string $prompt  Image prompt.
	 * @return string|\WP_Error
	 */
	private static function request_gemini_image( $api_key, $model, $prompt ) {
		$endpoint = AI_SEO_FILLER_GEMINI_API_URL . rawurlencode( $model ) . ':generateContent';

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'IMAGE' ),
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Gemini image API error.', 'ai-seo-filler' );

			return new \WP_Error( 'gemini_image_error', $message );
		}

		$binary = self::extract_gemini_image_binary( $decoded );

		if ( '' === $binary ) {
			return new \WP_Error( 'gemini_image_empty', __( 'Gemini returned no image data.', 'ai-seo-filler' ) );
		}

		return $binary;
	}

	/**
	 * @param array $decoded API response.
	 * @return string Binary image data or empty string.
	 */
	private static function extract_gemini_image_binary( $decoded ) {
		if ( empty( $decoded['candidates'] ) || ! is_array( $decoded['candidates'] ) ) {
			return '';
		}

		foreach ( $decoded['candidates'] as $candidate ) {
			if ( empty( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
				continue;
			}

			foreach ( $candidate['content']['parts'] as $part ) {
				if ( empty( $part['inlineData']['data'] ) ) {
					continue;
				}

				$binary = base64_decode( $part['inlineData']['data'], true );

				if ( false !== $binary && '' !== $binary ) {
					return $binary;
				}
			}
		}

		return '';
	}

	/**
	 * @param \WP_Error $error Error object.
	 * @return bool
	 */
	private static function is_gemini_model_not_found_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'not found' )
			|| false !== strpos( $message, 'not supported' );
	}

	/**
	 * @param string $binary     Image binary.
	 * @param array  $image_plan Plan row.
	 * @param int    $post_id    Parent post.
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function upload_attachment( $binary, $image_plan, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( $image_plan['filename_slug'] . self::guess_image_extension( $binary ) );
		$upload   = wp_upload_bits( $filename, null, $binary );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'upload_failed', $upload['error'] );
		}

		$filetype = wp_check_filetype( $upload['file'], null );

		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/png',
			'post_title'     => $image_plan['title'],
			'post_content'   => '',
			'post_excerpt'   => $image_plan['caption'],
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'attachment_failed', __( 'Could not create media attachment.', 'ai-seo-filler' ) );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image_plan['alt'] );

		return (int) $attachment_id;
	}

	/**
	 * @param int[] $attachment_ids Attachment IDs.
	 */
	private static function cleanup_attachments( $attachment_ids ) {
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	/**
	 * @param int[] $attachment_ids IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_images_summary( $attachment_ids ) {
		$summary = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$thumb         = wp_get_attachment_image_src( $attachment_id, 'medium' );
			$full          = wp_get_attachment_url( $attachment_id );

			$summary[] = array(
				'id'             => $attachment_id,
				'url'            => $full ? $full : '',
				'thumb'          => ( $thumb && ! empty( $thumb[0] ) ) ? $thumb[0] : $full,
				'alt'            => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'title'          => get_the_title( $attachment_id ),
				'suggested_role' => get_post_meta( $attachment_id, '_ai_seo_filler_suggested_role', true ),
			);
		}

		return $summary;
	}

	/**
	 * @param string $binary Image bytes.
	 * @return string File extension including dot.
	 */
	private static function guess_image_extension( $binary ) {
		if ( strncmp( $binary, "\xFF\xD8\xFF", 3 ) === 0 ) {
			return '.jpg';
		}

		if ( strncmp( $binary, "\x89PNG\r\n\x1a\n", 8 ) === 0 ) {
			return '.png';
		}

		if ( strncmp( $binary, 'RIFF', 4 ) === 0 && substr( $binary, 8, 4 ) === 'WEBP' ) {
			return '.webp';
		}

		return '.png';
	}
}
