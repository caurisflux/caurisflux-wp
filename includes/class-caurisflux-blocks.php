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
		$label    = esc_js( __( 'CaurisFlux Mobile Money & Carte Bancaire', 'caurisflux-wp' ) );

		return <<<JS
(function(){
  if (!window.wc || !window.wc.wcBlocksRegistry) return;
  var settings = (window.wc.wcSettings && window.wc.wcSettings.getSetting)
    ? window.wc.wcSettings.getSetting('caurisflux_data', {}) : {};
  var title = settings.title || '{$label}';
  var description = settings.description || '';
  var icon = settings.icon || '{$icon_url}';

  var Label = function(){
    return wp.element.createElement('span', { className: 'cflux-block-label' },
      wp.element.createElement('img', { src: icon, alt: '', style: { width: 24, height: 24, marginRight: 8, verticalAlign: 'middle' } }),
      title
    );
  };
  var Content = function(){
    return wp.element.createElement('div', { className: 'cflux-block-description' }, description);
  };

  window.wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'caurisflux',
    label: wp.element.createElement(Label),
    content: wp.element.createElement(Content),
    edit:    wp.element.createElement(Content),
    canMakePayment: function(){ return true; },
    ariaLabel: title,
    supports: { features: ['products'] }
  });
})();
JS;
	}
}
