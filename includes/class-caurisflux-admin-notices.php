<?php
/**
 * Notices admin pour signaler les misconfigurations du plugin.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Admin_Notices {

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		// Skip sur les pages où l'on configure justement le gateway.
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $current_screen && 'woocommerce_page_wc-settings' === $current_screen->id ) {
			return;
		}

		// Restriction aux utilisateurs qui peuvent configurer.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled = CaurisFlux_Settings::is_enabled();
		if ( ! $enabled ) {
			return; // Pas de notice si le plugin est désactivé volontairement.
		}

		$config_error = CaurisFlux_Settings::configuration_error();
		if ( '' !== $config_error ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=caurisflux' );
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>CaurisFlux: </strong>';
			echo esc_html( $config_error );
			echo ' &nbsp; <a href="' . esc_url( $settings_url ) . '">';
			echo esc_html__( 'Fix now', 'caurisflux-for-woocommerce' );
			echo '</a></p></div>';
			return;
		}

		// API health check (transient 1h).
		$health = get_transient( 'caurisflux_health_status' );
		if ( false === $health ) {
			$client = CaurisFlux_Client::from_settings();
			$health = ( $client && $client->ping() ) ? 'ok' : 'down';
			set_transient( 'caurisflux_health_status', $health, HOUR_IN_SECONDS );
		}
		if ( 'down' === $health ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>CaurisFlux: </strong>';
			echo esc_html__( 'The CaurisFlux API appears to be unavailable. Payments may fail. Check status.caurisflux.com.', 'caurisflux-for-woocommerce' );
			echo '</p></div>';
		}
	}
}
