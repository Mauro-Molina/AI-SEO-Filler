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

$seo_plugin       = Core::detect_seo_plugin();
$seo_options      = array(
	'auto'     => __( 'Auto-detect', 'ai-seo-filler' ),
	'rankmath' => __( 'Rank Math', 'ai-seo-filler' ),
	'yoast'    => __( 'Yoast SEO', 'ai-seo-filler' ),
);
$provider_options = Settings::get_available_providers();

$detected_label = 'none' === $seo_plugin
	? __( 'None detected', 'ai-seo-filler' )
	: ( 'rankmath' === $seo_plugin ? 'Rank Math' : 'Yoast SEO' );

$current_provider = Settings::get_ai_provider();
$current_seo      = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'seo_plugin', 'auto' );
$current_lang     = get_option( AI_SEO_FILLER_OPTION_PREFIX . 'language', get_locale() );
$current_model    = Settings::get_active_model();
$gemini_models    = Settings::get_available_models();
$groq_models      = Settings::get_available_groq_models();
$current_gemini   = Settings::get_gemini_model();
$current_groq     = Settings::get_groq_model();
?>
<div class="wrap ai-seo-filler-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'ai_seo_filler_settings' ); ?>

	<div class="ai-seo-filler-card ai-seo-filler-status-card">
		<h2><?php esc_html_e( 'Status', 'ai-seo-filler' ); ?></h2>
		<ul class="ai-seo-filler-status-list">
			<li>
				<strong><?php esc_html_e( 'AI Provider:', 'ai-seo-filler' ); ?></strong>
				<?php echo esc_html( AI_Provider::get_active_provider_label() ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'API Key:', 'ai-seo-filler' ); ?></strong>
				<?php echo Settings::has_active_provider_configured()
					? '<span class="ai-seo-filler-badge ai-seo-filler-badge--ok">' . esc_html__( 'Configured', 'ai-seo-filler' ) . '</span>'
					: '<span class="ai-seo-filler-badge ai-seo-filler-badge--warn">' . esc_html__( 'Not configured', 'ai-seo-filler' ) . '</span>'; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'SEO Plugin:', 'ai-seo-filler' ); ?></strong>
				<?php echo esc_html( $detected_label ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Model:', 'ai-seo-filler' ); ?></strong>
				<code><?php echo esc_html( $current_model ); ?></code>
			</li>
		</ul>
	</div>

	<form method="post" action="options.php" class="ai-seo-filler-card">
		<?php
		settings_fields( 'ai_seo_filler_settings' );
		do_settings_sections( 'ai_seo_filler_settings' );
		?>

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
						<?php esc_html_e( 'Choose which AI service generates SEO fields. Switch to Groq if Gemini is unavailable or overloaded.', 'ai-seo-filler' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<div class="ai-seo-filler-provider-panel ai-seo-filler-provider-panel--gemini" data-provider="gemini">
			<h2><?php esc_html_e( 'Google Gemini', 'ai-seo-filler' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ai_seo_filler_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'ai-seo-filler' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="ai_seo_filler_gemini_api_key"
							name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_API_KEY ); ?>"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( Settings::has_api_key()
								? __( 'Key saved — paste a new key to replace it', 'ai-seo-filler' )
								: __( 'Enter your Google AI API key', 'ai-seo-filler' ) ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Get a free API key at aistudio.google.com/apikey. Leave blank to keep the current key.', 'ai-seo-filler' ); ?>
						</p>
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
			<h2><?php esc_html_e( 'Groq', 'ai-seo-filler' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ai_seo_filler_groq_api_key"><?php esc_html_e( 'Groq API Key', 'ai-seo-filler' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="ai_seo_filler_groq_api_key"
							name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_GROQ_API_KEY ); ?>"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( Settings::has_groq_api_key()
								? __( 'Key saved — paste a new key to replace it', 'ai-seo-filler' )
								: __( 'Enter your Groq API key (gsk_...)', 'ai-seo-filler' ) ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Get a free API key at console.groq.com. Leave blank to keep the current key.', 'ai-seo-filler' ); ?>
						</p>
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

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ai_seo_filler_language"><?php esc_html_e( 'Content Language', 'ai-seo-filler' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="ai_seo_filler_language"
						name="<?php echo esc_attr( AI_SEO_FILLER_OPTION_PREFIX . 'language' ); ?>"
						value="<?php echo esc_attr( $current_lang ); ?>"
						class="regular-text"
						placeholder="en_US"
					/>
					<p class="description">
						<?php esc_html_e( 'Locale code used to instruct the AI (e.g. en_US, es_ES). Defaults to your WordPress locale.', 'ai-seo-filler' ); ?>
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

		<?php submit_button( __( 'Save Settings', 'ai-seo-filler' ) ); ?>
	</form>
</div>

<script>
	window.aiSeoFillerProvider = <?php echo wp_json_encode( $current_provider ); ?>;
</script>
