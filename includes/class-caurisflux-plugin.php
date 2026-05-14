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

		// Scoped frontend CSS — only on cart / checkout / order-pay so we
		// constrain our own gateway icon and never leak to other inputs.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	public function register_gateway( array $gateways ): array {
		$gateways[] = CaurisFlux_Gateway::class;
		return $gateways;
	}

	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=caurisflux' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'caurisflux-for-woocommerce' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Enqueue admin assets only on:
	 *   - the gateway settings page (?page=wc-settings&tab=checkout&section=caurisflux)
	 *   - the WooCommerce order detail page (legacy + HPOS)
	 *
	 * All CSS selectors are scoped (.cflux-*, or wrapped by
	 * `.woocommerce_page_wc-settings`/`#caurisflux_order_metabox`) — no leak
	 * to the frontend or other admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$is_settings = ( false !== strpos( $hook, 'wc-settings' ) )
			&& 'checkout' === $tab
			&& 'caurisflux' === $section;

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

		if ( $is_settings ) {
			wp_enqueue_script(
				'caurisflux-admin-test-webhook',
				CAURISFLUX_PLUGIN_URL . 'assets/js/admin-test-webhook.js',
				array(),
				CAURISFLUX_VERSION,
				true
			);
			wp_localize_script(
				'caurisflux-admin-test-webhook',
				'CaurisFluxTestWebhook',
				array(
					'restUrl' => esc_url_raw( rest_url( 'caurisflux/v1/webhook/test' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'orderIdPrompt' => __( 'WooCommerce order ID:', 'caurisflux-for-woocommerce' ),
						'eventPrompt'   => __( 'Event to simulate:', 'caurisflux-for-woocommerce' ),
						'sending'       => __( 'Sending…', 'caurisflux-for-woocommerce' ),
					),
				)
			);
		}

		if ( $is_order_screen ) {
			wp_enqueue_script(
				'caurisflux-admin-order-metabox',
				CAURISFLUX_PLUGIN_URL . 'assets/js/admin-order-metabox.js',
				array(),
				CAURISFLUX_VERSION,
				true
			);
			wp_localize_script(
				'caurisflux-admin-order-metabox',
				'CaurisFluxOrderMetabox',
				array(
					'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'i18n'    => array(
						'checking' => __( 'Checking…', 'caurisflux-for-woocommerce' ),
						'ok'       => __( 'OK', 'caurisflux-for-woocommerce' ),
						'error'    => __( 'Error', 'caurisflux-for-woocommerce' ),
					),
				)
			);
		}
	}

	/**
	 * Enqueue the scoped frontend stylesheet on the pages where our gateway
	 * may actually render (cart, checkout, order-pay, order-received). Every
	 * selector in the file is scoped to the CaurisFlux gateway, so loading
	 * the file is safe — but we still limit it to the pages that need it.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}

		if ( ! ( is_checkout() || is_cart() || is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}

		if ( ! CaurisFlux_Settings::is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'caurisflux-frontend',
			CAURISFLUX_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			CAURISFLUX_VERSION
		);
	}
}
