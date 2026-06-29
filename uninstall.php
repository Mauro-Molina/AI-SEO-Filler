<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AiSeoFiller
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'ai_seo_filler_gemini_api_key' );
delete_option( 'ai_seo_filler_language' );
delete_option( 'ai_seo_filler_seo_plugin' );
delete_option( 'ai_seo_filler_gemini_model' );
delete_option( 'ai_seo_filler_ai_provider' );
delete_option( 'ai_seo_filler_groq_api_key' );
delete_option( 'ai_seo_filler_groq_model' );
delete_option( 'ai_seo_filler_bulk_queue' );

// Clear scheduled cron events.
$timestamp = wp_next_scheduled( 'ai_seo_filler_process_bulk_queue' );

if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'ai_seo_filler_process_bulk_queue' );
}

wp_clear_scheduled_hook( 'ai_seo_filler_process_bulk_queue' );
