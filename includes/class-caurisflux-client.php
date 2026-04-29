<?php
/**
 * Client HTTP pour l'API CaurisFlux.
 *
 * Sans dépendance Composer — bâti sur wp_remote_*().
 * Features :
 *   - auth via X-API-Key
 *   - X-API-Version pour figer la surface
 *   - Idempotency-Key
 *   - Retry exponentiel sur 5xx / erreur réseau (3 tentatives max)
 *   - Sandbox/live URLs
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Client {

	private const TIMEOUT_SECONDS   = 30;
	private const USER_AGENT_PREFIX = 'CaurisFlux-WP/';
	private const ENDPOINT_LIVE     = 'https://prod-api.caurisflux.com/api/v1';
	private const ENDPOINT_SANDBOX  = 'https://sandbox-api.caurisflux.com/api/v1';
	private const MAX_RETRIES       = 3;
	private const BACKOFF_BASE_MS   = 500; // 500ms, 1s, 2s

	private string $api_key;
	private string $base_url;
	private string $env;

	public function __construct( string $api_key, string $env = 'live' ) {
		$this->api_key  = trim( $api_key );
		$this->env      = ( 'sandbox' === $env ) ? 'sandbox' : 'live';
		$this->base_url = ( 'sandbox' === $this->env ) ? self::ENDPOINT_SANDBOX : self::ENDPOINT_LIVE;
	}

	/**
	 * Construit un client à partir des réglages WP.
	 */
	public static function from_settings(): ?self {
		$key = CaurisFlux_Settings::api_key();
		if ( '' === $key || false === strpos( $key, ':' ) ) {
			return null;
		}
		return new self( $key, CaurisFlux_Settings::environment() );
	}

	public function get_base_url(): string {
		return $this->base_url;
	}

	public function get_environment(): string {
		return $this->env;
	}

	/**
	 * Health check API (HEAD ou GET sur /health). Retourne true si 2xx.
	 */
	public function ping(): bool {
		$url      = $this->base_url . '/health';
		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 400;
	}

	/**
	 * Initie un paiement (mode "checkout" hébergé par défaut).
	 *
	 * @return array{success:bool,data?:array,error?:string,status:int}
	 */
	public function initiate_payment( array $payload, string $idempotency_key ): array {
		$payload = array_merge( array( 'type' => 'checkout' ), $payload );
		return $this->request(
			'POST',
			'/payments/initiate',
			$payload,
			array( 'X-Idempotency-Key' => $idempotency_key )
		);
	}

	public function get_payment_status( string $transaction_id ): array {
		return $this->request( 'GET', '/payments/status/' . rawurlencode( $transaction_id ) );
	}

	/**
	 * Effectue une requête HTTP authentifiée avec retry exponentiel.
	 */
	private function request( string $method, string $path, ?array $body = null, array $extra_headers = array() ): array {
		if ( '' === $this->api_key || false === strpos( $this->api_key, ':' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Clé API CaurisFlux invalide ou non configurée.', 'caurisflux-wp' ),
				'status'  => 0,
			);
		}

		$url = $this->base_url . $path;

		$headers = array_merge(
			array(
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-API-Key'       => $this->api_key,
				'X-API-Version'   => CAURISFLUX_API_VERSION,
				'User-Agent'      => self::USER_AGENT_PREFIX . CAURISFLUX_VERSION . '; WP/' . get_bloginfo( 'version' ),
			),
			$extra_headers
		);

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::TIMEOUT_SECONDS,
		);

		if ( null !== $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'success' => false,
					'error'   => __( 'Impossible de sérialiser la requête en JSON.', 'caurisflux-wp' ),
					'status'  => 0,
				);
			}
			$args['body'] = $encoded;
		}

		CaurisFlux_Logger::debug( "[API] $method $path", array( 'body' => $body ) );

		$last_error  = '';
		$last_status = 0;
		$last_data   = array();

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error  = $response->get_error_message();
				$last_status = 0;
				CaurisFlux_Logger::warning(
					"[API] $method $path tentative $attempt échec réseau: $last_error"
				);
				if ( $attempt < self::MAX_RETRIES ) {
					usleep( $this->backoff_micros( $attempt ) );
					continue;
				}
				break;
			}

			$last_status = (int) wp_remote_retrieve_response_code( $response );
			$body_raw    = wp_remote_retrieve_body( $response );
			$last_data   = json_decode( $body_raw, true );
			if ( ! is_array( $last_data ) ) {
				$last_data = array();
			}

			CaurisFlux_Logger::debug( "[API] $method $path → $last_status", array( 'body' => $last_data ) );

			// Succès.
			if ( $last_status >= 200 && $last_status < 300 ) {
				return array(
					'success' => true,
					'data'    => $last_data,
					'status'  => $last_status,
				);
			}

			// 4xx = erreur définitive (validation, auth) — pas de retry.
			if ( $last_status >= 400 && $last_status < 500 ) {
				$last_error = $this->extract_error_message( $last_data );
				break;
			}

			// 5xx = retry.
			$last_error = $this->extract_error_message( $last_data );
			if ( $attempt < self::MAX_RETRIES ) {
				CaurisFlux_Logger::warning(
					"[API] $method $path tentative $attempt → $last_status, retry"
				);
				usleep( $this->backoff_micros( $attempt ) );
				continue;
			}
		}

		return array(
			'success' => false,
			'error'   => '' !== $last_error ? $last_error : __( 'Erreur API CaurisFlux', 'caurisflux-wp' ),
			'data'    => $last_data,
			'status'  => $last_status,
		);
	}

	private function extract_error_message( array $data ): string {
		if ( isset( $data['message'] ) ) {
			$msg = $data['message'];
			if ( is_array( $msg ) ) {
				return implode( ', ', array_filter( array_map( 'strval', $msg ) ) );
			}
			return (string) $msg;
		}
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}
		return '';
	}

	private function backoff_micros( int $attempt ): int {
		$ms = self::BACKOFF_BASE_MS * (int) pow( 2, $attempt - 1 );
		// Jitter ±20%.
		$jitter = (int) ( $ms * ( ( wp_rand( -200, 200 ) / 1000 ) ) );
		return ( $ms + $jitter ) * 1000;
	}
}
