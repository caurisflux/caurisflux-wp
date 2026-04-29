<?php
/**
 * Tests CaurisFlux_Settings — validation & lecture des options.
 *
 * @package CaurisFlux\Tests
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		// Reset state.
		$GLOBALS['cflux_options'] = array();
		CaurisFlux_Settings::flush_cache();
	}

	private function set_options( array $options ): void {
		update_option( CAURISFLUX_OPTIONS_KEY, $options );
		CaurisFlux_Settings::flush_cache();
	}

	public function test_defaults_when_no_options(): void {
		$this->assertFalse( CaurisFlux_Settings::is_enabled() );
		$this->assertSame( 'sandbox', CaurisFlux_Settings::environment() );
		$this->assertSame( '', CaurisFlux_Settings::api_key() );
		$this->assertSame( '', CaurisFlux_Settings::webhook_secret() );
		$this->assertFalse( CaurisFlux_Settings::debug() );
	}

	public function test_validate_api_key_empty(): void {
		$this->assertNotSame( '', CaurisFlux_Settings::validate_api_key() );
	}

	public function test_validate_api_key_malformed(): void {
		$this->set_options( array( 'api_key' => 'invalid-no-colon', 'environment' => 'sandbox' ) );
		$this->assertNotSame( '', CaurisFlux_Settings::validate_api_key() );

		$this->set_options( array( 'api_key' => 'pk_test_x:', 'environment' => 'sandbox' ) );
		$this->assertNotSame( '', CaurisFlux_Settings::validate_api_key() );
	}

	public function test_validate_api_key_sandbox_with_test_keys(): void {
		$this->set_options(
			array(
				'api_key'     => 'pk_test_abc:sk_test_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::validate_api_key() );
	}

	public function test_validate_api_key_format_only(): void {
		// Mismatch env n'est plus une erreur bloquante (juste un warning séparé).
		$this->set_options(
			array(
				'api_key'     => 'pk_live_abc:sk_live_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::validate_api_key() );

		$this->set_options(
			array(
				'api_key'     => 'pk_live_abc:sk_live_xyz',
				'environment' => 'live',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::validate_api_key() );
	}

	public function test_env_mismatch_warning(): void {
		$this->set_options(
			array(
				'api_key'     => 'pk_live_abc:sk_live_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertStringContainsString( 'Sandbox', CaurisFlux_Settings::env_mismatch_warning() );

		$this->set_options(
			array(
				'api_key'     => 'pk_test_abc:sk_test_xyz',
				'environment' => 'live',
			)
		);
		$this->assertStringContainsString( 'Production', CaurisFlux_Settings::env_mismatch_warning() );

		// Match correct → no warning.
		$this->set_options(
			array(
				'api_key'     => 'pk_test_abc:sk_test_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::env_mismatch_warning() );
	}

	public function test_validate_api_key_invalid_prefix(): void {
		$this->set_options(
			array(
				'api_key'     => 'foo_test_abc:sk_test_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertStringContainsString( 'pk_', CaurisFlux_Settings::validate_api_key() );

		$this->set_options(
			array(
				'api_key'     => 'pk_test_abc:bar_test_xyz',
				'environment' => 'sandbox',
			)
		);
		$this->assertStringContainsString( 'sk_', CaurisFlux_Settings::validate_api_key() );
	}

	public function test_supported_currencies(): void {
		$currencies = CaurisFlux_Settings::supported_currencies();
		$this->assertContains( 'XOF', $currencies );
		$this->assertContains( 'XAF', $currencies );
		$this->assertContains( 'EUR', $currencies );
		$this->assertContains( 'USD', $currencies );
		$this->assertNotContains( 'BTC', $currencies );
	}

	public function test_configuration_error_when_disabled_returns_empty(): void {
		$this->set_options(
			array(
				'enabled' => 'no',
				'api_key' => '',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::configuration_error() );
	}

	public function test_configuration_error_when_enabled_with_missing_key(): void {
		$this->set_options(
			array(
				'enabled'     => 'yes',
				'environment' => 'sandbox',
				'api_key'     => '',
			)
		);
		$this->assertNotSame( '', CaurisFlux_Settings::configuration_error() );
	}

	public function test_configuration_error_when_production_without_secret(): void {
		$this->set_options(
			array(
				'enabled'        => 'yes',
				'environment'    => 'live',
				'api_key'        => 'pk_live_abc:sk_live_xyz',
				'webhook_secret' => '',
			)
		);
		$err = CaurisFlux_Settings::configuration_error();
		$this->assertStringContainsString( 'Webhook secret', $err );
	}

	public function test_configuration_error_when_sandbox_without_secret_is_ok(): void {
		$this->set_options(
			array(
				'enabled'     => 'yes',
				'environment' => 'sandbox',
				'api_key'     => 'pk_test_abc:sk_test_xyz',
			)
		);
		$this->assertSame( '', CaurisFlux_Settings::configuration_error() );
	}

	public function test_environment_normalization(): void {
		$this->set_options( array( 'environment' => 'INVALID' ) );
		$this->assertSame( 'sandbox', CaurisFlux_Settings::environment() );
	}
}
