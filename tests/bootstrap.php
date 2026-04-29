<?php
/**
 * Bootstrap PHPUnit — stubs minimaux WordPress pour permettre des tests unitaires
 * sans WP_TestCase ni base de données.
 *
 * @package CaurisFlux\Tests
 */

// Constantes WP utilisées par les classes du plugin.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'CAURISFLUX_VERSION' ) ) {
	define( 'CAURISFLUX_VERSION', '1.0.0' );
}
if ( ! defined( 'CAURISFLUX_API_VERSION' ) ) {
	define( 'CAURISFLUX_API_VERSION', '2026-01-01' );
}
if ( ! defined( 'CAURISFLUX_OPTIONS_KEY' ) ) {
	define( 'CAURISFLUX_OPTIONS_KEY', 'woocommerce_caurisflux_settings' );
}
if ( ! defined( 'CAURISFLUX_PLUGIN_DIR' ) ) {
	define( 'CAURISFLUX_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CAURISFLUX_PLUGIN_URL' ) ) {
	define( 'CAURISFLUX_PLUGIN_URL', 'https://example.test/wp-content/plugins/caurisflux-wp/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Storage pour stubs.
$GLOBALS['cflux_options']     = array();
$GLOBALS['cflux_actions']     = array();
$GLOBALS['cflux_filters']     = array();
$GLOBALS['cflux_transients']  = array();

// Stubs WordPress strictement minimaux pour les tests unit.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cflux_actions'][ $hook ][] = compact( 'callback', 'priority' );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cflux_filters'][ $hook ][] = compact( 'callback', 'priority' );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['cflux_actions'][ $hook ] ?? array() as $h ) {
			call_user_func_array( $h['callback'], $args );
		}
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['cflux_filters'][ $hook ] ?? array() as $h ) {
			$value = call_user_func_array( $h['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['cflux_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['cflux_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		$entry = $GLOBALS['cflux_transients'][ $key ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
			unset( $GLOBALS['cflux_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['cflux_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + (int) $ttl : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['cflux_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = null ) { echo $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = PHP_INT_MAX ) { return random_int( $min, $max ); }
}

// Charger les classes du plugin.
$includes = dirname( __DIR__ ) . '/includes/';
require_once $includes . 'class-caurisflux-logger.php';
require_once $includes . 'class-caurisflux-settings.php';
require_once $includes . 'class-caurisflux-phone.php';

// Webhook a besoin d'WP_REST_Request — on stub si absent.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $body = '';
		private $headers = array();
		private $params = array();
		public function __construct( $body = '', $headers = array(), $params = array() ) {
			$this->body = $body;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
			$this->params = $params;
		}
		public function get_body() { return $this->body; }
		public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? ''; }
		public function get_param( $name ) { return $this->params[ $name ] ?? null; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}
