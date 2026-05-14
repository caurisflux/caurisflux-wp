<?php
/**
 * Passerelle de paiement WooCommerce CaurisFlux.
 *
 * Mode "checkout hébergé" — redirection vers une page CaurisFlux multi-provider
 * et multi-devise. PCI hors scope côté marchand.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Gateway extends WC_Payment_Gateway {

	/**
	 * Permet d'injecter un client custom (testabilité).
	 *
	 * @var CaurisFlux_Client|null
	 */
	private ?CaurisFlux_Client $client_override = null;

	public function __construct() {
		$this->id                 = 'caurisflux';
		$this->method_title       = __( 'CaurisFlux', 'caurisflux-for-woocommerce' );
		$this->method_description = __(
			'Mobile Money and Card payments through CaurisFlux. Customers are redirected to a secure hosted payment page.',
			'caurisflux-for-woocommerce'
		);
		$this->has_fields         = false;
		$this->icon               = CAURISFLUX_PLUGIN_URL . 'assets/images/logo.svg';

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Mobile Money & Card', 'caurisflux-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with Wave, Orange Money, MTN, Free Money, Moov or Card.', 'caurisflux-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Bouton "Test webhook" custom field (rendu côté admin uniquement).
		add_action( 'woocommerce_admin_field_caurisflux_test_webhook', array( $this, 'render_test_webhook_field' ) );
	}

	/** Injection (tests). */
	public function set_client( CaurisFlux_Client $client ): void {
		$this->client_override = $client;
	}

	public function init_form_fields(): void {
		$webhook_url = CaurisFlux_Webhook::url();

		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable / Disable', 'caurisflux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CaurisFlux for payments', 'caurisflux-for-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title shown to customers', 'caurisflux-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Appears on the WooCommerce checkout page.', 'caurisflux-for-woocommerce' ),
				'default'     => __( 'Mobile Money & Card', 'caurisflux-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'caurisflux-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Short text shown under the title.', 'caurisflux-for-woocommerce' ),
				'default'     => __( 'Pay with Wave, Orange Money, MTN, Free Money, Moov or Card.', 'caurisflux-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'environment'         => array(
				'title'       => __( 'Environment', 'caurisflux-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'sandbox' => __( 'Sandbox (test)', 'caurisflux-for-woocommerce' ),
					'live'    => __( 'Production (live)', 'caurisflux-for-woocommerce' ),
				),
				'default'     => 'sandbox',
				'description' => __( 'Use sandbox to test without moving real funds.', 'caurisflux-for-woocommerce' ),
			),
			'api_key'             => array(
				'title'       => __( 'CaurisFlux API key', 'caurisflux-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Format <code>pk_xxx:sk_xxx</code> (use <code>pk_test_</code>/<code>sk_test_</code> keys in sandbox).', 'caurisflux-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret'      => array(
				'title'       => __( 'Webhook secret', 'caurisflux-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'HMAC secret used to verify the authenticity of payment notifications. <strong>Required.</strong>', 'caurisflux-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_url_display' => array(
				'title'       => __( 'Webhook URL', 'caurisflux-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s = webhook URL */
					__( 'Configure this URL in your CaurisFlux dashboard (Settings → Webhooks):<br><code>%s</code>', 'caurisflux-for-woocommerce' ),
					esc_html( $webhook_url )
				),
			),
			'test_webhook'        => array(
				'type' => 'caurisflux_test_webhook',
			),
			'auto_capture_phone'  => array(
				'title'   => __( 'Customer phone number', 'caurisflux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Pre-fill the phone number from the WooCommerce order', 'caurisflux-for-woocommerce' ),
				'default' => 'yes',
			),
			'debug'               => array(
				'title'       => __( 'Debug logs', 'caurisflux-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable detailed logging', 'caurisflux-for-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s = link to the WooCommerce logs page */
					__( 'Logs are visible in %s.', 'caurisflux-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">WooCommerce → Status → Logs</a>'
				),
			),
		);
	}

	/**
	 * Available when enabled + API key valid + shop currency supported.
	 *
	 * To help diagnostics, every rejection is logged (debug only) with the
	 * precise reason. See WooCommerce → Status → Logs → caurisflux-*.log.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			CaurisFlux_Logger::debug( '[Gateway] is_available=false: gateway disabled in settings' );
			return false;
		}
		$api_err = CaurisFlux_Settings::validate_api_key();
		if ( '' !== $api_err ) {
			CaurisFlux_Logger::debug( '[Gateway] is_available=false: ' . $api_err );
			return false;
		}
		$shop_currency = strtoupper( (string) get_woocommerce_currency() );
		if ( ! in_array( $shop_currency, CaurisFlux_Settings::supported_currencies(), true ) ) {
			CaurisFlux_Logger::debug(
				"[Gateway] is_available=false: WC currency '$shop_currency' not supported. " .
				'Supported: ' . implode( ',', CaurisFlux_Settings::supported_currencies() )
			);
			return false;
		}
		return parent::is_available();
	}

	public function get_webhook_secret(): string {
		return CaurisFlux_Settings::webhook_secret();
	}

	/**
	 * Override the default icon markup so we can attach a CSS class and let
	 * the scoped frontend stylesheet constrain its size. Without this, the
	 * SVG renders at its intrinsic 64×32 dimensions and can stretch the
	 * payment-method row in some themes.
	 */
	public function get_icon(): string {
		if ( ! $this->icon ) {
			return apply_filters( 'woocommerce_gateway_icon', '', $this->id );
		}

		$icon = sprintf(
			'<img src="%1$s" alt="%2$s" class="caurisflux-gateway-icon" />',
			esc_url( WC_HTTPS::force_https_url( $this->icon ) ),
			esc_attr( $this->get_title() )
		);

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Renders the "Test webhook" button on the gateway settings page.
	 * The associated JS is enqueued via {@see CaurisFlux_Plugin::enqueue_admin_assets()}.
	 */
	public function render_test_webhook_field(): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Test the integration', 'caurisflux-for-woocommerce' ); ?></th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="caurisflux-test-webhook">
					<?php esc_html_e( 'Send a test webhook', 'caurisflux-for-woocommerce' ); ?>
				</button>
				<span id="caurisflux-test-webhook-result" style="margin-left:10px;"></span>
				<p class="description">
					<?php esc_html_e( 'Enter a pending order ID to simulate a payment.completed event.', 'caurisflux-for-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param int|string $order_id
	 * @return array{result:string, redirect?:string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'caurisflux-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = $this->client_override ?? CaurisFlux_Client::from_settings();
		if ( ! $client ) {
			wc_add_notice( __( 'CaurisFlux is not configured correctly. Please contact the merchant.', 'caurisflux-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$customer_phone = '';
		if ( CaurisFlux_Settings::bool( 'auto_capture_phone', true ) ) {
			$customer_phone = CaurisFlux_Phone::to_e164(
				(string) $order->get_billing_phone(),
				(string) $order->get_billing_country()
			);
		}

		$payload = array(
			'amount'            => (float) $order->get_total(),
			'currency'          => $order->get_currency(),
			'externalReference' => (string) $order->get_id(),
			'description'       => sprintf(
				/* translators: %1$s = order number, %2$s = site name */
				__( 'Order #%1$s — %2$s', 'caurisflux-for-woocommerce' ),
				$order->get_order_number(),
				get_bloginfo( 'name' )
			),
			'customerPhone'     => $customer_phone,
			'customerName'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customerEmail'     => (string) $order->get_billing_email(),
			'callbackUrl'       => CaurisFlux_Webhook::url(),
			'returnUrl'         => $this->get_return_url( $order ),
			'cancelUrl'         => $order->get_cancel_order_url_raw(),
			'metadata'          => array(
				'wc_order_id'  => $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
			),
		);

		$idempotency_key = 'wc_order_' . $order->get_id() . '_' . $order->get_order_key();

		$response = $client->initiate_payment( $payload, $idempotency_key );

		if ( ! $response['success'] ) {
			CaurisFlux_Logger::error(
				'[Gateway] Payment initiation failed',
				array(
					'order_id' => $order->get_id(),
					'status'   => $response['status'],
					'error'    => $response['error'] ?? 'unknown',
				)
			);
			wc_add_notice(
				sprintf(
					/* translators: %s = error message from API */
					__( 'Failed to initiate payment: %s', 'caurisflux-for-woocommerce' ),
					$response['error'] ?? __( 'unknown error', 'caurisflux-for-woocommerce' )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$payload_data   = $response['data']['data'] ?? $response['data'];
		$checkout_url   = (string) ( $payload_data['redirectUrl'] ?? $payload_data['checkoutUrl'] ?? $payload_data['paymentUrl'] ?? '' );
		$transaction_id = (string) ( $payload_data['transactionId'] ?? $payload_data['providerTransactionId'] ?? '' );

		if ( '' === $checkout_url ) {
			CaurisFlux_Logger::error( '[Gateway] redirectUrl missing from API response', array( 'response' => $payload_data ) );
			wc_add_notice( __( 'Invalid CaurisFlux response (missing checkout URL).', 'caurisflux-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( '' !== $transaction_id ) {
			$order->update_meta_data( '_caurisflux_transaction_id', $transaction_id );
		}
		$order->update_meta_data( '_caurisflux_environment', CaurisFlux_Settings::environment() );
		$order->set_payment_method( $this->id );
		$order->set_payment_method_title( $this->method_title );
		$order->update_status(
			'pending',
			sprintf(
				/* translators: %s = transaction id */
				__( 'CaurisFlux payment initiated. Transaction: %s', 'caurisflux-for-woocommerce' ),
				$transaction_id
			)
		);
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}
}
