<?php
/**
 * Clase principal del plugin: registra hooks y coordina los componentes.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Core — singleton que orquesta todo el plugin.
 */
class Core {

	/**
	 * Instancia singleton.
	 *
	 * @var Core|null
	 */
	private static $instance = null;

	/**
	 * Cliente de la API de Gemini.
	 *
	 * @var Gemini
	 */
	private $gemini;

	/**
	 * Obtiene la instancia singleton.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado para el patrón singleton.
	 */
	private function __construct() {
		$this->gemini = new Gemini();
	}

	/**
	 * Registra todos los hooks de WordPress del plugin.
	 */
	public function init() {
		$this->load_textdomain();
		$this->register_cron_schedules();

		// Hooks de administración.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX — modo individual desde el editor.
		add_action( 'wp_ajax_ai_seo_filler_generate_single', array( $this, 'ajax_generate_single' ) );

		// AJAX — modo masivo desde el panel de administración.
		add_action( 'wp_ajax_ai_seo_filler_start_bulk', array( $this, 'ajax_start_bulk' ) );
		add_action( 'wp_ajax_ai_seo_filler_bulk_status', array( $this, 'ajax_bulk_status' ) );

		// WP-Cron — procesamiento de la cola masiva.
		add_action( 'ai_seo_filler_process_bulk_queue', array( $this, 'process_bulk_queue' ) );

		// Metabox en el editor de entradas, páginas y productos.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Carga el dominio de traducción del plugin.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ai-seo-filler',
			false,
			dirname( AI_SEO_FILLER_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Registra intervalos de cron personalizados para el procesamiento masivo.
	 */
	public function register_cron_schedules() {
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				if ( ! isset( $schedules['ai_seo_filler_every_minute'] ) ) {
					$schedules['ai_seo_filler_every_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						'display'  => __( 'Cada minuto (AI SEO Filler)', 'ai-seo-filler' ),
					);
				}

				return $schedules;
			}
		);
	}

