<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Un paiement. Montants en unité principale (5000 = 5 000 FCFA). Hors zone franc CFA, un montant
 * payé peut avoir des centimes (121.96 GHS) : stockez-le en décimal, ne l'arrondissez pas.
 */
final class Payment
{
    /**
     * @param array<string, mixed>|null $fx
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly int|float $amount,
        /** `null` tant que le payeur n'a pas choisi son pays. */
        public readonly ?string $currency,
        public readonly int|float $commission,
        /** Ce qui vous revient. */
        public readonly int|float $netAmount,
        /** `pending`, `completed`, `failed` (PAS définitif), `expired` (définitif), `amount_mismatch`. */
        public readonly string $status,
        /** `mobile_money` ou `card`. */
        public readonly string $method,
        public readonly int|float $refundedAmount,
        public readonly int|float|null $originalAmount,
        public readonly ?string $originalCurrency,
        /** Change hors zone CFA. */
        public readonly ?array $fx,
        /** `null` après expiration : n'envoyez jamais un ancien lien. */
        public readonly ?string $checkoutUrl,
        public readonly array $metadata,
        public readonly bool $testMode,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $expiredAt,
        /** Quand vous l'avez annulé (`cancelPayment`) ; `null` sinon. Un paiement annulé reste `expired`. */
        public readonly ?string $canceledAt = null,
        /** `canceled_by_partner` après `cancelPayment` ; `null` sinon. */
        public readonly ?string $cancelReason = null,
        /** Avis `payment.expired` : `canceled` (annulé par vous) ou `timeout` ; `null` sur un ancien avis. */
        public readonly ?string $reason = null,
        /**
         * Ce que Technas demande au réseau de paiement, avant ses frais (= `amount`, sauf frais au
         * client). Serveur ancien : `amount`.
         */
        public readonly int|float|null $customerAmount = null,
        /** Qui a payé les frais : `partner` (vous) ou `customer` (le client). Serveur ancien : `partner`. */
        public readonly string $feesPaidBy = 'partner',
        /**
         * Ce que le client paie, frais du réseau de paiement compris (le plus haut des réseaux tant
         * qu'il n'a pas choisi le sien). `null` : inconnu (carte, hors franc CFA, serveur ancien).
         */
        public readonly int|float|null $customerTotal = null,
        /** Les frais du réseau de paiement dans `customerTotal` ; `null` : inconnus. */
        public readonly int|float|null $processingFee = null,
        /**
         * Le réseau avec lequel le client a payé (`Network::ALL`), une fois payé ; `null` avant, ou par
         * carte. Une valeur que ce SDK ne connaît pas encore est gardée telle quelle.
         */
        public readonly ?string $network = null,
        /**
         * Les réseaux permis pour ce paiement, s'il les a restreints (`networks` à la création) ; `null` sinon.
         *
         * @var list<string>|null
         */
        public readonly ?array $networks = null,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'id'),
            D::str($r, 'reference'),
            D::num($r, 'amount'),
            D::nstr($r, 'currency'),
            D::num($r, 'commission'),
            D::num($r, 'net_amount'),
            D::str($r, 'status'),
            D::str($r, 'method', 'mobile_money'),
            D::num($r, 'refunded_amount'),
            D::nnum($r, 'original_amount'),
            D::nstr($r, 'original_currency'),
            D::nmap($r, 'fx'),
            D::nstr($r, 'checkout_url'),
            D::map($r, 'metadata'),
            D::bool($r, 'test_mode'),
            D::nstr($r, 'created_at'),
            D::nstr($r, 'completed_at'),
            D::nstr($r, 'expires_at'),
            D::nstr($r, 'expired_at'),
            D::nstr($r, 'canceled_at'),
            D::nstr($r, 'cancel_reason'),
            D::nstr($r, 'reason'),
            D::nnum($r, 'customer_amount') ?? D::num($r, 'amount'),
            D::str($r, 'fees_paid_by', 'partner'),
            D::nnum($r, 'customer_total'),
            D::nnum($r, 'processing_fee'),
            D::nstr($r, 'network'),
            D::nstrings($r, 'networks'),
        );
    }

    /**
     * Ce que VOTRE backend renvoie à votre app (`['payment' => $p->toAppJson()]`) : ni commission,
     * ni montant net, ni métadonnées. C'est le contrat que lit `technas_pay_flutter`.
     *
     * @return array{id: string, reference: string, status: string, checkout_url: ?string, amount: int|float, currency: ?string, expires_at: ?string, method: string}
     */
    public function toAppJson(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'checkout_url' => $this->checkoutUrl,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
            'method' => $this->method,
        ];
    }
}
