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
			'Paiements Mobile Money & Carte Bancaire en Afrique francophone via CaurisFlux. Le client est redirigé vers une page de paiement sécurisée.',
			'caurisflux-for-woocommerce'
		);
		$this->has_fields         = false;
		$this->icon               = CAURISFLUX_PLUGIN_URL . 'assets/images/logo.svg';

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Mobile Money & Carte Bancaire', 'caurisflux-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Payez avec Wave, Orange Money, MTN, Free Money, Moov ou Carte Bancaire.', 'caurisflux-for-woocommerce' ) );
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
				'title'   => __( 'Activer / Désactiver', 'caurisflux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activer CaurisFlux pour les paiements', 'caurisflux-for-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Titre affiché au client', 'caurisflux-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Apparaît sur la page de checkout WooCommerce.', 'caurisflux-for-woocommerce' ),
				'default'     => __( 'Mobile Money & Carte Bancaire', 'caurisflux-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'caurisflux-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Texte court sous le titre.', 'caurisflux-for-woocommerce' ),
				'default'     => __( 'Payez avec Wave, Orange Money, MTN, Free Money, Moov ou Carte Bancaire.', 'caurisflux-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'environment'         => array(
				'title'       => __( 'Environnement', 'caurisflux-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'sandbox' => __( 'Sandbox (test)', 'caurisflux-for-woocommerce' ),
					'live'    => __( 'Production (live)', 'caurisflux-for-woocommerce' ),
				),
				'default'     => 'sandbox',
				'description' => __( 'Sandbox pour tester sans mouvements de fonds réels.', 'caurisflux-for-woocommerce' ),
			),
			'api_key'             => array(
				'title'       => __( 'Clé API CaurisFlux', 'caurisflux-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Format <code>pk_xxx:sk_xxx</code> (utilisez les clés <code>pk_test_</code>/<code>sk_test_</code> en sandbox).', 'caurisflux-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret'      => array(
				'title'       => __( 'Secret du webhook', 'caurisflux-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Secret HMAC utilisé pour vérifier l\'authenticité des notifications de paiement. <strong>Obligatoire en production.</strong>', 'caurisflux-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_url_display' => array(
				'title'       => __( 'URL de webhook', 'caurisflux-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s = webhook URL */
					__( 'À configurer dans votre dashboard CaurisFlux (Réglages → Webhooks) :<br><code>%s</code>', 'caurisflux-for-woocommerce' ),
					esc_html( $webhook_url )
				),
			),
			'test_webhook'        => array(
				'type' => 'caurisflux_test_webhook',
			),
			'auto_capture_phone'  => array(
				'title'   => __( 'Numéro de téléphone du client', 'caurisflux-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Pré-remplir le numéro depuis la commande WooCommerce', 'caurisflux-for-woocommerce' ),
				'default' => 'yes',
			),
			'debug'               => array(
				'title'       => __( 'Logs de debug', 'caurisflux-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activer les logs détaillés', 'caurisflux-for-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s = link to logs page */
					__( 'Logs visibles dans %s.', 'caurisflux-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">WooCommerce → Status → Logs</a>'
				),
			),
		);
	}

	/**
	 * Disponible si activé + clés API valides + devise du shop supportée.
	 *
	 * Pour faciliter le diagnostic, chaque rejet est loggué (debug only) avec
	 * la raison précise. Voir WooCommerce → Status → Logs → caurisflux-*.log.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			CaurisFlux_Logger::debug( '[Gateway] is_available=false: gateway désactivé dans les settings' );
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
				"[Gateway] is_available=false: devise WC '$shop_currency' non supportée. " .
				'Supportées: ' . implode( ',', CaurisFlux_Settings::supported_currencies() )
			);
			return false;
		}
		return parent::is_available();
	}

	public function get_webhook_secret(): string {
		return CaurisFlux_Settings::webhook_secret();
	}

	/**
	 * Affiche le bouton "Tester le webhook" dans la page settings.
	 */
	public function render_test_webhook_field(): void {
		$rest_url = rest_url( 'caurisflux/v1/webhook/test' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Tester l\'intégration', 'caurisflux-for-woocommerce' ); ?></th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="caurisflux-test-webhook">
					<?php esc_html_e( 'Envoyer un webhook de test', 'caurisflux-for-woocommerce' ); ?>
				</button>
				<span id="caurisflux-test-webhook-result" style="margin-left:10px;"></span>
				<p class="description">
					<?php esc_html_e( 'Saisissez l\'ID d\'une commande pending pour simuler un payment.completed.', 'caurisflux-for-woocommerce' ); ?>
				</p>
				<script>
				(function(){
					var btn = document.getElementById('caurisflux-test-webhook');
					if (!btn) return;
					btn.addEventListener('click', function(){
						var oid = window.prompt('<?php echo esc_js( __( 'ID de commande WooCommerce :', 'caurisflux-for-woocommerce' ) ); ?>');
						if (!oid) return;
						var event = window.prompt('<?php echo esc_js( __( 'Event à simuler :', 'caurisflux-for-woocommerce' ) ); ?>', 'payment.completed');
						if (!event) return;
						btn.disabled = true;
						var status = document.getElementById('caurisflux-test-webhook-result');
						status.textContent = '<?php echo esc_js( __( 'Envoi en cours…', 'caurisflux-for-woocommerce' ) ); ?>';
						fetch('<?php echo esc_url_raw( $rest_url ); ?>', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( $nonce ); ?>' },
							body: JSON.stringify({ event: event, order_id: parseInt(oid, 10) })
						}).then(function(r){ return r.json().then(function(j){ return [r.status, j]; }); })
						.then(function(arr){
							btn.disabled = false;
							status.textContent = arr[0] + ': ' + JSON.stringify(arr[1]);
						})
						.catch(function(e){
							btn.disabled = false;
							status.textContent = 'Error: ' + e.message;
						});
					});
				})();
				</script>
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
			wc_add_notice( __( 'Commande introuvable.', 'caurisflux-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = $this->client_override ?? CaurisFlux_Client::from_settings();
		if ( ! $client ) {
			wc_add_notice( __( 'Configuration CaurisFlux invalide. Contactez le marchand.', 'caurisflux-for-woocommerce' ), 'error' );
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
				/* translators: %1$s = order number, %2$s = blog name */
				__( 'Commande #%1$s — %2$s', 'caurisflux-for-woocommerce' ),
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
				'[Gateway] Échec initiation paiement',
				array(
					'order_id' => $order->get_id(),
					'status'   => $response['status'],
					'error'    => $response['error'] ?? 'unknown',
				)
			);
			wc_add_notice(
				sprintf(
					/* translators: %s = error message from API */
					__( 'Échec initialisation du paiement : %s', 'caurisflux-for-woocommerce' ),
					$response['error'] ?? __( 'erreur inconnue', 'caurisflux-for-woocommerce' )
				),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$payload_data   = $response['data']['data'] ?? $response['data'];
		$checkout_url   = (string) ( $payload_data['redirectUrl'] ?? $payload_data['checkoutUrl'] ?? $payload_data['paymentUrl'] ?? '' );
		$transaction_id = (string) ( $payload_data['transactionId'] ?? $payload_data['providerTransactionId'] ?? '' );

		if ( '' === $checkout_url ) {
			CaurisFlux_Logger::error( '[Gateway] redirectUrl manquant dans la réponse API', array( 'response' => $payload_data ) );
			wc_add_notice( __( 'Réponse CaurisFlux invalide (URL de checkout manquante).', 'caurisflux-for-woocommerce' ), 'error' );
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
				__( 'Paiement CaurisFlux initié. Transaction : %s', 'caurisflux-for-woocommerce' ),
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
