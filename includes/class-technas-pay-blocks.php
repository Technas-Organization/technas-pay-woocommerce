<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** Les deux moyens dans le paiement en blocs (page de paiement par défaut de WooCommerce 8.3+). */
final class Technas_Pay_Blocks extends AbstractPaymentMethodType {

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	private function gateway(): ?WC_Payment_Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways[ $this->name ] ?? null;
	}

	public function is_active(): bool {
		$gateway = $this->gateway();
		return $gateway !== null && $gateway->is_available();
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'technas-pay-blocks',
			plugins_url( 'assets/js/blocks.js', TECHNAS_PAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities' ),
			TECHNAS_PAY_WC_VERSION,
			true,
		);
		return array( 'technas-pay-blocks' );
	}

	public function get_payment_method_data(): array {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway?->get_title() ?? '',
			'description' => $gateway?->get_description() ?? '',
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array(),
		);
	}
}
