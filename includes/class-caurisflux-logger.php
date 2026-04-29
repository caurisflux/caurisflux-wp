<?php
/**
 * Wrapper sur wc_get_logger() — visible dans WooCommerce → Status → Logs.
 * Filtré par le réglage debug : info/warning/error toujours loggés,
 * debug seulement si le toggle est actif.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Logger {

	private const SOURCE = 'caurisflux';

	private const SENSITIVE_KEYS = array(
		'api_key',
		'apikey',
		'authorization',
		'sk_live',
		'sk_test',
		'pk_live',
		'pk_test',
		'cardData',
		'card_data',
		'number',
		'cvv',
		'cvc',
		'pin',
		'webhook_secret',
		'webhooksecret',
	);

	public static function debug( string $message, array $context = array() ): void {
		if ( ! CaurisFlux_Settings::debug() ) {
			return;
		}
		self::log( 'debug', $message, $context );
	}

	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	private static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}

		$payload = $message;
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( self::redact( $context ) );
			if ( false !== $encoded ) {
				$payload .= ' | ' . $encoded;
			}
		}

		$logger->log( $level, $payload, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Masque récursivement les valeurs des clés sensibles.
	 */
	private static function redact( array $data ): array {
		$redacted = array();
		foreach ( $data as $key => $value ) {
			$is_sensitive = false;
			foreach ( self::SENSITIVE_KEYS as $sk ) {
				if ( false !== stripos( (string) $key, $sk ) ) {
					$is_sensitive = true;
					break;
				}
			}
			if ( $is_sensitive ) {
				$redacted[ $key ] = '***MASKED***';
			} elseif ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}
		return $redacted;
	}
}
