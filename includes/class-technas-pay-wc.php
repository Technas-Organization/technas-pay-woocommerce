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
	/** Le dernier paiement créé par moyen (`mobile_money`, `card`) : un seul paiement ouvert par commande. */
	public const META_LAST_BY_METHOD = '_technas_pay_last_payment_by_method';
	/** Le paiement qui a payé la commande (un second paiement est signalé, jamais ignoré). */
	public const META_PAID_PAYMENT = '_technas_pay_paid_payment_id';
	/** Paiements reçus à un autre montant que la commande (ancien essai) : à rembourser si elle est payée autrement. */
	public const META_TO_REFUND = '_technas_pay_payments_to_refund';

	/**
	 * Le seuil d'identité du compte (`getAccountKyc()`), gardé une heure et rafraîchi par l'avis
	 * `account.kyc_required`. Lu seulement avec une clé `tps_live_` : en test, rien ne s'applique.
	 */
	public const KYC_TRANSIENT = 'technas_pay_kyc';

	/** Devis du total à payer au paiement (`quote`), gardés quelques minutes par montant et pays. */
	public const QUOTE_TRANSIENT_PREFIX = 'technas_pay_quote_';

	/**
	 * Le nom que le client connaît, pour chaque réseau de `Network::ALL` (marques, non traduites).
	 * Un réseau absent d'ici s'affiche par sa valeur.
	 */
	public const NETWORK_NAMES = array(
		'mixx'             => 'Mixx by Yas',
		'moov'             => 'Moov Money (Flooz)',
		'mtn_momo'         => 'MTN MoMo',
		'orange_money'     => 'Orange Money',
		'wave'             => 'Wave',
		'airtel_money'     => 'Airtel Money',
		'free_money'       => 'Free Money',
		'celtiis_cash'     => 'Celtiis Cash',
		'djamo'            => 'Djamo',
		'expresso'         => 'Expresso',
		'wizall'           => 'Wizall',
		'mobicash'         => 'Mobicash',
		'mpesa'            => 'M-Pesa',
		'telecel_cash'     => 'Telecel Cash',
		'airteltigo_money' => 'AirtelTigo Money',
		'afrimoney'        => 'Afrimoney',
		'qmoney'           => 'QMoney',
		'tnm_mpamba'       => 'TNM Mpamba',
		'emola'            => 'e-Mola',
		'zamtel_kwacha'    => 'Zamtel Kwacha',
		'ezypesa'          => 'EzyPesa',
		'halopesa'         => 'HaloPesa',
		'tpesa'            => 'T-Pesa',
	);

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

	/**
	 * Le client Technas Pay. Les tests injectent leur transport par le filtre `technas_pay_wc_transport`.
	 * `$timeout` (secondes) : un appel fait pendant l'affichage d'une page (devis) n'attend jamais 30 s.
	 */
	public static function client( ?int $timeout = null ): TechnasPay {
		$transport = apply_filters( 'technas_pay_wc_transport', null );
		if ( $transport === null && $timeout !== null ) {
			$transport = new \TechnasPay\Http\CurlTransport( $timeout );
		}
		return new TechnasPay(
			self::secret(),
			(string) apply_filters( 'technas_pay_wc_base_url', TechnasPay::DEFAULT_BASE_URL ),
			$transport,
		);
	}

	/**
	 * Qui paie les frais en mobile money, selon le réglage « Who pays the fees » : `customer` (le
	 * client paie le total de la commande + les frais ; le marchand reçoit exactement le total),
	 * `partner` (le client paie le total, frais compris) ou `null` (le réglage du compte Technas Pay).
	 */
	public static function fees_paid_by(): ?string {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$fees     = is_array( $settings ) ? ( $settings['fees'] ?? 'account' ) : 'account';
		return in_array( $fees, array( 'customer', 'partner' ), true ) ? $fees : null;
	}

	/**
	 * Les réseaux proposés au client en mobile money, selon les réglages « Networks offered » (vide :
	 * tous ceux du compte, rien n'est envoyé) et « Network order » (valeurs séparées par des virgules ;
	 * les réseaux choisis qui n'y sont pas suivent, dans l'ordre de `Network::ALL`). Seules les valeurs
	 * de `Network::ALL` sont gardées.
	 *
	 * @return list<string>
	 */
	public static function networks_offered(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$chosen   = array_values( array_intersect( \TechnasPay\Network::ALL, (array) ( $settings['networks'] ?? array() ) ) );
		if ( ! $chosen ) {
			return array();
		}
		$order = self::parse_network_order( (string) ( $settings['network_order'] ?? '' ) );
		return array_values( array_unique( array_merge( array_values( array_intersect( $order, $chosen ) ), $chosen ) ) );
	}

	/**
	 * « mixx, moov » → `['mixx', 'moov']` : valeurs de `Network::ALL` seulement, sans doublon.
	 *
	 * @return list<string>
	 */
	public static function parse_network_order( string $order ): array {
		$values = array_map( static fn ( string $v ): string => strtolower( trim( $v ) ), explode( ',', $order ) );
		return array_values( array_unique( array_filter( $values, array( \TechnasPay\Network::class, 'isValid' ) ) ) );
	}

	/**
	 * La ligne montrée au client sous « Mobile money » au paiement : ce qu'il paiera, frais du réseau
	 * de paiement compris, lu dans le devis de Technas (`quote`). Vide quand le client paie exactement
	 * le total (frais à la charge du marchand), sans panier, ou si le devis n'a pas pu être lu : le
	 * paiement n'en dépend jamais. Un devis est gardé 10 minutes par montant, pays et réglage ; un échec,
	 * 2 minutes (aucune page de paiement ne relance Technas à chaque rafraîchissement).
	 */
	public static function checkout_total_line(): string {
		if ( ! self::is_configured() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		$country = WC()->customer ? (string) WC()->customer->get_billing_country() : '';
		return self::total_line_for( (int) round( (float) WC()->cart->get_total( 'edit' ) ), $country );
	}

	/** La ligne de `checkout_total_line` pour un montant (F CFA) et le pays de facturation du client. */
	public static function total_line_for( int $amount, string $country ): string {
		if ( $amount <= 0 || ! self::is_configured() ) {
			return '';
		}
		$country  = strtoupper( trim( $country ) );
		$country  = $country !== '' && Status::currencyForCountry( $country ) !== null ? $country : '';
		$currency = $country === '' ? get_woocommerce_currency() : '';
		$fees     = self::fees_paid_by();
		$key      = self::QUOTE_TRANSIENT_PREFIX . md5( implode( '|', array( $amount, $country, $currency, (string) $fees, self::secret_hint(), implode( ',', self::networks_offered() ) ) ) );
		$cached   = get_transient( $key );
		if ( $cached === 'unavailable' ) {
			return '';
		}
		if ( is_array( $cached ) ) {
			$quote = \TechnasPay\Model\Quote::fromApi( $cached );
		} else {
			try {
				$quote = self::client( 5 )->quote( $amount, country: $country !== '' ? $country : null, fees: $fees, currency: $currency !== '' ? $currency : null );
			} catch ( \Throwable ) {
				set_transient( $key, 'unavailable', 2 * MINUTE_IN_SECONDS );
				return '';
			}
			set_transient( $key, self::quote_to_array( $quote ), 10 * MINUTE_IN_SECONDS );
		}
		return self::total_line( $quote );
	}

	/** Le devis sous la forme rendue par l'API, pour le garder dans un transient. */
	private static function quote_to_array( \TechnasPay\Model\Quote $q ): array {
		return array(
			'method'          => $q->method,
			'currency'        => $q->currency,
			'country'         => $q->country,
			'fees_paid_by'    => $q->feesPaidBy,
			'amount'          => $q->amount,
			'customer_amount' => $q->customerAmount,
			'customer_total'  => $q->customerTotal,
			'processing_fee'  => $q->processingFee,
			'commission'      => $q->commission,
			'net_amount'      => $q->netAmount,
			'networks'        => array_map(
				static fn ( \TechnasPay\Model\QuoteNetwork $n ): array => array(
					'network'        => $n->network,
					'network_name'   => $n->networkName,
					'customer_total' => $n->customerTotal,
					'processing_fee' => $n->processingFee,
					'commission'     => $n->commission,
					'net_amount'     => $n->netAmount,
				),
				$q->networks
			),
		);
	}

	/**
	 * « Tu paieras X F CFA » (un seul montant) ou « entre X et Y » (selon l'opérateur) ; vide si rien ne
	 * s'ajoute. Seuls les réseaux proposés comptent (`networks[i].network`) ; si aucun ne correspond
	 * (réseau inconnu de cette version), tous ceux du devis.
	 */
	public static function total_line( \TechnasPay\Model\Quote $q ): string {
		$offered  = self::networks_offered();
		$networks = $offered ? array_filter( $q->networks, static fn ( \TechnasPay\Model\QuoteNetwork $n ): bool => in_array( $n->network, $offered, true ) ) : array();
		$networks = $networks ? $networks : $q->networks;
		$totals   = array_map( static fn ( \TechnasPay\Model\QuoteNetwork $n ): int|float => $n->customerTotal, $networks );
		$high     = $totals ? max( $totals ) : $q->customerTotal;
		$low      = $totals ? min( $totals ) : $q->customerTotal;
		if ( $high <= $q->amount ) {
			return '';
		}
		if ( (float) $low === (float) $high ) {
			return sprintf(
				/* translators: %s: amount in CFA francs */
				__( 'With mobile money you will pay %s F CFA, payment network fees included.', 'technas-pay-woocommerce' ),
				number_format_i18n( $high )
			);
		}
		return sprintf(
			/* translators: 1: lowest amount, 2: highest amount (CFA francs) */
			__( 'With mobile money you will pay between %1$s and %2$s F CFA depending on your operator, payment network fees included.', 'technas-pay-woocommerce' ),
			number_format_i18n( $low ),
			number_format_i18n( $high )
		);
	}

	/**
	 * Crée (ou reprend) le paiement de cette commande. Une référence = un essai : `{commande}-{essai}`
	 * en mobile money, `{commande}-card-{essai}` par carte. Un essai expiré est sauté ; un essai en
	 * cours est rendu tel quel. Un seul paiement ouvert par commande : le dernier essai de l'AUTRE moyen
	 * encore ouvert est annulé d'abord, et l'essai d'un ancien total aussi avant le suivant. Si
	 * l'annulation est refusée (`payment_in_progress`, `payment_already_completed`), aucun nouveau
	 * paiement n'est créé : l'erreur remonte. Une annulation qui répond 404 ne bloque rien.
	 */
	public static function start_payment( WC_Order $order, string $method, string $return_url ): \TechnasPay\Model\Payment {
		$pay = self::client();
		self::close_other_method( $pay, $order, $method );
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
		} else {
			// Qui paie les frais : mobile money seulement (par carte, toujours le marchand).
			if ( self::fees_paid_by() !== null ) {
				$input['fees'] = self::fees_paid_by();
			}
			// Réseaux proposés, dans l'ordre choisi ; aucun choisi : ceux du compte.
			if ( self::networks_offered() ) {
				$input['networks'] = self::networks_offered();
			}
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
				if ( $attempt >= self::MAX_ATTEMPTS || ( $e->errorCode !== 'payment_expired' && $e->errorCode !== 'reference_conflict' ) ) {
					throw $e;
				}
				// Essai expiré : le suivant. Essai d'un autre montant (le total a changé depuis le premier
				// essai, reference_conflict) : l'ancien est annulé s'il est encore ouvert, puis le suivant.
				if ( $e->errorCode === 'reference_conflict' ) {
					self::close_previous( $pay, $input['reference'] );
				}
			}
		}
		// Avant META_PAYMENT_ID et META_METHOD : une commande d'avant 0.4.0 y lit encore son dernier paiement.
		$order->update_meta_data( self::META_LAST_BY_METHOD, array( $method => $payment->id ) + self::last_by_method( $order ) );
		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->update_meta_data( self::META_PAYMENT_ID, $payment->id );
		$order->update_meta_data( self::META_METHOD, $method );
		$order->update_meta_data( self::META_REFERENCES, array_values( array_unique( array_merge( self::meta_list( $order, self::META_REFERENCES ), array( $payment->reference ) ) ) ) );
		$order->update_meta_data( self::META_PAYMENT_IDS, array_values( array_unique( array_merge( self::meta_list( $order, self::META_PAYMENT_IDS ), array( $payment->id ) ) ) ) );
		$order->save();
		return $payment;
	}

	/**
	 * Le dernier paiement de chaque moyen. Une commande d'avant 0.4.0 n'a que le dernier paiement
	 * (`META_PAYMENT_ID`) et son moyen (`META_METHOD`).
	 *
	 * @return array<string, string>
	 */
	public static function last_by_method( WC_Order $order ): array {
		$value = $order->get_meta( self::META_LAST_BY_METHOD );
		if ( is_array( $value ) ) {
			return array_filter( $value, static fn ( $id ): bool => is_string( $id ) && $id !== '' );
		}
		$id     = (string) $order->get_meta( self::META_PAYMENT_ID );
		$method = (string) $order->get_meta( self::META_METHOD );
		return $id !== '' && $method !== '' ? array( $method => $id ) : array();
	}

	/**
	 * Le client paie par un moyen alors que le dernier essai de l'autre est encore ouvert (`pending`,
	 * `failed`) : cet essai est annulé d'abord, sinon la commande pourrait être payée deux fois
	 * (mobile money puis carte). Déjà payé : `payment_already_completed`, aucun nouveau paiement.
	 */
	private static function close_other_method( TechnasPay $pay, WC_Order $order, string $method ): void {
		foreach ( self::last_by_method( $order ) as $other => $id ) {
			if ( $other === $method ) {
				continue;
			}
			try {
				$previous = $pay->getPayment( $id );
			} catch ( TechnasPayException $e ) {
				if ( $e->status === 404 ) {
					continue;
				}
				throw $e;
			}
			self::cancel_if_open( $pay, $previous, array( 'pending', 'failed' ) );
		}
	}

	/** Changement de total : l'essai de cette référence est relu, puis annulé s'il est encore ouvert. */
	private static function close_previous( TechnasPay $pay, string $reference ): void {
		try {
			$previous = $pay->getPaymentByReference( $reference );
		} catch ( TechnasPayException $e ) {
			if ( $e->status === 404 ) {
				return;
			}
			throw $e;
		}
		self::cancel_if_open( $pay, $previous, null );
	}

	/**
	 * Payé : `payment_already_completed`. Ouvert (`$open`, ou tout sauf `expired` si `null`) : annulé.
	 * Une annulation qui répond 404 (paiement introuvable, ou service qui ne l'a pas encore) ne bloque
	 * rien ; `payment_in_progress` et `payment_already_completed` remontent.
	 *
	 * @param list<string>|null $open
	 */
	private static function cancel_if_open( TechnasPay $pay, \TechnasPay\Model\Payment $previous, ?array $open ): void {
		if ( $previous->status === 'completed' ) {
			throw new TechnasPayException( 'payment_already_completed', 'An earlier payment for this order is already paid', 409 );
		}
		$is_open = $open === null ? $previous->status !== 'expired' : in_array( $previous->status, $open, true );
		if ( ! $is_open ) {
			return;
		}
		try {
			$pay->cancelPayment( $previous->id );
		} catch ( TechnasPayException $e ) {
			if ( $e->status !== 404 ) {
				throw $e;
			}
		}
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
			'method_not_enabled', 'network_not_enabled', 'network_not_available' => __( 'This payment method is not available right now. Please choose another one.', 'technas-pay-woocommerce' ),
			'amount_too_low' => __( 'The amount is too low for this payment method.', 'technas-pay-woocommerce' ),
			// Un seul paiement ouvert par commande : l'ancien n'a pas pu être fermé.
			'payment_in_progress' => __( 'A payment is already in progress for this order. Complete it, or try again in a few minutes.', 'technas-pay-woocommerce' ),
			'payment_already_completed' => __( 'This order is already paid.', 'technas-pay-woocommerce' ),
			default => $e->retryable()
				? __( 'The payment service is temporarily unavailable. Please try again in a moment.', 'technas-pay-woocommerce' )
				: __( 'The payment could not be started. Please try again or choose another payment method.', 'technas-pay-woocommerce' ),
		};
	}
}
