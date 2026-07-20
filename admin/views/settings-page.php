<?php
/**
 * Settings page view.
 *
 * @package AiSeoFiller
 */

defined( 'ABSPATH' ) || exit;

use AiSeoFiller\AI_Provider;
use AiSeoFiller\Core;
use AiSeoFiller\Settings;

$seo_plugin        = Core::detect_seo_plugin();
$seo_options       = array(
	'auto'     => __( 'Auto-detect', 'ai-seo-filler' ),
	'rankmath' => __( 'Rank Math', 'ai-seo-filler' ),
	'yoast'    => __( 'Yoast SEO', 'ai-seo-filler' ),
);
$provider_options  = Settings::get_available_providers();
$detected_label    = 'none' === $seo_plugin
	? __( 'None detected', 'ai-seo-filler' )
	: ( 'rankmath' === $seo_plugin ? 'Rank Math' : 'Yoast SEO' );
$current_provider  = Settings::get_ai_provider();
$current_seo       = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin', 'auto' );
$current_lang      = Settings::get_content_language();
$content_languages = Settings::get_available_content_languages();
$current_model     = Settings::get_active_model();
$gemini_models     = Settings::get_available_models();
$groq_models       = Settings::get_available_groq_models();
$current_gemini    = Settings::get_gemini_model();
$current_groq      = Settings::get_groq_model();
$gemini_key        = Settings::get_api_key();
$groq_key          = Settings::get_groq_api_key();
$openai_key        = Settings::get_openai_api_key();
$selectable_types  = Settings::get_selectable_post_types();
$enabled_types     = Settings::get_enabled_post_types();
$cpt_option_name   = AI_SEO_FILLER_OPTION_PREFIX . 'enabled_post_types';

$tabs = array(
	'providers' => __( 'AI Providers', 'ai-seo-filler' ),
	'images'    => __( 'Images', 'ai-seo-filler' ),
	'generation'=> __( 'Generation', 'ai-seo-filler' ),
	'types'     => __( 'Content Types', 'ai-seo-filler' ),
	'general'   => __( 'General', 'ai-seo-filler' ),
);

/**
 * Renders an API secret field with show/hide, copy, and configured status.
 *
 * @param array $args Field args.
 */
