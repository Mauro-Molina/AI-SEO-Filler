<?php
/**
 * Central logging for AI SEO Filler.
 *
 * @package AiSeoFiller
 */

namespace AiSeoFiller;

defined( 'ABSPATH' ) || exit;

/**
 * Writes structured log entries to WooCommerce logger or error_log fallback.
 */
class Logger {

	const SOURCE = 'ai-seo-filler';

	/**
	 * Logs an informational message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Logs an error.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Writes a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function log( $level, $message, $context = array() ) {
		$entry = $message;

		if ( ! empty( $context ) ) {
			$entry .= ' ' . wp_json_encode( $context );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log( $level, $entry, array( 'source' => self::SOURCE ) );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[%s][%s] %s', self::SOURCE, strtoupper( $level ), $entry ) );
	}
}
