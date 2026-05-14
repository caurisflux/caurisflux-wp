<?php
/**
 * Intégration WooCommerce Blocks (Block Checkout / Gutenberg).
 *
 * Sans cette classe, le gateway n'apparaît pas dans le block checkout activé
 * par défaut sur WP 6.5+.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
	return;
}

final class CaurisFlux_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/** @var string */
	protected $name = 'caurisflux';

	public function initialize(): void {
		// Rien à faire — settings lus à la volée via CaurisFlux_Settings.
	}

	public function is_active(): bool {
		if ( ! CaurisFlux_Settings::is_enabled() ) {
			return false;
		}
		return '' === CaurisFlux_Settings::validate_api_key();
	}

	public function get_payment_method_script_handles(): array {
		// Inline script enregistré sans build npm — registre la méthode côté React.
		$handle = 'caurisflux-blocks-integration';

		wp_register_script(
			$handle,
			'',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wc-blocks-registry' ),
			CAURISFLUX_VERSION,
			true
		);

		wp_add_inline_script( $handle, $this->build_inline_script() );
		return array( $handle );
	}

	public function get_payment_method_data(): array {
		$gateway = new CaurisFlux_Gateway();
		return array(
			'title'       => $gateway->title,
			'description' => $gateway->description,
			'icon'        => $gateway->icon,
			'supports'    => $gateway->supports,
		);
	}

	private function build_inline_script(): string {
		$icon_url = esc_url( CAURISFLUX_PLUGIN_URL . 'assets/images/logo.svg' );
		$label    = esc_js( __( 'CaurisFlux Mobile Money & Card', 'caurisflux-for-woocommerce' ) );

		return "(function(){\n"
			. "  if (!window.wc || !window.wc.wcBlocksRegistry) return;\n"
			. "  var settings = (window.wc.wcSettings && window.wc.wcSettings.getSetting)\n"
			. "    ? window.wc.wcSettings.getSetting('caurisflux_data', {}) : {};\n"
			. "  var title = settings.title || '" . $label . "';\n"
			. "  var description = settings.description || '';\n"
			. "  var icon = settings.icon || '" . $icon_url . "';\n"
			. "\n"
			. "  var Label = function(){\n"
			. "    return wp.element.createElement('span', { className: 'cflux-block-label' },\n"
			. "      wp.element.createElement('img', { src: icon, alt: '', className: 'cflux-block-icon' }),\n"
			. "      title\n"
			. "    );\n"
			. "  };\n"
			. "  var Content = function(){\n"
			. "    return wp.element.createElement('div', { className: 'cflux-block-description' }, description);\n"
			. "  };\n"
			. "\n"
			. "  window.wc.wcBlocksRegistry.registerPaymentMethod({\n"
			. "    name: 'caurisflux',\n"
			. "    label: wp.element.createElement(Label),\n"
			. "    content: wp.element.createElement(Content),\n"
			. "    edit:    wp.element.createElement(Content),\n"
			. "    canMakePayment: function(){ return true; },\n"
			. "    ariaLabel: title,\n"
			. "    supports: { features: ['products'] }\n"
			. "  });\n"
			. "})();";
	}
}
