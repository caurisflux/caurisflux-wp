<?php
/**
 * Lecture des réglages WooCommerce du gateway CaurisFlux sans instancier
 * la passerelle entière. Utilisé par Webhook, Admin_Notices, etc. pour
 * éviter le coût de chargement de WC_Payment_Gateway à chaque requête.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Settings {

	/**
	 * @var array<string,string>|null Cache mémoire.
	 */
	private static ?array $cache = null;

	public static function flush_cache(): void {
		self::$cache = null;
	}

	private static function all(): array {
		if ( null === self::$cache ) {
			$raw = get_option( CAURISFLUX_OPTIONS_KEY, array() );
			self::$cache = is_array( $raw ) ? $raw : array();
		}
		return self::$cache;
	}

	public static function get( string $key, string $default = '' ): string {
		$opts = self::all();
		return isset( $opts[ $key ] ) ? (string) $opts[ $key ] : $default;
	}

	public static function bool( string $key, bool $default = false ): bool {
		$value = self::get( $key, $default ? 'yes' : 'no' );
		return 'yes' === $value;
	}

	public static function is_enabled(): bool {
		return self::bool( 'enabled', false );
	}

	public static function environment(): string {
		$env = self::get( 'environment', 'sandbox' );
		return in_array( $env, array( 'live', 'sandbox' ), true ) ? $env : 'sandbox';
	}

	public static function api_key(): string {
		return trim( self::get( 'api_key', '' ) );
	}

	public static function webhook_secret(): string {
		return self::get( 'webhook_secret', '' );
	}

	public static function debug(): bool {
		return self::bool( 'debug', false );
	}

	/**
	 * Vérifie le format de la clé API (`pk_xxx:sk_xxx`).
	 *
	 * IMPORTANT: ne bloque PAS sur le mismatch env↔préfixe — c'est juste un
	 * warning séparé via {@see env_mismatch_warning()}. Le but est que le
	 * gateway reste disponible même si l'admin colle une clé sandbox dans
	 * un site live (ou inversement) et puisse voir le warning explicite.
	 *
	 * @return string Erreur lisible bloquante ou '' si OK.
	 */
	public static function validate_api_key(): string {
		$key = self::api_key();
		if ( '' === $key ) {
			return __( 'Clé API non renseignée.', 'caurisflux-for-woocommerce' );
		}
		$parts = explode( ':', $key );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return __( 'Format invalide. Attendu : pk_xxx:sk_xxx', 'caurisflux-for-woocommerce' );
		}
		[ $pk, $sk ] = $parts;
		if ( 0 !== stripos( $pk, 'pk_' ) ) {
			return __( 'La clé publique doit commencer par "pk_".', 'caurisflux-for-woocommerce' );
		}
		if ( 0 !== stripos( $sk, 'sk_' ) ) {
			return __( 'La clé secrète doit commencer par "sk_".', 'caurisflux-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Détecte un mismatch entre l'environnement sélectionné et le préfixe
	 * des clés (test/live). Non-bloquant — utilisé pour afficher un avertissement.
	 */
	public static function env_mismatch_warning(): string {
		$key = self::api_key();
		if ( '' === $key || false === strpos( $key, ':' ) ) {
			return '';
		}
		[ $pk, $sk ] = explode( ':', $key, 2 );
		$env = self::environment();
		$key_is_test = ( false !== stripos( $pk, 'pk_test_' ) ) || ( false !== stripos( $sk, 'sk_test_' ) );
		$key_is_live = ( false !== stripos( $pk, 'pk_live_' ) ) || ( false !== stripos( $sk, 'sk_live_' ) );

		if ( 'sandbox' === $env && $key_is_live ) {
			return __( 'Sandbox sélectionné mais clés "live" détectées. Utilisez pk_test_/sk_test_ ou passez en mode Production.', 'caurisflux-for-woocommerce' );
		}
		if ( 'live' === $env && $key_is_test ) {
			return __( 'Production sélectionnée mais clés "test" détectées. Utilisez pk_live_/sk_live_ ou passez en mode Sandbox.', 'caurisflux-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Renvoie un message si la config est incomplète, sinon ''.
	 * Inclut: clé API, devise non supportée, mismatch env, secret manquant en prod.
	 */
	public static function configuration_error(): string {
		if ( ! self::is_enabled() ) {
			return ''; // Désactivé volontairement.
		}
		$key_err = self::validate_api_key();
		if ( '' !== $key_err ) {
			return $key_err;
		}
		// Devise WC.
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$shop_currency = strtoupper( (string) get_woocommerce_currency() );
			if ( ! in_array( $shop_currency, self::supported_currencies(), true ) ) {
				return sprintf(
					/* translators: %1$s = currency code, %2$s = supported list */
					__( 'Devise WooCommerce "%1$s" non supportée par CaurisFlux. Devises acceptées : %2$s.', 'caurisflux-for-woocommerce' ),
					$shop_currency,
					implode( ', ', self::supported_currencies() )
				);
			}
		}
		// Mismatch env (warning prio inférieur).
		$mismatch = self::env_mismatch_warning();
		if ( '' !== $mismatch ) {
			return $mismatch;
		}
		if ( '' === self::webhook_secret() && 'sandbox' !== self::environment() ) {
			return __( 'Webhook secret manquant. Sans ce secret, les notifications de paiement seront rejetées en production.', 'caurisflux-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Devises supportées par CaurisFlux.
	 */
	public static function supported_currencies(): array {
		return array( 'XOF', 'XAF', 'GHS', 'NGN', 'EUR', 'USD' );
	}

	/**
	 * Vide le cache quand WooCommerce sauvegarde les options.
	 */
	public static function bind_cache_invalidation(): void {
		add_action(
			'woocommerce_update_options_payment_gateways_caurisflux',
			array( __CLASS__, 'flush_cache' )
		);
		add_action( 'update_option_' . CAURISFLUX_OPTIONS_KEY, array( __CLASS__, 'flush_cache' ) );
	}
}

CaurisFlux_Settings::bind_cache_invalidation();
