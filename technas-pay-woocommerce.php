<?php
/**
 * Plugin Name:       Technas Pay for WooCommerce
 * Plugin URI:        https://github.com/Technas-Organization/technas-pay-woocommerce
 * Description:       Accept mobile money (Orange Money, MTN MoMo, Moov, Wave, Mixx…) and card payments with Technas Pay. 2% per successful payment, no subscription.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Technas
 * Author URI:        https://pay.technas.fr
 * License:           MIT
 * Text Domain:       technas-pay-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'TECHNAS_PAY_WC_VERSION', '0.1.0' );
define( 'TECHNAS_PAY_WC_FILE', __FILE__ );
define( 'TECHNAS_PAY_WC_DIR', __DIR__ );

require_once __DIR__ . '/vendor/autoload.php';

// Compatibilité déclarée : tables de commandes HPOS et paiement en blocs.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'technas-pay-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		if ( ! class_exists( \WC_Payment_Gateway::class ) ) {
			return; // WooCommerce absent ou désactivé : rien à brancher.
		}
		require_once __DIR__ . '/includes/class-technas-pay-wc.php';
		require_once __DIR__ . '/includes/class-technas-pay-gateway.php';
		require_once __DIR__ . '/includes/class-technas-pay-gateway-mobile-money.php';
		require_once __DIR__ . '/includes/class-technas-pay-gateway-card.php';
		require_once __DIR__ . '/includes/class-technas-pay-webhook.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = Technas_Pay_Gateway_Mobile_Money::class;
				$gateways[] = Technas_Pay_Gateway_Card::class;
				return $gateways;
			}
		);

		// L'adresse d'avis : /wp-json/technas-pay/v1/webhook (signature obligatoire).
		add_action( 'rest_api_init', array( Technas_Pay_Webhook::class, 'register_route' ) );
		// Ancienne adresse /?wc-api=technas_pay, gardée : même traitement, même signature exigée.
		add_action( 'woocommerce_api_technas_pay', array( Technas_Pay_Webhook::class, 'handle_request' ) );
	}
);

// Paiement en blocs (page de paiement par défaut de WooCommerce 8.3+).
add_action(
	'woocommerce_blocks_loaded',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once __DIR__ . '/includes/class-technas-pay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ): void {
				$registry->register( new Technas_Pay_Blocks( 'technas_pay_mobile_money' ) );
				$registry->register( new Technas_Pay_Blocks( 'technas_pay_card' ) );
			}
		);
	}
);
