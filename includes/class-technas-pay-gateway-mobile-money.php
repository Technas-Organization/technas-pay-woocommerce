<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mobile money (Orange Money, MTN MoMo, Moov, Wave…). Porte aussi les réglages communs : la
 * clé Technas Pay et l'adresse d'avis à copier.
 */
final class Technas_Pay_Gateway_Mobile_Money extends Technas_Pay_Gateway {

	public function __construct() {
		$this->id                 = 'technas_pay_mobile_money';
		$this->method_title       = __( 'Mobile money (Technas Pay)', 'technas-pay-woocommerce' );
		$this->method_description = __( 'Orange Money, MTN MoMo, Moov Money, Wave… The customer chooses their country and operator on the Technas Pay page. 2% per successful payment (operator fees are paid by the customer), no subscription.', 'technas-pay-woocommerce' );
		parent::__construct();
	}

	protected function technas_method(): string {
		return 'mobile_money';
	}

	public function init_form_fields(): void {
		$this->form_fields = $this->base_form_fields(
			__( 'Mobile money', 'technas-pay-woocommerce' ),
			__( 'Pay with Orange Money, MTN MoMo, Moov Money or Wave.', 'technas-pay-woocommerce' ),
		) + array(
			'secret'  => array(
				'title'       => __( 'Technas Pay key', 'technas-pay-woocommerce' ),
				'type'        => 'technas_secret',
				'description' => __( 'Starts with tps_test_ (test, no money moves) or tps_live_ (real payments). Shared by mobile money and card. Ask contact@technas.fr for a test key.', 'technas-pay-woocommerce' ),
				'default'     => '',
			),
			'webhook' => array(
				'title'       => __( 'Notification address', 'technas-pay-woocommerce' ),
				'type'        => 'title',
				/* translators: %s: webhook URL */
				'description' => sprintf( __( 'Give this address to Technas: it is how your orders get marked as paid. <br><code>%s</code>', 'technas-pay-woocommerce' ), esc_url( Technas_Pay_WC::webhook_url() ) ),
			),
		);
	}

	/**
	 * Le champ de la clé : toujours VIDE à l'affichage (la clé n'est jamais renvoyée au navigateur),
	 * avec seulement son mode et ses 4 derniers caractères en indication.
	 */
	public function generate_technas_secret_html( string $key, array $data ): string {
		$field = $this->get_field_key( $key );
		$hint  = Technas_Pay_WC::secret_hint();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" autocomplete="new-password"
					name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" value=""
					placeholder="<?php echo esc_attr( $hint !== '' ? sprintf( /* translators: %s: masked key */ __( 'Saved: %s. Paste a new key to replace it.', 'technas-pay-woocommerce' ), $hint ) : 'tps_test_…' ); ?>" />
				<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * La clé est vérifiée, puis rangée dans son option à part (sans autoload) ; elle n'est jamais
	 * écrite dans les réglages WooCommerce. Champ vide = garder la clé enregistrée.
	 */
	public function validate_technas_secret_field( string $key, $value ): string {
		// Pas de sanitize_text_field : il retirerait des « %xx » d'une clé. Le motif strict de
		// Technas_Pay_WC::SECRET_PATTERN (aucun espace, aucun caractère de contrôle) fait office de filtre.
		$value = trim( wp_unslash( (string) $value ) );
		if ( $value !== '' && ! Technas_Pay_WC::save_secret( $value ) ) {
			WC_Admin_Settings::add_error( __( 'This Technas Pay key looks wrong: it must start with tps_test_ or tps_live_. Copy it again.', 'technas-pay-woocommerce' ) );
		}
		return '';
	}

	/** Le mobile money encaisse en francs CFA : la boutique doit vendre en XOF ou en XAF. */
	public function is_available(): bool {
		return parent::is_available() && in_array( get_woocommerce_currency(), array( 'XOF', 'XAF' ), true );
	}
}
