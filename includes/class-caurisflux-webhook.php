<?php
/**
 * Endpoint REST pour recevoir les webhooks CaurisFlux et mettre à jour
 * les commandes WooCommerce.
 *
 * Sécurité :
 *  - Vérification HMAC SHA256 obligatoire en production (refus 401 si secret
 *    manquant ou signature invalide).
 *  - Protection contre les replay attacks via header X-Cauris-Timestamp
 *    avec tolérance de ±5 minutes.
 *  - Dédoublonnage par hash du body (transient 24h).
 *
 * Routes :
 *   POST /wp-json/caurisflux/v1/webhook
 *   POST /wp-json/caurisflux/v1/webhook/test  (admin uniquement, simule un event)
 *
 * Pour étendre les events traités, hooker sur :
 *   do_action('caurisflux_webhook_event_<event_name>', array $data, array $payload)
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Webhook {

	/**
	 * Headers utilisés par le backend CaurisFlux (cf. webhook-delivery.processor.ts).
	 * On accepte aussi les variantes legacy `X-Cauris-*` pour rétro-compat.
	 */
	private const SIGNATURE_HEADERS = array( 'X-Webhook-Signature', 'X-Cauris-Signature' );
	private const TIMESTAMP_HEADERS = array( 'X-Webhook-Timestamp', 'X-Cauris-Timestamp' );
	private const EVENT_HEADER      = 'X-Webhook-Event';
	private const DELIVERY_HEADER   = 'X-Webhook-Delivery-Id';
	private const NAMESPACE         = 'caurisflux/v1';
	private const TIMESTAMP_TOLERANCE_SECONDS = 300; // 5 min

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // auth via signature
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webhook/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_test' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);

		// Default event handlers — events réels émis par le backend CaurisFlux.
		add_action( 'caurisflux_webhook_event_payment_success', array( __CLASS__, 'on_payment_completed' ), 10, 2 );
		add_action( 'caurisflux_webhook_event_payment_completed', array( __CLASS__, 'on_payment_completed' ), 10, 2 ); // legacy
		add_action( 'caurisflux_webhook_event_payment_failed', array( __CLASS__, 'on_payment_failed' ), 10, 2 );
		add_action( 'caurisflux_webhook_event_payment_cancelled', array( __CLASS__, 'on_payment_failed' ), 10, 2 );
		add_action( 'caurisflux_webhook_event_payment_expired', array( __CLASS__, 'on_payment_failed' ), 10, 2 );
		add_action( 'caurisflux_webhook_event_refund_completed', array( __CLASS__, 'on_refund_completed' ), 10, 2 );
	}

	/**
	 * Lit le premier header non-vide parmi une liste (pour gérer les variantes
	 * `X-Webhook-Signature` vs `X-Cauris-Signature`).
	 */
	private static function read_first_header( WP_REST_Request $request, array $names ): string {
		foreach ( $names as $name ) {
			$value = (string) $request->get_header( $name );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * URL publique du webhook.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . '/webhook' );
	}

	/**
	 * Handler principal — vérifie signature, replay protection, idempotence
	 * puis dispatche l'event via une action WP filtrée.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body   = $request->get_body();
		$signature  = self::read_first_header( $request, self::SIGNATURE_HEADERS );
		$timestamp  = self::read_first_header( $request, self::TIMESTAMP_HEADERS );
		$secret     = CaurisFlux_Settings::webhook_secret();
		$is_sandbox = 'sandbox' === CaurisFlux_Settings::environment();

		// 1. Secret obligatoire (sandbox accepte aussi un secret vide pour faciliter
		// les tests, mais log un warning sévère).
		if ( '' === $secret ) {
			if ( ! $is_sandbox ) {
				CaurisFlux_Logger::error(
					'[Webhook] REJETÉ — webhook_secret non configuré en production. Configurez le secret HMAC.'
				);
				return new WP_REST_Response( array( 'error' => 'webhook_not_configured' ), 503 );
			}
			CaurisFlux_Logger::warning(
				'[Webhook] sandbox: webhook_secret vide, signature non vérifiée. Configurez-le avant la prod.'
			);
		}

		// 2. Vérification signature. Le backend CaurisFlux signe `timestamp.body`
		// (Stripe-style). Pour rétro-compat on accepte aussi un HMAC sur le
		// body seul (pour les anciens webhooks ou tests manuels).
		if ( '' !== $secret ) {
			$valid = self::verify_signature( $timestamp . '.' . $raw_body, $signature, $secret )
				|| self::verify_signature( $raw_body, $signature, $secret );
			if ( ! $valid ) {
				CaurisFlux_Logger::warning(
					'[Webhook] Signature invalide rejetée',
					array(
						'received_signature_length' => strlen( $signature ),
						'has_timestamp'             => '' !== $timestamp,
					)
				);
				return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
			}
		}

		// 3. Replay protection si timestamp fourni.
		if ( '' !== $timestamp ) {
			$ts = (int) $timestamp;
			if ( $ts <= 0 ) {
				return new WP_REST_Response( array( 'error' => 'invalid_timestamp' ), 400 );
			}
			$age = time() - $ts;
			if ( $age > self::TIMESTAMP_TOLERANCE_SECONDS || $age < -self::TIMESTAMP_TOLERANCE_SECONDS ) {
				CaurisFlux_Logger::warning(
					"[Webhook] Timestamp hors tolérance ($age s), rejet replay protection"
				);
				return new WP_REST_Response( array( 'error' => 'timestamp_out_of_window' ), 401 );
			}
		}

		// 4. Parse JSON.
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
		}

		$event = (string) ( $payload['event'] ?? '' );
		$data  = (array) ( $payload['data'] ?? array() );
		if ( '' === $event || empty( $data ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		// 5. Idempotence: hash du body, transient 24h.
		$dedup_key = 'caurisflux_evt_' . md5( $raw_body );
		if ( false !== get_transient( $dedup_key ) ) {
			CaurisFlux_Logger::info( "[Webhook] Doublon ignoré ($event)" );
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}
		set_transient( $dedup_key, '1', DAY_IN_SECONDS );

		CaurisFlux_Logger::info(
			"[Webhook] Reçu: $event",
			array( 'externalReference' => $data['externalReference'] ?? null )
		);

		// 6. Dispatch via hook WP — extensible et testable.
		$normalized_event = str_replace( '.', '_', $event );
		do_action( "caurisflux_webhook_event_{$normalized_event}", $data, $payload );
		do_action( 'caurisflux_webhook_event', $event, $data, $payload );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Endpoint de test admin (manage_woocommerce capability) — simule un event
	 * sans signature ni network. Permet de valider l'intégration côté WC.
	 */
	public static function handle_test( WP_REST_Request $request ): WP_REST_Response {
		$event   = (string) $request->get_param( 'event' );
		$order_id = (int) $request->get_param( 'order_id' );

		if ( '' === $event ) {
			return new WP_REST_Response( array( 'error' => 'event_required' ), 400 );
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'order_not_found' ), 404 );
		}

		$data = array(
			'externalReference'      => (string) $order->get_id(),
			'transactionId'          => 'TEST_' . wp_generate_uuid4(),
			'providerTransactionId'  => 'TEST_' . wp_generate_uuid4(),
			'provider'               => 'test_provider',
			'amount'                 => (float) $order->get_total(),
			'currency'               => $order->get_currency(),
			'failureReason'          => 'simulation',
			'failureMessage'         => __( 'Webhook simulé depuis l\'admin.', 'caurisflux-for-woocommerce' ),
		);

		$normalized_event = str_replace( '.', '_', $event );
		do_action(
			"caurisflux_webhook_event_{$normalized_event}",
			$data,
			array(
				'event' => $event,
				'data' => $data,
			)
		);

		return new WP_REST_Response(
			array(
				'status'   => 'simulated',
				'event'    => $event,
				'order_id' => $order->get_id(),
			),
			200
		);
	}

	/**
	 * Comparaison constante-temps entre la signature reçue et celle calculée.
	 */
	private static function verify_signature( string $payload, string $signature, string $secret ): bool {
		if ( '' === $signature ) {
			return false;
		}
		$received = $signature;
		if ( 0 === strpos( $received, 'sha256=' ) ) {
			$received = substr( $received, 7 );
		}
		$expected = hash_hmac( 'sha256', $payload, $secret );
		return hash_equals( $expected, $received );
	}

	// =====================================================================
	// Default event handlers
	// =====================================================================

	/**
	 * Marque la commande comme payée (event payment.success / payment.completed).
	 */
	public static function on_payment_completed( array $data, array $payload ): void {
		$order = self::resolve_order( $data );
		if ( ! $order ) {
			return;
		}
		if ( $order->is_paid() ) {
			CaurisFlux_Logger::debug( '[Webhook] Commande déjà payée, skip', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$tx_id    = (string) ( $data['transactionId'] ?? $data['providerTransactionId'] ?? '' );
		$provider = (string) ( $data['provider'] ?? '' );

		if ( '' !== $tx_id ) {
			$order->update_meta_data( '_caurisflux_transaction_id', $tx_id );
		}
		if ( '' !== $provider ) {
			$order->update_meta_data( '_caurisflux_provider', $provider );
		}

		$order->payment_complete( $tx_id );
		$order->add_order_note(
			sprintf(
				/* translators: %1$s = transaction id, %2$s = provider */
				__( 'Paiement CaurisFlux confirmé. Transaction : %1$s. Provider : %2$s.', 'caurisflux-for-woocommerce' ),
				$tx_id,
				$provider
			)
		);
		$order->save();
	}

	public static function on_payment_failed( array $data, array $payload ): void {
		$order = self::resolve_order( $data );
		if ( ! $order ) {
			return;
		}
		if ( $order->is_paid() ) {
			return; // Don't downgrade a paid order.
		}

		$event   = (string) ( $payload['event'] ?? 'payment.failed' );
		$reason  = (string) ( $data['failureReason'] ?? $data['errorCode'] ?? $event );
		$message = (string) ( $data['failureMessage'] ?? $data['errorMessage'] ?? '' );

		$order->update_status(
			'failed',
			sprintf(
				/* translators: %1$s = event, %2$s = reason, %3$s = message */
				__( 'CaurisFlux %1$s — raison : %2$s. %3$s', 'caurisflux-for-woocommerce' ),
				$event,
				$reason,
				$message
			)
		);
		$order->save();
	}

	public static function on_refund_completed( array $data, array $payload ): void {
		$order = self::resolve_order( $data );
		if ( ! $order ) {
			return;
		}
		$amount   = isset( $data['amount'] ) ? (float) $data['amount'] : 0;
		$currency = (string) ( $data['currency'] ?? $order->get_currency() );
		$reason   = (string) ( $data['reason'] ?? __( 'Remboursement CaurisFlux', 'caurisflux-for-woocommerce' ) );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s = amount, %2$s = currency, %3$s = reason */
				__( 'Remboursement CaurisFlux reçu : %1$s %2$s. Motif : %3$s', 'caurisflux-for-woocommerce' ),
				$amount,
				$currency,
				$reason
			)
		);
		$order->save();
	}

	/**
	 * Retrouve la commande WC à partir du payload :
	 *  - externalReference (= order ID stocké dans la transaction Cauris)
	 *  - puis fallback sur meta _caurisflux_transaction_id.
	 */
	private static function resolve_order( array $data ): ?WC_Order {
		$external_ref = (string) ( $data['externalReference'] ?? '' );
		if ( '' !== $external_ref && ctype_digit( $external_ref ) ) {
			$order = wc_get_order( (int) $external_ref );
			if ( $order ) {
				return $order;
			}
		}
		$tx_id = (string) ( $data['transactionId'] ?? $data['providerTransactionId'] ?? '' );
		if ( '' !== $tx_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => '_caurisflux_transaction_id',
					'meta_value' => $tx_id,
				)
			);
			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}
		CaurisFlux_Logger::warning(
			'[Webhook] Commande introuvable',
			array(
				'externalReference' => $external_ref,
				'transactionId'     => $tx_id,
			)
		);
		return null;
	}
}
