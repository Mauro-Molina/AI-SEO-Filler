<?php
/**
 * Cliente de la API de Gemini Flash para generación de campos SEO.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Gemini — comunicación con la API de Google Generative Language.
 */
class Gemini {

	/**
	 * Tiempo máximo de espera para la petición HTTP en segundos.
	 *
	 * @var int
	 */
	private $timeout = 60;

	/**
	 * Genera los datos SEO para una entrada o producto usando Gemini Flash.
	 *
	 * Lee el contenido del post, construye un prompt estructurado,
	 * llama a la API y devuelve un array con todos los campos SEO.
	 *
	 * @param int $post_id ID de la entrada o producto.
	 * @return array|\WP_Error Array estructurado con los campos SEO o WP_Error.
	 */
	public function generate_seo_data( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new \WP_Error(
				'invalid_post_id',
				__( 'ID de entrada no válido.', 'ai-seo-filler' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'No se encontró la entrada solicitada.', 'ai-seo-filler' )
			);
		}

		$api_key = Core::get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'No se ha configurado la API key de Gemini.', 'ai-seo-filler' )
			);
		}

		$content_data = $this->gather_post_content( $post );
		$prompt       = $this->build_prompt( $content_data );
		$response     = $this->call_api( $api_key, $prompt );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response );
	}

	/**
	 * Recopila el contenido relevante de una entrada para el prompt.
	 *
	 * @param \WP_Post $post Objeto de la entrada.
	 * @return array Datos estructurados del contenido.
	 */
	private function gather_post_content( $post ) {
		$categories = array();
		$tags       = array();

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
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
				$price             = $product->get_price();
				$sku               = $product->get_sku();

				$content_body = $post->post_content;
				if ( ! empty( $short_description ) ) {
					$content_body = $short_description . "\n\n" . $content_body;
				}
			} else {
				$content_body = $post->post_content;
				$price        = '';
				$sku          = '';
			}
		} else {
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			$content_body = $post->post_content;
			$price      = '';
			$sku        = '';
		}

		$images = $this->gather_images( $post );

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
	 * Recopila las imágenes asociadas a una entrada.
	 *
	 * @param \WP_Post $post Objeto de la entrada.
	 * @return array Lista de imágenes con ID, URL y alt text actual.
	 */
	private function gather_images( $post ) {
		$images = array();

		// Imagen destacada.
		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( $thumbnail_id ) {
			$images[] = array(
				'id'          => (int) $thumbnail_id,
				'url'         => wp_get_attachment_url( $thumbnail_id ),
				'alt'         => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
				'is_featured' => true,
			);
		}

		// Imágenes embebidas en el contenido.
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

		// Imágenes de galería de producto WooCommerce.
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$gallery_ids = $product->get_gallery_image_ids();

				foreach ( $gallery_ids as $gallery_id ) {
					$already_listed = false;

					foreach ( $images as $existing ) {
						if ( $existing['id'] === (int) $gallery_id ) {
							$already_listed = true;
							break;
						}
					}

					if ( ! $already_listed ) {
						$images[] = array(
							'id'          => (int) $gallery_id,
							'url'         => wp_get_attachment_url( $gallery_id ),
							'alt'         => get_post_meta( $gallery_id, '_wp_attachment_image_alt', true ),
							'is_featured' => false,
						);
					}
				}
			}
		}

		return $images;
	}

	/**
	 * Construye el prompt que se enviará a Gemini.
	 *
	 * @param array $content_data Datos recopilados de la entrada.
	 * @return string Prompt completo en texto plano.
	 */
	private function build_prompt( $content_data ) {
		$images_description = '';

		if ( ! empty( $content_data['images'] ) ) {
			$image_lines = array();

			foreach ( $content_data['images'] as $image ) {
				$image_lines[] = sprintf(
					'- ID %d (destacada: %s): alt actual "%s"',
					$image['id'],
					$image['is_featured'] ? 'sí' : 'no',
					$image['alt']
				);
			}

			$images_description = implode( "\n", $image_lines );
		} else {
			$images_description = '(sin imágenes)';
		}

		$prompt  = "Eres un experto en SEO para WordPress. Analiza el siguiente contenido y genera campos SEO optimizados.\n\n";
		$prompt .= "IDIOMA DE RESPUESTA: {$content_data['language']}\n";
		$prompt .= "SITIO WEB: {$content_data['site_name']}\n";
		$prompt .= "TIPO DE CONTENIDO: {$content_data['post_type']}\n\n";
		$prompt .= "TÍTULO: {$content_data['title']}\n\n";
		$prompt .= "EXTRACTO: {$content_data['excerpt']}\n\n";
		$prompt .= "CONTENIDO:\n{$content_data['content']}\n\n";

		if ( ! empty( $content_data['categories'] ) ) {
			$prompt .= 'CATEGORÍAS: ' . implode( ', ', $content_data['categories'] ) . "\n";
		}

		if ( ! empty( $content_data['tags'] ) ) {
			$prompt .= 'ETIQUETAS: ' . implode( ', ', $content_data['tags'] ) . "\n";
		}

		if ( ! empty( $content_data['price'] ) ) {
			$prompt .= "PRECIO: {$content_data['price']}\n";
		}

		if ( ! empty( $content_data['sku'] ) ) {
			$prompt .= "SKU: {$content_data['sku']}\n";
		}

		$prompt .= "\nIMÁGENES:\n{$images_description}\n\n";
		$prompt .= "Responde ÚNICAMENTE con un objeto JSON válido (sin markdown, sin explicaciones) con esta estructura exacta:\n";
		$prompt .= "{\n";
		$prompt .= '  "meta_title": "título SEO (máx. 60 caracteres)",' . "\n";
		$prompt .= '  "meta_description": "meta descripción (máx. 160 caracteres)",' . "\n";
		$prompt .= '  "focus_keyword": "palabra clave principal",' . "\n";
		$prompt .= '  "slug": "slug-url-sugerido-sin-espacios",' . "\n";
		$prompt .= '  "og_title": "título Open Graph",' . "\n";
		$prompt .= '  "og_description": "descripción Open Graph",' . "\n";
		$prompt .= '  "image_alts": {' . "\n";
		$prompt .= '    "ID_IMAGEN": "texto alt sugerido"' . "\n";
		$prompt .= "  }\n";
		$prompt .= "}\n\n";
		$prompt .= 'En "image_alts", usa como claves los IDs numéricos de las imágenes listadas arriba.';

		return $prompt;
	}

	/**
	 * Realiza la llamada HTTP a la API de Gemini Flash.
	 *
	 * @param string $api_key API key de Google AI.
	 * @param string $prompt  Texto del prompt.
	 * @return string|\WP_Error Texto de respuesta de Gemini o WP_Error.
	 */
	private function call_api( $api_key, $prompt ) {
		$model    = AI_SEO_FILLER_GEMINI_MODEL;
		$endpoint = AI_SEO_FILLER_GEMINI_API_URL . $model . ':generateContent?key=' . rawurlencode( $api_key );

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => 0.4,
				'topP'            => 0.9,
				'maxOutputTokens' => 1024,
				'responseMimeType' => 'application/json',
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'gemini_request_failed',
				sprintf(
					/* translators: %s: mensaje de error de red */
					__( 'Error de conexión con Gemini: %s', 'ai-seo-filler' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_data = json_decode( $raw_body, true );
			$message    = isset( $error_data['error']['message'] )
				? $error_data['error']['message']
				: __( 'Error desconocido de la API de Gemini.', 'ai-seo-filler' );

			return new \WP_Error(
				'gemini_api_error',
				sprintf(
					/* translators: 1: código HTTP, 2: mensaje de error */
					__( 'Gemini respondió con error %1$d: %2$s', 'ai-seo-filler' ),
					$status_code,
					$message
				),
				array( 'status' => $status_code )
			);
		}

		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'gemini_invalid_response',
				__( 'La respuesta de Gemini no es un JSON válido.', 'ai-seo-filler' )
			);
		}

		$text = $this->extract_text_from_response( $decoded );

		if ( empty( $text ) ) {
			return new \WP_Error(
				'gemini_empty_response',
				__( 'Gemini no devolvió contenido en la respuesta.', 'ai-seo-filler' )
			);
		}

		return $text;
	}

	/**
	 * Extrae el texto generado del cuerpo de respuesta de Gemini.
	 *
	 * @param array $decoded Respuesta JSON decodificada.
	 * @return string Texto generado o cadena vacía.
	 */
	private function extract_text_from_response( $decoded ) {
		if ( ! isset( $decoded['candidates'] ) || ! is_array( $decoded['candidates'] ) ) {
			return '';
		}

		foreach ( $decoded['candidates'] as $candidate ) {
			if ( ! isset( $candidate['content']['parts'] ) ) {
				continue;
			}

			foreach ( $candidate['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					return trim( $part['text'] );
				}
			}
		}

		return '';
	}

	/**
	 * Parsea y valida la respuesta JSON de Gemini.
	 *
	 * @param string $raw_text Texto JSON devuelto por Gemini.
	 * @return array|\WP_Error Array estructurado con los campos SEO.
	 */
	private function parse_response( $raw_text ) {
		// Limpiar posibles bloques de código markdown que Gemini pueda añadir.
		$clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_text ) );
		$clean = preg_replace( '/\s*```$/', '', $clean );

		$data = json_decode( $clean, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'gemini_parse_error',
				__( 'No se pudo parsear la respuesta JSON de Gemini.', 'ai-seo-filler' )
			);
		}

		$image_alts = array();

		if ( isset( $data['image_alts'] ) && is_array( $data['image_alts'] ) ) {
			foreach ( $data['image_alts'] as $image_id => $alt_text ) {
				$image_alts[ absint( $image_id ) ] = sanitize_text_field( $alt_text );
			}
		}

		return array(
			'meta_title'       => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description' => isset( $data['meta_description'] ) ? sanitize_textarea_field( $data['meta_description'] ) : '',
			'focus_keyword'    => isset( $data['focus_keyword'] ) ? sanitize_text_field( $data['focus_keyword'] ) : '',
			'slug'             => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'og_title'         => isset( $data['og_title'] ) ? sanitize_text_field( $data['og_title'] ) : '',
			'og_description'   => isset( $data['og_description'] ) ? sanitize_textarea_field( $data['og_description'] ) : '',
			'image_alts'       => $image_alts,
		);
	}
}
