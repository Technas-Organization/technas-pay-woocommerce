<?php

defined( 'ABSPATH' ) || exit;

use TechnasPay\TechnasPayException;

/**
 * Base des deux moyens de paiement Technas Pay. Le client paie sur la page Technas (opérateur,
 * pays, carte) ; la commande n'est marquée payée QUE sur l'avis signé (`Technas_Pay_Webhook`),
 * jamais au retour du navigateur.
 */
abstract class Technas_Pay_Gateway extends WC_Payment_Gateway {

	/** `mobile_money` ou `card`. */
	abstract protected function technas_method(): string;

	public function __construct() {
		$this->has_fields = false;
		$this->supports   = array( 'products', 'refunds' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title' );
		if ( Technas_Pay_WC::is_configured() && Technas_Pay_WC::is_test_mode() ) {
			/* translators: %s: payment method title */
			$this->title = sprintf( __( '%s (TEST, no real payment)', 'technas-pay-woocommerce' ), $this->title );
		}
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Champs communs : activer, titre, description. */
	protected function base_form_fields( string $title, string $description ): array {
		return array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'technas-pay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => sprintf( /* translators: %s: payment method title */ __( 'Enable %s', 'technas-pay-woocommerce' ), $title ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'technas-pay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'technas-pay-woocommerce' ),
				'default'     => $title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'technas-pay-woocommerce' ),
				'type'    => 'textarea',
				'default' => $description,
			),
		);
	}

	public function is_available(): bool {
		return parent::is_available() && Technas_Pay_WC::is_configured();
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'technas-pay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		try {
			$payment = Technas_Pay_WC::start_payment( $order, $this->technas_method(), $this->get_return_url( $order ) );
		} catch ( TechnasPayException $e ) {
			if ( $e->errorCode === 'kyc_required' ) {
				// Seuil d'identité atteint : pour le marchand, une note de journal ; le client voit un
				// message neutre. L'état gardé est relu au prochain affichage de l'administration.
				delete_transient( Technas_Pay_WC::KYC_TRANSIENT );
				wc_get_logger()->warning(
					sprintf(
						'Technas Pay: mobile money refused for order %1$s (kyc_required, reason %2$s). The account holder must upload their ID in the Technas Pay console: %3$s',
						$order->get_id(),
						$e->details['reason'] ?? '?',
						$e->details['consoleUrl'] ?? '',
					),
					array( 'source' => 'technas-pay' )
				);
			}
			$order->add_order_note(
				sprintf(
				/* translators: 1: error code */
					__( 'Technas Pay: the payment could not be started (%1$s).', 'technas-pay-woocommerce' ),
					$e->errorCode,
				)
			);
			wc_add_notice( Technas_Pay_WC::customer_message( $e ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( $payment->status === 'completed' ) {
			// Essai déjà payé (même référence rejouée) : l'avis signé l'a peut-être déjà marquée.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		if ( ! Technas_Pay_WC::is_trusted_checkout_url( $payment->checkoutUrl ) ) {
			// Une adresse de paiement inattendue n'est jamais suivie (hameçonnage, javascript:…).
			$order->add_order_note( __( 'Technas Pay: unexpected payment page address, not opened.', 'technas-pay-woocommerce' ) );
			wc_add_notice( __( 'The payment could not be started. Please try again.', 'technas-pay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_status( 'pending', __( 'Technas Pay: waiting for the customer to pay on the payment page.', 'technas-pay-woocommerce' ) );
		WC()->cart?->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $payment->checkoutUrl,
		);
	}

	/**
	 * Remboursement depuis l'admin WooCommerce (« Rembourser via Technas Pay »). L'argent repart au
	 * numéro ou à la carte qui a payé ; la commission Technas n'est pas rendue.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		// WooCommerce vérifie déjà jeton et droit avant d'appeler ; on le revérifie (défense en
		// profondeur), sauf en ligne de commande ou en tâche planifiée, où il n'y a pas d'utilisateur.
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() && ! current_user_can( 'edit_shop_orders' ) ) {
			return new WP_Error( 'technas_pay_refund', __( 'You are not allowed to refund orders.', 'technas-pay-woocommerce' ) );
		}
		$reason     = sanitize_text_field( (string) $reason );
		$order      = wc_get_order( $order_id );
		$payment_id = $order ? (string) $order->get_meta( Technas_Pay_WC::META_PAYMENT_ID ) : '';
		if ( $payment_id === '' ) {
			return new WP_Error( 'technas_pay_refund', __( 'This order has no Technas Pay payment to refund.', 'technas-pay-woocommerce' ) );
		}
		$method = (string) $order->get_meta( Technas_Pay_WC::META_METHOD ) ?: 'mobile_money';
		$value  = $amount === null ? null : ( $method === 'card' ? round( (float) $amount, 2 ) : (int) round( (float) $amount ) );
		// Une référence par remboursement WooCommerce (son identifiant, jamais réutilisé) : rejouer la
		// même demande ne rembourse pas deux fois.
		$refunds   = $order->get_refunds();
		$reference = 'wc-' . $order->get_id() . '-refund-' . ( $refunds ? $refunds[0]->get_id() : wp_generate_uuid4() );
		try {
			$refund = Technas_Pay_WC::client()->refund( $payment_id, $value, 'partner', $reference );
		} catch ( TechnasPayException $e ) {
			$message = match ( $e->errorCode ) {
				'insufficient_balance' => sprintf(
					/* translators: 1: missing amount, 2: currency */
					__( 'Your Technas Pay balance is too low: top it up with at least %1$s %2$s, then refund again.', 'technas-pay-woocommerce' ),
					$e->details['missingAmount'] ?? '?',
					$e->details['currency'] ?? '',
				),
				'refund_exceeds_payment' => __( 'This amount is more than what can still be refunded.', 'technas-pay-woocommerce' ),
				'payment_not_refundable' => __( 'Only a paid order can be refunded.', 'technas-pay-woocommerce' ),
				default => sprintf( /* translators: %s: error code */ __( 'Technas Pay refused the refund (%s).', 'technas-pay-woocommerce' ), $e->errorCode ),
			};
			return new WP_Error( 'technas_pay_refund', $message );
		}
		if ( $value !== null && abs( (float) $refund->amount - (float) $value ) > 0.009 ) {
			return new WP_Error( 'technas_pay_refund', __( 'Technas Pay returned a different refund amount. Check the order before refunding again.', 'technas-pay-woocommerce' ) );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: amount, 2: currency, 3: status, 4: reason */
				__( 'Technas Pay refund: %1$s %2$s (%3$s). %4$s', 'technas-pay-woocommerce' ),
				$refund->amount,
				$refund->currency,
				$refund->status,
				$reason,
			)
		);
		return true;
	}
}
