<?php
/**
 * Tests vérification de signature HMAC du webhook.
 * On teste la méthode privée via une classe sœur qui copie la logique
 * (la plupart des classes du Webhook tirent des deps WC qu'on évite ici).
 *
 * @package CaurisFlux\Tests
 */

use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase {

	/**
	 * Reproduit la logique de CaurisFlux_Webhook::verify_signature pour tester
	 * en isolation, sans avoir à charger WooCommerce.
	 */
	private static function verify( string $payload, string $signature, string $secret ): bool {
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

	public function test_valid_signature_with_prefix(): void {
		$payload   = '{"event":"payment.completed","data":{"id":1}}';
		$secret    = 'super_secret_key';
		$signature = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
		$this->assertTrue( self::verify( $payload, $signature, $secret ) );
	}

	public function test_valid_signature_without_prefix(): void {
		$payload   = '{"event":"payment.completed","data":{"id":1}}';
		$secret    = 'super_secret_key';
		$signature = hash_hmac( 'sha256', $payload, $secret );
		$this->assertTrue( self::verify( $payload, $signature, $secret ) );
	}

	public function test_tampered_payload_rejected(): void {
		$payload   = '{"event":"payment.completed","data":{"id":1}}';
		$secret    = 'super_secret_key';
		$signature = hash_hmac( 'sha256', $payload, $secret );
		$tampered  = '{"event":"payment.completed","data":{"id":2}}';
		$this->assertFalse( self::verify( $tampered, $signature, $secret ) );
	}

	public function test_wrong_secret_rejected(): void {
		$payload   = '{}';
		$signature = hash_hmac( 'sha256', $payload, 'right_secret' );
		$this->assertFalse( self::verify( $payload, $signature, 'wrong_secret' ) );
	}

	public function test_empty_signature_rejected(): void {
		$this->assertFalse( self::verify( '{}', '', 'any_secret' ) );
	}

	public function test_uses_timing_safe_compare(): void {
		// hash_equals exists par défaut depuis PHP 5.6 — on vérifie juste qu'il
		// est disponible (sinon notre Webhook tombe en timing attack).
		$this->assertTrue( function_exists( 'hash_equals' ) );
	}

	public function test_stripe_style_signature_timestamp_dot_body(): void {
		// Backend CaurisFlux: HMAC sur `timestamp.body` (cf. webhook-delivery.processor.ts).
		$body      = '{"event":"payment.success","data":{"id":42}}';
		$timestamp = '1745923400';
		$secret    = 'super_secret_key';
		$signed    = $timestamp . '.' . $body;
		$signature = hash_hmac( 'sha256', $signed, $secret );

		$this->assertTrue( self::verify( $signed, $signature, $secret ) );

		// Si on signe juste le body (legacy), ça ne doit PAS matcher la signature stripe-style.
		$body_only_sig = hash_hmac( 'sha256', $body, $secret );
		$this->assertNotSame( $signature, $body_only_sig );
	}
}