	/**
	 * Registra las opciones del plugin en el API de Settings de WordPress.
	 */
	public function register_settings() {
		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_PREFIX . 'language',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => get_locale(),
			)
		);

		register_setting(
			'ai_seo_filler_settings',
			AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'auto',
			)
		);
	}

	/**
	 * Sanitiza y cifra la API key antes de guardarla.
	 *
	 * @param string $value Valor enviado desde el formulario de ajustes.
	 * @return string API key cifrada lista para almacenar.
	 */
	public function sanitize_api_key( $value ) {
		$value = sanitize_text_field( $value );

		if ( empty( $value ) ) {
			return '';
		}

		return self::encrypt_api_key( $value );
	}

	/**
	 * Cifra un valor usando OpenSSL con una clave derivada de wp_hash().
	 *
	 * wp_hash() por sí solo es unidireccional; aquí se usa para derivar
	 * la clave de cifrado simétrico de forma determinista por instalación.
	 *
	 * @param string $plain_text Texto plano a cifrar.
	 * @return string Texto cifrado codificado en base64.
	 */
	public static function encrypt_api_key( $plain_text ) {
		$key = substr( hash( 'sha256', wp_hash( 'ai-seo-filler-api-key', 'ai-seo-filler' ) ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $plain_text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Descifra un valor previamente cifrado con encrypt_api_key().
	 *
	 * @param string $cipher_text Texto cifrado en base64.
	 * @return string Texto plano o cadena vacía si falla el descifrado.
	 */
	public static function decrypt_api_key( $cipher_text ) {
		if ( empty( $cipher_text ) ) {
			return '';
		}

		$raw = base64_decode( $cipher_text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$key       = substr( hash( 'sha256', wp_hash( 'ai-seo-filler-api-key', 'ai-seo-filler' ) ), 0, 32 );
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );

		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Obtiene la API key de Gemini descifrada desde las opciones.
	 *
	 * @return string API key en texto plano o cadena vacía.
	 */
	public static function get_api_key() {
		$stored = get_option( AI_SEO_FILLER_OPTION_API_KEY, '' );

		return self::decrypt_api_key( $stored );
	}

	/**
	 * Detecta qué plugin SEO está activo.
	 *
	 * @return string 'rankmath', 'yoast' o 'none'.
	 */
	public static function detect_seo_plugin() {
		$forced = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin', 'auto' );

		if ( 'rankmath' === $forced ) {
			return 'rankmath';
		}

		if ( 'yoast' === $forced ) {
			return 'yoast';
		}

		// Detección automática.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}

		return 'none';
	}

	/**
	 * Encola los assets de administración (CSS/JS) en las pantallas del plugin.
	 *
	 * @param string $hook_suffix Hook de la pantalla actual en wp-admin.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$plugin_screens = array(
			'toplevel_page_ai-seo-filler',
			'ai-seo-filler_page_ai-seo-filler-bulk',
		);

		$is_plugin_screen = in_array( $hook_suffix, $plugin_screens, true );
		$is_editor_screen = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_screen && ! $is_editor_screen ) {
			return;
		}

		wp_enqueue_style(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			AI_SEO_FILLER_VERSION
		);

		wp_enqueue_script(
			'ai-seo-filler-admin',
			AI_SEO_FILLER_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			AI_SEO_FILLER_VERSION,
			true
		);

		wp_localize_script(
			'ai-seo-filler-admin',
			'aiSeoFiller',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ai_seo_filler_nonce' ),
				'i18n'    => array(
					'generating' => __( 'Generando campos SEO…', 'ai-seo-filler' ),
					'success'    => __( 'Campos SEO generados correctamente.', 'ai-seo-filler' ),
					'error'      => __( 'Error al generar los campos SEO.', 'ai-seo-filler' ),
				),
			)
		);
	}

	/**
	 * Registra el metabox en el editor de entradas, páginas y productos.
	 */
	public function register_meta_box() {
		$post_types = array( 'post', 'page' );

		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ai-seo-filler-metabox',
				__( 'AI SEO Filler', 'ai-seo-filler' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renderiza el contenido del metabox en el editor.
	 *
	 * @param \WP_Post $post Objeto de la entrada actual.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'ai_seo_filler_metabox', 'ai_seo_filler_metabox_nonce' );

		$seo_plugin = self::detect_seo_plugin();
		$has_api    = ! empty( self::get_api_key() );

		echo '<div class="ai-seo-filler-metabox">';

		if ( ! $has_api ) {
			echo '<p class="description">';
			echo esc_html__( 'Configura tu API key de Gemini en Ajustes → AI SEO Filler.', 'ai-seo-filler' );
			echo '</p>';
		} elseif ( 'none' === $seo_plugin ) {
			echo '<p class="description">';
			echo esc_html__( 'No se detectó Rank Math ni Yoast SEO activo.', 'ai-seo-filler' );
			echo '</p>';
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: nombre del plugin SEO detectado */
						__( 'Plugin SEO detectado: %s', 'ai-seo-filler' ),
						'rankmath' === $seo_plugin ? 'Rank Math' : 'Yoast SEO'
					)
				)
			);

			printf(
				'<button type="button" class="button button-primary ai-seo-filler-generate" data-post-id="%d">%s</button>',
				(int) $post->ID,
				esc_html__( 'Generar SEO con IA', 'ai-seo-filler' )
			);

			echo '<div class="ai-seo-filler-status" aria-live="polite"></div>';
		}

		echo '</div>';
	}

	/**
	 * Maneja la petición AJAX de generación individual.
	 */
	public function ajax_generate_single() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'ai-seo-filler' ) ),
				403
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Entrada no válida.', 'ai-seo-filler' ) ),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No puedes editar esta entrada.', 'ai-seo-filler' ) ),
				403
			);
		}

		$result = $this->generate_and_save_seo( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Campos SEO generados y guardados correctamente.', 'ai-seo-filler' ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Genera los datos SEO con Gemini y los guarda en el plugin SEO activo.
	 *
	 * @param int $post_id ID de la entrada o producto.
	 * @return array|\WP_Error Datos SEO guardados o error.
	 */
	public function generate_and_save_seo( $post_id ) {
		$seo_data = $this->gemini->generate_seo_data( $post_id );

		if ( is_wp_error( $seo_data ) ) {
			return $seo_data;
		}

		$seo_plugin = self::detect_seo_plugin();

		if ( 'none' === $seo_plugin ) {
			return new \WP_Error(
				'no_seo_plugin',
				__( 'No se detectó ningún plugin SEO compatible.', 'ai-seo-filler' )
			);
		}

		// Las clases RankMath y Yoast se implementarán en siguientes iteraciones.
		if ( 'rankmath' === $seo_plugin && class_exists( __NAMESPACE__ . '\\RankMath' ) ) {
			$writer = new RankMath();
			$writer->save_seo_data( $post_id, $seo_data );
		} elseif ( 'yoast' === $seo_plugin && class_exists( __NAMESPACE__ . '\\Yoast' ) ) {
			$writer = new Yoast();
			$writer->save_seo_data( $post_id, $seo_data );
		}

		// Actualizar slug si se sugirió uno.
		if ( ! empty( $seo_data['slug'] ) ) {
			wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => sanitize_title( $seo_data['slug'] ),
				)
			);
		}

		return $seo_data;
	}

	/**
	 * Inicia el procesamiento masivo encolando IDs en una opción transitoria.
	 */
	public function ajax_start_bulk() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'ai-seo-filler' ) ),
				403
			);
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$status    = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'publish';

		$allowed_types = array( 'post', 'page' );

		if ( class_exists( 'WooCommerce' ) ) {
			$allowed_types[] = 'product';
		}

		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Tipo de contenido no válido.', 'ai-seo-filler' ) ),
				400
			);
		}

		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $post_ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No se encontraron entradas para procesar.', 'ai-seo-filler' ) ),
				404
			);
		}

		update_option(
			AI_SEO_FILLER_OPTION_PREFIX . 'bulk_queue',
			array(
				'pending'  => $post_ids,
				'processed' => array(),
				'errors'   => array(),
				'total'    => count( $post_ids ),
				'started'  => time(),
			),
			false
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: número de entradas encoladas */
					__( 'Se han encolado %d entradas para procesamiento.', 'ai-seo-filler' ),
					count( $post_ids )
				),
				'total'   => count( $post_ids ),
			)
		);
	}

	/**
	 * Devuelve el estado actual de la cola de procesamiento masivo.
	 */
	public function ajax_bulk_status() {
		check_ajax_referer( 'ai_seo_filler_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'ai-seo-filler' ) ),
				403
			);
		}

		$queue = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'bulk_queue', array() );

		if ( empty( $queue ) ) {
			wp_send_json_success(
				array(
					'status'    => 'idle',
					'total'     => 0,
					'processed' => 0,
					'pending'   => 0,
					'errors'    => 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'status'    => empty( $queue['pending'] ) ? 'completed' : 'processing',
				'total'     => (int) $queue['total'],
				'processed' => count( $queue['processed'] ),
				'pending'   => count( $queue['pending'] ),
				'errors'    => count( $queue['errors'] ),
			)
		);
	}

	/**
	 * Procesa un lote de la cola masiva vía WP-Cron.
	 */
	public function process_bulk_queue() {
		$queue = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'bulk_queue', array() );

		if ( empty( $queue['pending'] ) ) {
			return;
		}

		$batch = array_splice( $queue['pending'], 0, AI_SEO_FILLER_BULK_BATCH_SIZE );

		foreach ( $batch as $post_id ) {
			$result = $this->generate_and_save_seo( (int) $post_id );

			if ( is_wp_error( $result ) ) {
				$queue['errors'][] = array(
					'post_id' => (int) $post_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$queue['processed'][] = (int) $post_id;
			}
		}

		update_option( AI_SEO_FILLER_OPTION_PREFIX . 'bulk_queue', $queue, false );
	}

	/**
	 * Impide la clonación del singleton.
	 */
	private function __clone() {}

	/**
	 * Impide la deserialización del singleton.
	 */
	public function __wakeup() {
		throw new \Exception( 'No se puede deserializar un singleton.' );
	}
}
