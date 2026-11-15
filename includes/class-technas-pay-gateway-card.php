<?php

defined( 'ABSPATH' ) || exit;

/**
 * Carte bancaire (et Apple Pay / Google Pay selon l'appareil). Exige un compte carte ouvert chez
 * Technas Pay ; l'argent arrive directement sur ce compte, commission déjà retirée.
 */
final class Technas_Pay_Gateway_Card extends Technas_Pay_Gateway {

	public function __construct() {
		$this->id                 = 'technas_pay_card';
		$this->method_title       = __( 'Card (Technas Pay)', 'technas-pay-woocommerce' );
		$this->method_description = __( 'Bank cards, Apple Pay and Google Pay. 1% per successful payment (plus your card account fees), no subscription. Requires an active Technas Pay card account. The key is set in « Mobile money (Technas Pay) ».', 'technas-pay-woocommerce' );
		parent::__construct();
	}

	protected function technas_method(): string {
		return 'card';
	}

	public function init_form_fields(): void {
		$this->form_fields = $this->base_form_fields(
			__( 'Bank card', 'technas-pay-woocommerce' ),
			__( 'Pay by card, Apple Pay or Google Pay.', 'technas-pay-woocommerce' ),
		);
	}

	/**
	 * Ouvert seulement si le compte carte peut encaisser. L'état est gardé une heure (et mis à jour
	 * par l'avis `card_account.updated`) pour ne pas interroger Technas à chaque affichage.
	 */
	public function is_available(): bool {
		return parent::is_available() && Technas_Pay_Webhook::card_account_ready();
	}
}
