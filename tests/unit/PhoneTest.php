<?php
/**
 * Tests CaurisFlux_Phone::to_e164 — couverture mondiale.
 *
 * @package CaurisFlux\Tests
 */

use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase {

	/**
	 * @dataProvider provider_already_e164
	 */
	public function test_already_e164_passthrough( string $input, string $expected ): void {
		$this->assertSame( $expected, CaurisFlux_Phone::to_e164( $input ) );
	}

	public function provider_already_e164(): array {
		return array(
			'SN clean'             => array( '+221771234567', '+221771234567' ),
			'FR clean'             => array( '+33612345678', '+33612345678' ),
			'US clean'             => array( '+12025551234', '+12025551234' ),
			'with spaces'          => array( '+221 77 123 45 67', '+221771234567' ),
			'with parens & dashes' => array( '+1 (202) 555-1234', '+12025551234' ),
		);
	}

	/**
	 * @dataProvider provider_with_country
	 */
	public function test_normalize_with_country( string $input, string $iso2, string $expected ): void {
		$this->assertSame( $expected, CaurisFlux_Phone::to_e164( $input, $iso2 ) );
	}

	public function provider_with_country(): array {
		return array(
			'SN national'        => array( '771234567', 'SN', '+221771234567' ),
			'SN trunk-zero'      => array( '0771234567', 'SN', '+221771234567' ),
			'CI'                 => array( '0707123456', 'CI', '+225707123456' ),
			'CM'                 => array( '699735940', 'CM', '+237699735940' ),
			'NG'                 => array( '8026378383', 'NG', '+2348026378383' ),
			'GH'                 => array( '244123456', 'GH', '+233244123456' ),
			'KE'                 => array( '712345678', 'KE', '+254712345678' ),
			'FR national'        => array( '0612345678', 'FR', '+33612345678' ),
			'FR sans 0'          => array( '612345678', 'FR', '+33612345678' ),
			'GB'                 => array( '07911123456', 'GB', '+447911123456' ),
			'IN'                 => array( '9876543210', 'IN', '+919876543210' ),
			'BR'                 => array( '11987654321', 'BR', '+5511987654321' ),
			'JP'                 => array( '9012345678', 'JP', '+819012345678' ),
			'AU'                 => array( '412345678', 'AU', '+61412345678' ),
			'iso2 lowercase ok'  => array( '771234567', 'sn', '+221771234567' ),
		);
	}

	/**
	 * @dataProvider provider_invalid
	 */
	public function test_invalid_returns_empty( string $input, string $iso2 = '' ): void {
		$this->assertSame( '', CaurisFlux_Phone::to_e164( $input, $iso2 ) );
	}

	public function provider_invalid(): array {
		return array(
			'empty'              => array( '' ),
			'letters'            => array( 'abcdef' ),
			'too short E.164'    => array( '+33' ),
			'too long E.164'     => array( '+1234567890123456' ), // 16 chiffres
			'leading zero E.164' => array( '+0033612345678' ),
			'national no iso'    => array( '771234567' ), // sans ISO → trop court pour fallback
			'unknown iso'        => array( '771234567', 'XX' ),
		);
	}

	public function test_double_zero_international_prefix(): void {
		$this->assertSame( '+221771234567', CaurisFlux_Phone::to_e164( '00221771234567' ) );
		$this->assertSame( '+33612345678', CaurisFlux_Phone::to_e164( '0033612345678' ) );
	}

	public function test_dial_code_lookup(): void {
		$this->assertSame( '221', CaurisFlux_Phone::dial_code( 'SN' ) );
		$this->assertSame( '33', CaurisFlux_Phone::dial_code( 'FR' ) );
		$this->assertSame( '1', CaurisFlux_Phone::dial_code( 'US' ) );
		$this->assertSame( '237', CaurisFlux_Phone::dial_code( 'CM' ) );
		$this->assertSame( '', CaurisFlux_Phone::dial_code( 'XX' ) );
		$this->assertSame( '221', CaurisFlux_Phone::dial_code( 'sn' ), 'lowercase iso must work' );
	}

	public function test_e164_validator(): void {
		$this->assertTrue( CaurisFlux_Phone::is_valid_e164( '+221771234567' ) );
		$this->assertTrue( CaurisFlux_Phone::is_valid_e164( '+12025551234' ) );
		$this->assertFalse( CaurisFlux_Phone::is_valid_e164( '+0123456789' ), 'leading 0 after + invalide' );
		$this->assertFalse( CaurisFlux_Phone::is_valid_e164( '221771234567' ), 'manque +' );
		$this->assertFalse( CaurisFlux_Phone::is_valid_e164( '+12345' ), 'trop court' );
	}

	public function test_dial_codes_coverage(): void {
		$codes = CaurisFlux_Phone::all_dial_codes();
		$this->assertGreaterThan( 200, count( $codes ), 'Au moins 200 pays attendus' );
		$this->assertArrayHasKey( 'SN', $codes );
		$this->assertArrayHasKey( 'CI', $codes );
		$this->assertArrayHasKey( 'CM', $codes );
		$this->assertArrayHasKey( 'NG', $codes );
		$this->assertArrayHasKey( 'GH', $codes );
		$this->assertArrayHasKey( 'FR', $codes );
		$this->assertArrayHasKey( 'US', $codes );
		$this->assertArrayHasKey( 'IN', $codes );
		$this->assertArrayHasKey( 'JP', $codes );
		$this->assertArrayHasKey( 'BR', $codes );

		// Tous les indicatifs doivent être numériques.
		foreach ( $codes as $iso => $dial ) {
			$this->assertMatchesRegularExpression( '/^\d+$/', $dial, "dial $iso doit être numérique" );
		}
	}
}
