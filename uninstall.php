<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AiSeoFiller
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'ai_seo_filler_gemini_api_key',
	'ai_seo_filler_language',
	'ai_seo_filler_seo_plugin',
	'ai_seo_filler_gemini_model',
	'ai_seo_filler_ai_provider',
	'ai_seo_filler_groq_api_key',
	'ai_seo_filler_groq_model',
	'ai_seo_filler_openai_api_key',
	'ai_seo_filler_openai_model',
	'ai_seo_filler_bulk_queue',
	'ai_seo_filler_preview_mode',
	'ai_seo_filler_only_if_empty',
	'ai_seo_filler_revision_backup',
	'ai_seo_filler_enable_fallback',
	'ai_seo_filler_gen_meta',
	'ai_seo_filler_gen_slug',
	'ai_seo_filler_gen_content',
	'ai_seo_filler_gen_short_desc',
	'ai_seo_filler_gen_image_alts',
	'ai_seo_filler_min_word_count',
	'ai_seo_filler_content_tone',
	'ai_seo_filler_bulk_rate_limit',
	'ai_seo_filler_image_provider',
	'ai_seo_filler_openai_image_model',
	'ai_seo_filler_gemini_image_model',
	'ai_seo_filler_flux_model',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_queue' );
wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_batch' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ai_seo_filler_process_bulk_batch', array(), 'ai-seo-filler' );
}
