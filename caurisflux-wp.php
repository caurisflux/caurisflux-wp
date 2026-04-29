<?php
/**
 * Plugin Name: CaurisFlux for WooCommerce
 * Plugin URI:  https://docs.caurisflux.com/
 * Description: Acceptez les paiements Mobile Money (Wave, Orange Money, MTN, Free Money, Moov…) et Carte Bancaire via CaurisFlux. Multi-devises (XOF/XAF/GHS/NGN), checkout hébergé sécurisé, webhooks signés, mode sandbox.
 * Version:     1.0.0
 * Author:      Cauris Pay
 * Author URI:  https://caurisflux.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: caurisflux-wp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'CAURISFLUX_VERSION', '1.0.0' );
define( 'CAURISFLUX_PLUGIN_FILE', __FILE__ );
define( 'CAURISFLUX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAURISFLUX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CAURISFLUX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CAURISFLUX_MIN_PHP', '7.4' );
define( 'CAURISFLUX_MIN_WP', '6.0' );
define( 'CAURISFLUX_MIN_WC', '7.0' );
define( 'CAURISFLUX_API_VERSION', '2026-01-01' );
define( 'CAURISFLUX_OPTIONS_KEY', 'woocommerce_caurisflux_settings' );

// ---------------------------------------------------------------------------
// Autoloader (PSR-style mapped to includes/class-*.php)
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CaurisFlux_' ) ) {
			return;
		}
		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		$file_path = CAURISFLUX_PLUGIN_DIR . 'includes/' . $file_name;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

// ---------------------------------------------------------------------------
// Activation & deactivation
// ---------------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	static function () {
		// Check requirements before activation.
		if ( version_compare( PHP_VERSION, CAURISFLUX_MIN_PHP, '<' ) ) {
			deactivate_plugins( CAURISFLUX_PLUGIN_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %1$s = current PHP, %2$s = required PHP */
						__( 'CaurisFlux nécessite PHP %2$s ou supérieur. Version détectée: %1$s.', 'caurisflux-wp' ),
						PHP_VERSION,
						CAURISFLUX_MIN_PHP
					)
				)
			);
		}
		if ( version_compare( get_bloginfo( 'version' ), CAURISFLUX_MIN_WP, '<' ) ) {
			deactivate_plugins( CAURISFLUX_PLUGIN_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s = required WP version */
						__( 'CaurisFlux nécessite WordPress %s ou supérieur.', 'caurisflux-wp' ),
						CAURISFLUX_MIN_WP
					)
				)
			);
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
		// Clear health-check transient.
		delete_transient( 'caurisflux_health_status' );
	}
);

// ---------------------------------------------------------------------------
// HPOS (High-Performance Order Storage) compatibility declaration.
// ---------------------------------------------------------------------------
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				CAURISFLUX_PLUGIN_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------------
// Block Checkout (WooCommerce Blocks) integration
// ---------------------------------------------------------------------------
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new CaurisFlux_Blocks() );
			}
		);
	}
);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'caurisflux_init', 11 );

function caurisflux_init(): void {
	load_plugin_textdomain( 'caurisflux-wp', false, dirname( CAURISFLUX_PLUGIN_BASENAME ) . '/languages' );

	// Runtime PHP version safety net (in case host downgraded).
	if ( version_compare( PHP_VERSION, CAURISFLUX_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: %1$s = current PHP version, %2$s = required version */
						__( 'CaurisFlux désactivé : PHP %2$s+ requis (vous avez %1$s).', 'caurisflux-wp' ),
						PHP_VERSION,
						CAURISFLUX_MIN_PHP
					)
				);
				echo '</p></div>';
			}
		);
		return;
	}

	// WooCommerce required.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'CaurisFlux nécessite WooCommerce activé pour fonctionner.', 'caurisflux-wp' );
				echo '</p></div>';
			}
		);
		return;
	}

	// WC version check.
	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, CAURISFLUX_MIN_WC, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-warning"><p>';
				echo esc_html(
					sprintf(
						/* translators: %1$s = current WC version, %2$s = required version */
						__( 'CaurisFlux : WooCommerce %2$s+ recommandé (vous avez %1$s).', 'caurisflux-wp' ),
						WC_VERSION,
						CAURISFLUX_MIN_WC
					)
				);
				echo '</p></div>';
			}
		);
	}

	CaurisFlux_Plugin::instance();
}
