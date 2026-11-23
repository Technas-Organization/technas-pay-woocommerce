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
		$this->method_description = __( 'Orange Money, MTN MoMo, Moov Money, Wave… The customer chooses their country and operator on the Technas Pay page. 2% per successful payment, no subscription. You choose who pays the fees: the customer (you receive exactly the order total) or you.', 'technas-pay-woocommerce' );
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
			'secret'        => array(
				'title'       => __( 'Technas Pay key', 'technas-pay-woocommerce' ),
				'type'        => 'technas_secret',
				'description' => __( 'Starts with tps_test_ (test, no money moves) or tps_live_ (real payments). Shared by mobile money and card. Ask contact@technas.fr for a test key.', 'technas-pay-woocommerce' ),
				'default'     => '',
			),
			'fees'          => array(
				'title'       => __( 'Who pays the fees', 'technas-pay-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'account'  => __( 'Technas Pay account setting', 'technas-pay-woocommerce' ),
					'customer' => __( 'The customer: you receive exactly the order total', 'technas-pay-woocommerce' ),
					'partner'  => __( 'You: the customer pays the order total, fees included', 'technas-pay-woocommerce' ),
				),
				'description' => __( 'Mobile money only (card payments: always you). When the customer pays the fees, the total to pay is shown under « Mobile money » at checkout.', 'technas-pay-woocommerce' ),
				'default'     => 'account',
			),
			'networks'      => array(
				'title'       => __( 'Networks offered', 'technas-pay-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => self::network_options(),
				'description' => __( 'Leave empty to offer every network enabled on your Technas Pay account.', 'technas-pay-woocommerce' ),
				'default'     => array(),
			),
			'network_order' => array(
				'title'       => __( 'Network order', 'technas-pay-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'mixx, moov',
				/* translators: %s: list of network values */
				'description' => sprintf( __( 'Order of the networks offered on the payment page, separated by commas. Values: %s.', 'technas-pay-woocommerce' ), implode( ', ', \TechnasPay\Network::ALL ) ),
				'default'     => '',
			),
			'kyc'           => array(
				'title' => __( 'Identity threshold', 'technas-pay-woocommerce' ),
				'type'  => 'technas_kyc',
			),
			'webhook'       => array(
				'title'       => __( 'Notification address', 'technas-pay-woocommerce' ),
				'type'        => 'title',
				/* translators: %s: webhook URL */
				'description' => sprintf( __( 'Give this address to Technas: it is how your orders get marked as paid. <br><code>%s</code>', 'technas-pay-woocommerce' ), esc_url( Technas_Pay_WC::webhook_url() ) ),
			),
		);
	}

	/** Les réseaux de `Network::ALL`, avec le nom que le client connaît. */
	private static function network_options(): array {
		$options = array();
		foreach ( \TechnasPay\Network::ALL as $network ) {
			$options[ $network ] = Technas_Pay_WC::NETWORK_NAMES[ $network ] ?? $network;
		}
		return $options;
	}

	/** « Network order » : seules les valeurs de `Network::ALL` sont gardées, sans doublon. */
	public function validate_network_order_field( string $key, $value ): string {
		return implode( ', ', Technas_Pay_WC::parse_network_order( sanitize_text_field( wp_unslash( (string) $value ) ) ) );
	}

	/** « Networks offered » : seules les valeurs de `Network::ALL` sont gardées. */
	public function validate_networks_field( string $key, $value ): array {
		$value = is_array( $value ) ? array_map( 'sanitize_text_field', wp_unslash( $value ) ) : array();
		return array_values( array_intersect( \TechnasPay\Network::ALL, $value ) );
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
	 * « Seuil d'identité » : ce que le compte a encaissé, le seuil, l'échéance et le lien vers la
	 * console. Clé `tps_live_` seulement (en test, rien ne s'applique : la ligne n'est pas montrée).
	 */
	public function generate_technas_kyc_html( string $key, array $data ): string {
		if ( ! Technas_Pay_WC::is_configured() || Technas_Pay_WC::is_test_mode() ) {
			return '';
		}
		$kyc = Technas_Pay_WC::account_kyc();
		ob_start();
		?>
		<tr valign="top" class="technas-pay-kyc">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<?php if ( ! $kyc ) : ?>
					<p><?php esc_html_e( 'The identity threshold could not be read right now. It will be read again in a few minutes.', 'technas-pay-woocommerce' ); ?></p>
				<?php else : ?>
					<p>
						<?php
						echo esc_html(
							match ( $kyc->stage ) {
								'verified' => __( 'Identity verified: no threshold applies.', 'technas-pay-woocommerce' ),
								'not_applicable' => __( 'No identity threshold applies to this account.', 'technas-pay-woocommerce' ),
								'blocked' => __( 'Threshold reached: new mobile money payments are refused until the identity is verified. Card payments are not affected.', 'technas-pay-woocommerce' ),
								'soon' => __( 'Threshold close: upload your ID before the deadline.', 'technas-pay-woocommerce' ),
								default => __( 'Below the threshold.', 'technas-pay-woocommerce' ),
							}
						);
						?>
					</p>
					<?php if ( $kyc->applies ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: amount collected, 2: threshold (both in CFA francs) */
									__( 'Collected: %1$s F CFA. Threshold: %2$s F CFA.', 'technas-pay-woocommerce' ),
									number_format_i18n( $kyc->collectedXof ),
									number_format_i18n( $kyc->thresholdXof )
								)
							);
							$deadline = Technas_Pay_WC::kyc_deadline( $kyc );
							if ( $deadline !== '' ) {
								/* translators: %s: deadline date */
								echo ' ' . esc_html( sprintf( __( 'Deadline: %s.', 'technas-pay-woocommerce' ), $deadline ) );
							}
							?>
						</p>
					<?php endif; ?>
					<?php $url = Technas_Pay_WC::kyc_console_url( $kyc ); ?>
					<?php if ( $url !== '' ) : ?>
						<p><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the Technas Pay console', 'technas-pay-woocommerce' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/** La ligne « Seuil d'identité » ne s'enregistre pas : elle est lue chez Technas. */
	public function validate_technas_kyc_field( string $key, $value ): string {
		return '';
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

	/**
	 * Au paiement, la description dit au client ce qu'il paiera, frais du réseau de paiement compris
	 * (devis `quote`), quand ce n'est pas exactement le total de la commande. Le devis n'est qu'une
	 * information : s'il échoue, la description reste celle des réglages et le paiement continue.
	 */
	public function get_description() {
		$description = parent::get_description();
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $description;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return $description;
		}
		$line = Technas_Pay_WC::checkout_total_line();
		if ( $line === '' ) {
			return $description;
		}
		return trim( $description . "\n\n" . esc_html( $line ) );
	}

	/** Le mobile money encaisse en francs CFA : la boutique doit vendre en XOF ou en XAF. */
	public function is_available(): bool {
		return parent::is_available() && in_array( get_woocommerce_currency(), array( 'XOF', 'XAF' ), true );
	}
}
