<?php

defined( 'ABSPATH' ) || exit;

use TechnasPay\Status;
use TechnasPay\TechnasPay;
use TechnasPay\TechnasPayException;

/**
 * Ce qui est commun aux deux moyens de paiement : la clé, le client Technas Pay, la création du
 * paiement d'une commande (« une référence = un essai ») et ses métadonnées.
 */
final class Technas_Pay_WC {

	/** Réglages communs, enregistrés par le moyen « Mobile money ». */
	public const SETTINGS_OPTION = 'woocommerce_technas_pay_mobile_money_settings';

	/**
	 * La clé, dans SA propre option, non chargée à chaque page (autoload désactivé) et jamais
	 * réaffichée dans l'administration.
	 */
	public const SECRET_OPTION = 'technas_pay_secret';

	public const SECRET_PATTERN = '/^tps_(live|test)_[^.\s]+\.[^.\s]+$/';

	/** Métadonnées de commande. */
	public const META_PAYMENT_ID = '_technas_pay_payment_id';
	public const META_ATTEMPT    = '_technas_pay_attempt';
	public const META_METHOD     = '_technas_pay_method';
	/** Toutes les références et tous les paiements créés pour la commande (un avis n'agit que sur eux). */
	public const META_REFERENCES  = '_technas_pay_references';
	public const META_PAYMENT_IDS = '_technas_pay_payment_ids';
	/** Le paiement qui a payé la commande (un second paiement est signalé, jamais ignoré). */
	public const META_PAID_PAYMENT = '_technas_pay_paid_payment_id';

	/**
	 * Le seuil d'identité du compte (`getAccountKyc()`), gardé une heure et rafraîchi par l'avis
	 * `account.kyc_required`. Lu seulement avec une clé `tps_live_` : en test, rien ne s'applique.
	 */
	public const KYC_TRANSIENT = 'technas_pay_kyc';

	/** Hôtes autorisés pour la page de paiement (redirection du client). */
	public const CHECKOUT_HOSTS = array( 'pay-api.technas.fr' );

	/** Nombre maximal d'essais expirés sautés pour une commande. */
	private const MAX_ATTEMPTS = 20;