$ai_seo_render_secret_field = static function ( $args ) {
	$id          = (string) ( $args['id'] ?? '' );
	$name        = (string) ( $args['name'] ?? '' );
	$value       = (string) ( $args['value'] ?? '' );
	$label       = (string) ( $args['label'] ?? '' );
	$placeholder = (string) ( $args['placeholder'] ?? '' );
	$help        = (string) ( $args['help'] ?? '' );
	$configured  = ! empty( $args['configured'] );
	$status_id   = (string) ( $args['status_id'] ?? '' );
	$extra_html  = (string) ( $args['extra_html'] ?? '' );
	$preview     = $configured ? Settings::get_secret_preview( $value ) : '';
	?>
	<div class="ai-seo-filler-secret-field">
		<input
			type="text"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text ai-seo-filler-secret-input is-masked"
			autocomplete="off"
			spellcheck="false"
			data-has-secret="<?php echo $configured ? '1' : '0'; ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		/>
		<button type="button" class="button ai-seo-filler-toggle-secret" aria-label="<?php esc_attr_e( 'Show key', 'ai-seo-filler' ); ?>"><?php esc_html_e( 'Show', 'ai-seo-filler' ); ?></button>
		<button type="button" class="button ai-seo-filler-copy-secret" <?php disabled( ! $configured && '' === $value ); ?> aria-label="<?php esc_attr_e( 'Copy key', 'ai-seo-filler' ); ?>"><?php esc_html_e( 'Copy', 'ai-seo-filler' ); ?></button>
	</div>
	<p class="description ai-seo-filler-secret-status" <?php echo $status_id ? 'id="' . esc_attr( $status_id ) . '"' : ''; ?>>
		<?php if ( $configured ) : ?>
			<span class="ai-seo-filler-badge ai-seo-filler-badge--ok">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: provider label, 2: key preview */
						__( '%1$s configured (%2$s)', 'ai-seo-filler' ),
						$label,
						$preview
					)
				);
				?>
			</span>
		<?php else : ?>
			<span class="ai-seo-filler-badge ai-seo-filler-badge--warn">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: provider label */
						__( '%s not configured', 'ai-seo-filler' ),
						$label
					)
				);
				?>
			</span>
			<?php if ( $help ) : ?>
				<span class="ai-seo-filler-secret-help"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
		<?php endif; ?>
	</p>
	<?php
	if ( $extra_html ) {
		echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by trusted callers.
	}
};
?>
<div class="wrap ai-seo-filler-wrap ai-seo-filler-settings-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'ai_seo_filler_settings' ); ?>

	<div class="ai-seo-filler-status-strip" aria-label="<?php esc_attr_e( 'Current status', 'ai-seo-filler' ); ?>">
		<div class="ai-seo-filler-status-strip__item">
			<span class="ai-seo-filler-status-strip__label"><?php esc_html_e( 'Provider', 'ai-seo-filler' ); ?></span>
			<span class="ai-seo-filler-status-strip__value"><?php echo esc_html( AI_Provider::get_active_provider_label() ); ?></span>
		</div>
		<div class="ai-seo-filler-status-strip__item">
			<span class="ai-seo-filler-status-strip__label"><?php esc_html_e( 'Model', 'ai-seo-filler' ); ?></span>
			<code class="ai-seo-filler-status-strip__value"><?php echo esc_html( $current_model ); ?></code>
		</div>
		<div class="ai-seo-filler-status-strip__item">
			<span class="ai-seo-filler-status-strip__label"><?php esc_html_e( 'SEO', 'ai-seo-filler' ); ?></span>
			<span class="ai-seo-filler-status-strip__value"><?php echo esc_html( $detected_label ); ?></span>
		</div>
		<div class="ai-seo-filler-status-strip__item ai-seo-filler-status-strip__item--apis">
			<span class="ai-seo-filler-status-strip__label"><?php esc_html_e( 'APIs', 'ai-seo-filler' ); ?></span>
			<span class="ai-seo-filler-api-status-list">
				<span class="ai-seo-filler-badge <?php echo Settings::has_api_key() ? 'ai-seo-filler-badge--ok' : 'ai-seo-filler-badge--warn'; ?>">
					Gemini
				</span>
				<span class="ai-seo-filler-badge <?php echo Settings::has_groq_api_key() ? 'ai-seo-filler-badge--ok' : 'ai-seo-filler-badge--warn'; ?>">
					Groq
				</span>
				<span class="ai-seo-filler-badge <?php echo Settings::has_openai_api_key() ? 'ai-seo-filler-badge--ok' : 'ai-seo-filler-badge--warn'; ?>">
					OpenAI
				</span>
			</span>
		</div>
	</div>

	<?php if ( 'openai' === $current_provider && ! Settings::has_openai_api_key() ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'OpenAI is selected but has no API key. Add one in Images, or switch to Gemini/Groq for free-tier text generation.', 'ai-seo-filler' ); ?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="options.php" class="ai-seo-filler-settings-form">
		<?php
		settings_fields( 'ai_seo_filler_settings' );
		do_settings_sections( 'ai_seo_filler_settings' );
		?>

		<nav class="nav-tab-wrapper ai-seo-filler-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-seo-filler' ); ?>">
			<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
				<a
					href="#ai-seo-tab-<?php echo esc_attr( $tab_id ); ?>"
					class="nav-tab<?php echo 'providers' === $tab_id ? ' nav-tab-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo 'providers' === $tab_id ? 'true' : 'false'; ?>"
					aria-controls="ai-seo-tab-<?php echo esc_attr( $tab_id ); ?>"
					data-tab="<?php echo esc_attr( $tab_id ); ?>"
					id="ai-seo-tab-btn-<?php echo esc_attr( $tab_id ); ?>"
				><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="ai-seo-filler-card ai-seo-filler-settings-panels">
			<div
				id="ai-seo-tab-providers"
				class="ai-seo-filler-tab-panel is-active"
				role="tabpanel"
				aria-labelledby="ai-seo-tab-btn-providers"
				data-tab="providers"
			>
				<h2 class="ai-seo-filler-tab-panel__title"><?php esc_html_e( 'AI Providers', 'ai-seo-filler' ); ?></h2>
				<p class="description ai-seo-filler-tab-panel__intro">
					<?php esc_html_e( 'Configure the service that generates SEO text. Only the selected provider panel is shown below.', 'ai-seo-filler' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai_seo_filler_ai_provider"><?php esc_html_e( 'AI Provider', 'ai-seo-filler' ); ?></label>
						</th>
						<td>
							<select id="ai_seo_filler_ai_provider" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_AI_PROVIDER ); ?>">
								<?php foreach ( $provider_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_provider, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Switch to Groq if Gemini is unavailable or overloaded.', 'ai-seo-filler' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="ai-seo-filler-provider-panel ai-seo-filler-provider-panel--gemini" data-provider="gemini">
					<h3><?php esc_html_e( 'Google Gemini', 'ai-seo-filler' ); ?></h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="ai_seo_filler_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'ai-seo-filler' ); ?></label>
							</th>
							<td>
								<?php
								$ai_seo_render_secret_field(
									array(
										'id'          => 'ai_seo_filler_gemini_api_key',
										'name'        => AI_SEO_FILLER_OPTION_API_KEY,
										'value'       => $gemini_key,
										'label'       => __( 'Gemini', 'ai-seo-filler' ),
										'placeholder' => Settings::has_api_key()
											? __( 'Paste a new key to replace the current one', 'ai-seo-filler' )
											: __( 'Enter your Google AI API key', 'ai-seo-filler' ),
										'help'        => __( 'Get a free API key at aistudio.google.com/apikey.', 'ai-seo-filler' ),
										'configured'  => Settings::has_api_key(),
										'status_id'   => 'ai-seo-filler-gemini-key-status',
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ai_seo_filler_gemini_model"><?php esc_html_e( 'Gemini Model', 'ai-seo-filler' ); ?></label>
							</th>
							<td>
								<select id="ai_seo_filler_gemini_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_GEMINI_MODEL ); ?>">
									<?php foreach ( $gemini_models as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_gemini, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="ai-seo-filler-provider-panel ai-seo-filler-provider-panel--groq" data-provider="groq">
					<h3><?php esc_html_e( 'Groq', 'ai-seo-filler' ); ?></h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="ai_seo_filler_groq_api_key"><?php esc_html_e( 'Groq API Key', 'ai-seo-filler' ); ?></label>
							</th>
							<td>
								<?php
								$ai_seo_render_secret_field(
									array(
										'id'          => 'ai_seo_filler_groq_api_key',
										'name'        => AI_SEO_FILLER_OPTION_GROQ_API_KEY,
										'value'       => $groq_key,
										'label'       => __( 'Groq', 'ai-seo-filler' ),
										'placeholder' => Settings::has_groq_api_key()
											? __( 'Paste a new key to replace the current one', 'ai-seo-filler' )
											: __( 'Enter your Groq API key (gsk_...)', 'ai-seo-filler' ),
										'help'        => __( 'Get a free API key at console.groq.com.', 'ai-seo-filler' ),
										'configured'  => Settings::has_groq_api_key(),
										'status_id'   => 'ai-seo-filler-groq-key-status',
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ai_seo_filler_groq_model"><?php esc_html_e( 'Groq Model', 'ai-seo-filler' ); ?></label>
							</th>
							<td>
								<select id="ai_seo_filler_groq_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_GROQ_MODEL ); ?>">
									<?php foreach ( $groq_models as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_groq, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Groq offers a generous free tier with fast inference.', 'ai-seo-filler' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ai-seo-filler-provider-panel ai-seo-filler-provider-panel--openai" data-provider="openai">
					<h3><?php esc_html_e( 'OpenAI', 'ai-seo-filler' ); ?></h3>
					<p class="description"><?php esc_html_e( 'The OpenAI API key is configured in the Images tab (shared for text and DALL·E).', 'ai-seo-filler' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ai_seo_filler_openai_model"><?php esc_html_e( 'OpenAI Model', 'ai-seo-filler' ); ?></label></th>
							<td>
								<select id="ai_seo_filler_openai_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_OPENAI_MODEL ); ?>">
									<?php foreach ( Settings::get_available_openai_models() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( Settings::get_openai_model(), $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<p class="ai-seo-filler-tab-panel__actions">
					<button type="button" class="button ai-seo-filler-test-api"><?php esc_html_e( 'Test API Connection', 'ai-seo-filler' ); ?></button>
					<span id="ai-seo-filler-test-result" class="description"></span>
				</p>
			</div>

			<div
				id="ai-seo-tab-images"
				class="ai-seo-filler-tab-panel"
				role="tabpanel"
				aria-labelledby="ai-seo-tab-btn-images"
				data-tab="images"
				hidden
			>
				<h2 class="ai-seo-filler-tab-panel__title"><?php esc_html_e( 'AI Image Generation', 'ai-seo-filler' ); ?></h2>
				<p class="description ai-seo-filler-tab-panel__intro">
					<?php esc_html_e( 'Separate from SEO text generation. Creates a featured image and gallery images for products, or a featured image for other content.', 'ai-seo-filler' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ai_seo_filler_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'ai-seo-filler' ); ?></label></th>
						<td>
							<?php
							ob_start();
							?>
							<p>
								<button type="button" class="button button-secondary ai-seo-filler-save-openai-key"><?php esc_html_e( 'Save OpenAI Key', 'ai-seo-filler' ); ?></button>
								<button type="button" class="button button-secondary ai-seo-filler-test-openai-key"><?php esc_html_e( 'Test OpenAI Key', 'ai-seo-filler' ); ?></button>
								<span id="ai-seo-filler-openai-key-result" class="description"></span>
							</p>
							<?php
							$openai_extra = ob_get_clean();
							$ai_seo_render_secret_field(
								array(
									'id'          => 'ai_seo_filler_openai_api_key',
									'name'        => Settings::OPENAI_SECRET_FIELD,
									'value'       => $openai_key,
									'label'       => __( 'OpenAI', 'ai-seo-filler' ),
									'placeholder' => Settings::has_openai_api_key()
										? __( 'Paste a new key to replace the current one', 'ai-seo-filler' )
										: 'sk-proj-...',
									'help'        => __( 'Required for DALL·E and when OpenAI is the text provider.', 'ai-seo-filler' ),
									'configured'  => Settings::has_openai_api_key(),
									'status_id'   => 'ai-seo-filler-openai-key-status',
									'extra_html'  => $openai_extra,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_seo_filler_image_provider"><?php esc_html_e( 'Image provider', 'ai-seo-filler' ); ?></label></th>
						<td>
							<select id="ai_seo_filler_image_provider" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_IMAGE_PROVIDER ); ?>">
								<?php foreach ( Settings::get_available_image_providers() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_option( AI_SEO_FILLER_OPTION_IMAGE_PROVIDER, 'auto' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Flux (Pollinations) is free and needs no API key. Gemini/OpenAI are optional fallbacks.', 'ai-seo-filler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_seo_filler_flux_model"><?php esc_html_e( 'Flux model', 'ai-seo-filler' ); ?></label></th>
						<td>
							<select id="ai_seo_filler_flux_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_FLUX_MODEL ); ?>">
								<?php foreach ( Settings::get_available_flux_models() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( Settings::get_flux_model(), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Uses Pollinations.ai. Free tier may be rate-limited; generating 4 product images can take a minute.', 'ai-seo-filler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_seo_filler_openai_image_model"><?php esc_html_e( 'OpenAI image model', 'ai-seo-filler' ); ?></label></th>
						<td>
							<input type="text" id="ai_seo_filler_openai_image_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_OPENAI_IMAGE_MODEL ); ?>" value="<?php echo esc_attr( Settings::get_openai_image_model() ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Use dall-e-3 for DALL·E. GPT Image models are handled automatically.', 'ai-seo-filler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_seo_filler_gemini_image_model"><?php esc_html_e( 'Gemini image model', 'ai-seo-filler' ); ?></label></th>
						<td>
							<select id="ai_seo_filler_gemini_image_model" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_GEMINI_IMAGE_MODEL ); ?>">
								<?php foreach ( Settings::get_available_gemini_image_models() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( Settings::get_gemini_image_model(), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Uses the Gemini generateContent API (not legacy Imagen predict).', 'ai-seo-filler' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div
				id="ai-seo-tab-generation"
				class="ai-seo-filler-tab-panel"
				role="tabpanel"
				aria-labelledby="ai-seo-tab-btn-generation"
				data-tab="generation"
				hidden
			>
				<h2 class="ai-seo-filler-tab-panel__title"><?php esc_html_e( 'Generation Behavior', 'ai-seo-filler' ); ?></h2>
				<p class="description ai-seo-filler-tab-panel__intro">
					<?php esc_html_e( 'Control what gets generated and how overwrites behave.', 'ai-seo-filler' ); ?>
				</p>

				<div class="ai-seo-filler-settings-grid">
					<?php
					$bool_opts = array(
						'preview_mode'    => __( 'Preview before applying', 'ai-seo-filler' ),
						'only_if_empty'   => __( 'Only fill empty SEO fields', 'ai-seo-filler' ),
						'revision_backup' => __( 'Create revision backup before overwrite', 'ai-seo-filler' ),
						'enable_fallback' => __( 'Fallback to other providers on failure', 'ai-seo-filler' ),
						'gen_meta'        => __( 'Generate meta title/description/keyword', 'ai-seo-filler' ),
						'gen_slug'        => __( 'Generate URL slug', 'ai-seo-filler' ),
						'gen_content'     => __( 'Rewrite post content (600+ words)', 'ai-seo-filler' ),
						'gen_short_desc'  => __( 'Generate short description (WooCommerce)', 'ai-seo-filler' ),
						'gen_image_alts'  => __( 'Generate image alt texts', 'ai-seo-filler' ),
					);
					foreach ( $bool_opts as $key => $label ) :
						$opt = AI_SEO_FILLER_OPTION_PREFIX . $key;
						?>
						<label>
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="1" <?php checked( Settings::is_enabled( $key ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ai_seo_filler_min_word_count"><?php esc_html_e( 'Minimum word count', 'ai-seo-filler' ); ?></label></th>
						<td><input type="number" min="300" max="3000" id="ai_seo_filler_min_word_count" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'min_word_count' ); ?>" value="<?php echo esc_attr( Settings::get_min_word_count() ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th><label for="ai_seo_filler_content_tone"><?php esc_html_e( 'Content tone', 'ai-seo-filler' ); ?></label></th>
						<td>
							<select id="ai_seo_filler_content_tone" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'content_tone' ); ?>">
								<?php foreach ( array( 'commercial' => __( 'Commercial', 'ai-seo-filler' ), 'technical' => __( 'Technical', 'ai-seo-filler' ), 'neutral' => __( 'Neutral', 'ai-seo-filler' ) ) as $val => $lbl ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( Settings::get_content_tone(), $val ); ?>><?php echo esc_html( $lbl ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ai_seo_filler_bulk_rate_limit"><?php esc_html_e( 'Bulk rate limit (seconds/item)', 'ai-seo-filler' ); ?></label></th>
						<td><input type="number" min="0" max="60" id="ai_seo_filler_bulk_rate_limit" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'bulk_rate_limit' ); ?>" value="<?php echo esc_attr( Settings::get_bulk_rate_limit() ); ?>" class="small-text" /></td>
					</tr>
				</table>
			</div>

			<div
				id="ai-seo-tab-types"
				class="ai-seo-filler-tab-panel"
				role="tabpanel"
				aria-labelledby="ai-seo-tab-btn-types"
				data-tab="types"
				hidden
			>
				<h2 class="ai-seo-filler-tab-panel__title"><?php esc_html_e( 'Content Types', 'ai-seo-filler' ); ?></h2>
				<p class="description ai-seo-filler-tab-panel__intro">
					<?php esc_html_e( 'Choose which post types can use AI SEO Filler (metabox, bulk processing, history, and Gutenberg sidebar).', 'ai-seo-filler' ); ?>
				</p>

				<input type="hidden" name="<?php echo esc_attr( $cpt_option_name ); ?>[]" value="" />
				<div class="ai-seo-filler-settings-grid ai-seo-filler-cpt-grid">
					<?php foreach ( $selectable_types as $slug => $label ) : ?>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $cpt_option_name ); ?>[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $enabled_types, true ) ); ?>
							/>
							<?php echo esc_html( $label ); ?>
							<code class="ai-seo-filler-cpt-slug"><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</div>
				<?php if ( empty( $selectable_types ) ) : ?>
					<p class="description"><?php esc_html_e( 'No selectable post types were found.', 'ai-seo-filler' ); ?></p>
				<?php endif; ?>
			</div>

			<div
				id="ai-seo-tab-general"
				class="ai-seo-filler-tab-panel"
				role="tabpanel"
				aria-labelledby="ai-seo-tab-btn-general"
				data-tab="general"
				hidden
			>
				<h2 class="ai-seo-filler-tab-panel__title"><?php esc_html_e( 'General', 'ai-seo-filler' ); ?></h2>
				<p class="description ai-seo-filler-tab-panel__intro">
					<?php esc_html_e( 'Language and SEO plugin integration.', 'ai-seo-filler' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ai_seo_filler_language"><?php esc_html_e( 'Content Language', 'ai-seo-filler' ); ?></label>
						</th>
						<td>
							<select
								id="ai_seo_filler_language"
								name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'language' ); ?>"
							>
								<?php foreach ( $content_languages as $locale => $label ) : ?>
									<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $current_lang, $locale ); ?>>
										<?php echo esc_html( $label . ' (' . $locale . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Language used by the AI for generated SEO content, descriptions, and image metadata.', 'ai-seo-filler' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ai_seo_filler_seo_plugin"><?php esc_html_e( 'SEO Plugin', 'ai-seo-filler' ); ?></label>
						</th>
						<td>
							<select id="ai_seo_filler_seo_plugin" name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin' ); ?>">
								<?php foreach ( $seo_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_seo, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose which SEO plugin receives the generated fields. Auto-detect is recommended.', 'ai-seo-filler' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="ai-seo-filler-settings-footer">
				<?php submit_button( __( 'Save Settings', 'ai-seo-filler' ), 'primary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Saves all tabs at once.', 'ai-seo-filler' ); ?></span>
			</div>
		</div>
	</form>
</div>

<script>
	window.aiSeoFillerProvider = <?php echo wp_json_encode( $current_provider ); ?>;
</script>
