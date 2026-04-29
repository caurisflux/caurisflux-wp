<?php
/**
 * Bootstrap singleton — branche les hooks WooCommerce + REST + admin.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Passerelle de paiement WooCommerce.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		// REST endpoints (webhook + test webhook).
		add_action( 'rest_api_init', array( CaurisFlux_Webhook::class, 'register_routes' ) );

		// Admin pieces (notices, metabox, order action, AJAX).
		if ( is_admin() ) {
			CaurisFlux_Admin_Notices::register();
			CaurisFlux_Order_Metabox::register();
		}

		// Lien "Réglages" dans la liste des plugins.
		add_filter( 'plugin_action_links_' . CAURISFLUX_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		// Inline CSS minimal pour la page settings — chargée strictement
		// sur ?page=wc-settings&tab=checkout&section=caurisflux pour éviter
		// toute fuite vers les autres pages admin.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_gateway( array $gateways ): array {
		$gateways[] = CaurisFlux_Gateway::class;
		return $gateways;
	}

	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=caurisflux' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Réglages', 'caurisflux-for-woocommerce' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Enqueue admin CSS uniquement sur :
	 *   - la page settings du gateway (?page=wc-settings&tab=checkout&section=caurisflux)
	 *   - la page de détail d'une commande WooCommerce (legacy + HPOS)
	 *
	 * Tous les sélecteurs CSS sont scopés (.cflux-*, ou wrappés par
	 * `.woocommerce_page_wc-settings`/`#caurisflux_order_metabox`) → aucune
	 * fuite possible vers le frontend ou les autres pages admin.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_settings = ( false !== strpos( $hook, 'wc-settings' ) )
			&& 'checkout' === ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' )
			&& 'caurisflux' === ( isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '' );

		$is_order_screen = $current_screen && in_array(
			$current_screen->id,
			array_filter(
				array(
					'shop_order',
					'edit-shop_order',
					function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '',
				)
			),
			true
		);

		if ( ! $is_settings && ! $is_order_screen ) {
			return;
		}

		wp_enqueue_style(
			'caurisflux-admin',
			CAURISFLUX_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CAURISFLUX_VERSION
		);
	}
}