	public static function secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );
		return is_string( $secret ) ? $secret : '';
	}

	/** Enregistre la clé (sans autoload). Rend faux si elle est mal formée. */
	public static function save_secret( string $secret ): bool {
		$secret = trim( $secret ); // pas de sanitize_text_field : il altérerait une clé ; le motif ci-dessous suffit
		if ( ! preg_match( self::SECRET_PATTERN, $secret ) ) {
			return false;
		}
		delete_option( self::SECRET_OPTION );
		add_option( self::SECRET_OPTION, $secret, '', false );
		delete_transient( 'technas_pay_card_ready' );
		delete_transient( self::KYC_TRANSIENT );
		return true;
	}

	/** Ce qu'on peut montrer de la clé : son mode et ses 4 derniers caractères, jamais plus. */
	public static function secret_hint(): string {
		$secret = self::secret();
		if ( ! self::is_configured() ) {
			return '';
		}
		return ( self::is_test_mode() ? 'tps_test_' : 'tps_live_' ) . '…' . substr( $secret, -4 );
	}

	public static function is_configured(): bool {
		return (bool) preg_match( self::SECRET_PATTERN, self::secret() );
	}

	public static function is_test_mode(): bool {
		return str_starts_with( self::secret(), 'tps_test_' );
	}

	/**
	 * Le seuil d'identité du compte, à montrer au MARCHAND (jamais au client). Null avec une clé de
	 * test, sans clé, ou si Technas n'a pas répondu (réessayé dans 10 minutes). Les chiffres viennent
	 * de l'API : rien n'est écrit en dur ici.
	 */
	public static function account_kyc(): ?\TechnasPay\Model\AccountKyc {
		if ( ! self::is_configured() || self::is_test_mode() ) {
			return null;
		}
		$cached = get_transient( self::KYC_TRANSIENT );
		if ( is_array( $cached ) ) {
			return \TechnasPay\Model\AccountKyc::fromApi( $cached );
		}
		if ( $cached === 'unavailable' ) {
			return null;
		}
		try {
			$kyc = self::client()->getAccountKyc();
		} catch ( TechnasPayException ) {
			set_transient( self::KYC_TRANSIENT, 'unavailable', 10 * MINUTE_IN_SECONDS );
			return null;
		}
		self::store_kyc( $kyc );
		return $kyc;
	}

	/** Garde l'état une heure, sous la forme rendue par l'API. */
	public static function store_kyc( \TechnasPay\Model\AccountKyc $kyc ): void {
		set_transient(
			self::KYC_TRANSIENT,
			array(
				'applies'            => $kyc->applies,
				'stage'              => $kyc->stage,
				'level'              => $kyc->level,
				'days_elapsed'       => $kyc->daysElapsed,
				'warn_from_xof'      => $kyc->warnFromXof,
				'warn_from_date'     => $kyc->warnFromDate,
				'collected_xof'      => $kyc->collectedXof,
				'threshold_xof'      => $kyc->thresholdXof,
				'grace_days'         => $kyc->graceDays,
				'first_collected_at' => $kyc->firstCollectedAt,
				'deadline'           => $kyc->deadline,
				'waiver_until'       => $kyc->waiverUntil,
				'blocked'            => $kyc->blocked,
				'reason'             => $kyc->reason,
				'console_url'        => $kyc->consoleUrl,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * L'avis `account.kyc_required` met l'état gardé à jour, sans appeler Technas : l'encaissé, le
	 * seuil, l'échéance et l'adresse de la console. `stage` et `block_reason` sont lus tels quels quand
	 * l'avis les porte ; un avis plus ancien qui ne les porte pas : `stage` déduit de `blocked`
	 * (`blocked` ou `soon`), motif du blocage inchangé.
	 */
	public static function kyc_from_event( \TechnasPay\Model\KycRequired $k ): void {
		$cached = get_transient( self::KYC_TRANSIENT );
		$base   = is_array( $cached ) ? $cached : array(
			'applies' => true,
			'level'   => 'none',
		);
		$state  = array(
			'stage'         => $k->stage ?? ( $k->blocked ? 'blocked' : 'soon' ),
			'collected_xof' => $k->collectedXof,
			'threshold_xof' => $k->thresholdXof,
			'deadline'      => $k->deadline,
			'blocked'       => $k->blocked,
		) + ( $k->consoleUrl !== '' ? array( 'console_url' => $k->consoleUrl ) : array() )
			+ ( $k->blockReason !== null ? array( 'reason' => $k->blockReason ) : array() )
			+ ( $k->daysElapsed !== null ? array( 'days_elapsed' => $k->daysElapsed ) : array() )
			+ ( $k->graceDays !== null ? array( 'grace_days' => $k->graceDays ) : array() )
			+ ( $k->firstCollectedAt !== null ? array( 'first_collected_at' => $k->firstCollectedAt ) : array() );
		self::store_kyc( \TechnasPay\Model\AccountKyc::fromApi( $state + $base ) );
	}

	/** Le lien vers la console Technas Pay rendu par l'API, s'il est en https ; sinon vide. */
	public static function kyc_console_url( \TechnasPay\Model\AccountKyc $kyc ): string {
		return wp_http_validate_url( $kyc->consoleUrl ) && str_starts_with( $kyc->consoleUrl, 'https://' ) ? $kyc->consoleUrl : '';
	}

	/** L'échéance, à la date du site ; vide si l'API n'en donne pas. */
	public static function kyc_deadline( \TechnasPay\Model\AccountKyc $kyc ): string {
		$ts = $kyc->deadline !== null ? strtotime( $kyc->deadline ) : false;
		return $ts ? wp_date( get_option( 'date_format' ), $ts ) : '';
	}

	/**
	 * Notice d'administration (réservée à `manage_woocommerce`) quand le seuil est proche (`soon`)
	 * ou atteint (`blocked`), clé `tps_live_` seulement.
	 */
	public static function kyc_admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$kyc = self::account_kyc();
		if ( ! $kyc || ! in_array( $kyc->stage, array( 'soon', 'blocked' ), true ) ) {
			return;
		}
		$blocked = $kyc->stage === 'blocked';
		$text    = $blocked
			? __( 'Technas Pay: new mobile money payments are refused until the account holder\'s identity is verified. Card payments are not affected.', 'technas-pay-woocommerce' )
			: sprintf(
				/* translators: 1: amount collected, 2: threshold (both in CFA francs) */
				__( 'Technas Pay: identity check needed soon. Collected: %1$s F CFA of %2$s F CFA allowed without a verified identity.', 'technas-pay-woocommerce' ),
				number_format_i18n( $kyc->collectedXof ),
				number_format_i18n( $kyc->thresholdXof )
			);
		$deadline = self::kyc_deadline( $kyc );
		$url      = self::kyc_console_url( $kyc );
		echo '<div class="notice ' . ( $blocked ? 'notice-error' : 'notice-warning' ) . ' technas-pay-kyc-notice"><p>' . esc_html( $text );
		if ( $deadline !== '' && ! $blocked ) {
			/* translators: %s: deadline date */
			echo ' ' . esc_html( sprintf( __( 'Deadline: %s.', 'technas-pay-woocommerce' ), $deadline ) );
		}
		if ( $url !== '' ) {
			echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upload your ID in the Technas Pay console', 'technas-pay-woocommerce' ) . '</a>';
		}
		echo '</p></div>';
	}

	/** L'adresse d'avis à déclarer à Technas (route REST, signature obligatoire). */
	public static function webhook_url(): string {
		return rest_url( 'technas-pay/v1/webhook' );
	}

	/** Le client Technas Pay. Les tests injectent leur transport par le filtre `technas_pay_wc_transport`. */
	public static function client(): TechnasPay {
		return new TechnasPay(
			self::secret(),
			(string) apply_filters( 'technas_pay_wc_base_url', TechnasPay::DEFAULT_BASE_URL ),
			apply_filters( 'technas_pay_wc_transport', null ),
		);
	}

	/**
	 * Crée (ou reprend) le paiement de cette commande. Une référence = un essai : `{commande}-{essai}`
	 * en mobile money, `{commande}-card-{essai}` par carte. Un essai expiré est sauté ; un essai en
	 * cours est rendu tel quel (jamais deux paiements pour une commande).
	 */
	public static function start_payment( WC_Order $order, string $method, string $return_url ): \TechnasPay\Model\Payment {
		$pay      = self::client();
		$attempt  = max( 1, (int) $order->get_meta( self::META_ATTEMPT ) );
		$order_id = (string) $order->get_id();
		$input    = array(
			'amount'      => self::amount_for( $order, $method ),
			'customer'    => array_filter(
				array(
					'email'   => $order->get_billing_email(),
					'name'    => trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email(),
					'phone'   => $order->get_billing_phone() ?: null,
					'country' => $order->get_billing_country() ?: null,
				)
			),
			'returnUrl'   => $return_url,
			'description' => sprintf(
				/* translators: 1: order number, 2: shop name */
				__( 'Order %1$s at %2$s', 'technas-pay-woocommerce' ),
				$order->get_order_number(),
				get_bloginfo( 'name' ),
			),
			'metadata'    => array(
				'order_id'  => $order_id,
				'order_key' => $order->get_order_key(),
				'source'    => 'woocommerce',
			),
		);
		if ( $method === 'card' ) {
			$input['method']   = 'card';
			$input['currency'] = $order->get_currency();
		}
		// Mobile money : la boutique vend en F CFA, la devise suit le pays du client (XOF ou XAF, même montant).
		for ( ; ; $attempt++ ) {
			// Préfixe « wc- » : une référence de la boutique ne croise jamais celle d'une autre
			// application branchée sur la même clé.
			$input['reference'] = $method === 'card' ? "wc-$order_id-card-$attempt" : 'wc-' . Status::retryReference( $order_id, $attempt );
			try {
				$payment = $pay->askForPayment( $input );
				break;
			} catch ( TechnasPayException $e ) {
				if ( $e->errorCode === 'payment_expired' && $attempt < self::MAX_ATTEMPTS ) {
					continue;
				}
				throw $e;
			}
		}
		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->update_meta_data( self::META_PAYMENT_ID, $payment->id );
		$order->update_meta_data( self::META_METHOD, $method );
		$order->update_meta_data( self::META_REFERENCES, array_values( array_unique( array_merge( self::meta_list( $order, self::META_REFERENCES ), array( $payment->reference ) ) ) ) );
		$order->update_meta_data( self::META_PAYMENT_IDS, array_values( array_unique( array_merge( self::meta_list( $order, self::META_PAYMENT_IDS ), array( $payment->id ) ) ) ) );
		$order->save();
		return $payment;
	}

	/** @return list<string> */
	public static function meta_list( WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/** La page de paiement rendue par Technas : https, et un hôte attendu. Sinon, aucune redirection. */
	public static function is_trusted_checkout_url( ?string $url ): bool {
		if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$hosts = (array) apply_filters( 'technas_pay_wc_checkout_hosts', self::CHECKOUT_HOSTS );
		return ( $parts['scheme'] ?? '' ) === 'https' && in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), $hosts, true );
	}

	/** Mobile money : entier en F CFA. Carte : décimales permises. */
	public static function amount_for( WC_Order $order, string $method ): int|float {
		$total = (float) $order->get_total();
		return $method === 'card' ? round( $total, 2 ) : (int) round( $total );
	}

	/**
	 * La commande d'un avis, SEULEMENT s'il vient de cette boutique : marque `woocommerce`, clé de
	 * commande identique (temps constant), référence et paiement créés pour cette commande, moyen de
	 * paiement Technas Pay. Un avis d'une autre application branchée sur la même clé est ignoré.
	 */
	public static function order_for( \TechnasPay\Model\Payment $payment ): ?WC_Order {
		$meta = $payment->metadata;
		if ( ( $meta['source'] ?? null ) !== 'woocommerce' || ! is_string( $meta['order_key'] ?? null ) ) {
			return null;
		}
		if ( ! preg_match( '/^wc-(\d+)-(?:card-)?\d+$/', $payment->reference, $m ) || (string) ( $meta['order_id'] ?? '' ) !== $m[1] ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof WC_Order
			|| ! hash_equals( $order->get_order_key(), $meta['order_key'] )
			|| ! str_starts_with( $order->get_payment_method(), 'technas_pay_' )
			|| ! in_array( $payment->reference, self::meta_list( $order, self::META_REFERENCES ), true )
			|| ! in_array( $payment->id, self::meta_list( $order, self::META_PAYMENT_IDS ), true ) ) {
			return null;
		}
		return $order;
	}

	/** La commande d'un remboursement : référence `wc-…` de la boutique et paiement créé pour elle. */
	public static function order_for_refund( \TechnasPay\Model\Refund $refund ): ?WC_Order {
		if ( ! preg_match( '/^wc-(\d+)-(?:card-)?\d+$/', $refund->paymentReference, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		return $order instanceof WC_Order && in_array( $refund->paymentId, self::meta_list( $order, self::META_PAYMENT_IDS ), true ) ? $order : null;
	}

	/** Un message d'erreur lisible par le client, jamais un détail technique ni le secret. */
	public static function customer_message( TechnasPayException $e ): string {
		return match ( $e->errorCode ) {
			'country_not_allowed', 'country_not_available', 'currency_conversion_unavailable' => __( 'This payment method is not available in your country. Please choose another one.', 'technas-pay-woocommerce' ),
			'card_account_not_ready' => __( 'Card payment is not available yet. Please choose mobile money.', 'technas-pay-woocommerce' ),
			// Seuil d'identité du compte atteint : rien à dire au client sur le compte du marchand.
			'kyc_required' => __( 'Mobile money payment is not available right now. Please choose card or try again later.', 'technas-pay-woocommerce' ),
			'amount_too_low' => __( 'The amount is too low for this payment method.', 'technas-pay-woocommerce' ),
			default => $e->retryable()
				? __( 'The payment service is temporarily unavailable. Please try again in a moment.', 'technas-pay-woocommerce' )
				: __( 'The payment could not be started. Please try again or choose another payment method.', 'technas-pay-woocommerce' ),
		};
	}
}
