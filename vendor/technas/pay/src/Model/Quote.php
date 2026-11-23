<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Le devis d'un paiement (`$pay->quote(...)`) : ce que le client paiera et ce que vous recevrez.
 * Rien n'est créé. Montants en unité principale (5000 = 5 000 F CFA).
 */
final class Quote
{
    /** @param list<QuoteNetwork> $networks */
    public function __construct(
        /** `mobile_money` ou `card`. */
        public readonly string $method,
        public readonly string $currency,
        /** `null` : tous les pays de la devise. */
        public readonly ?string $country,
        /** Qui paie les frais : `partner` (vous) ou `customer` (le client). */
        public readonly string $feesPaidBy,
        /** Votre prix. */
        public readonly int|float $amount,
        /** Ce que Technas demande au réseau de paiement (avant ses frais). */
        public readonly int|float $customerAmount,
        /**
         * Ce que le client paie, frais du réseau compris (le plus haut des réseaux possibles) : le
         * montant à lui afficher. Par carte : `amount` (les frais de la carte sont pris sur votre compte carte).
         */
        public readonly int|float $customerTotal,
        /** Les frais du réseau de paiement (le plus haut des réseaux) ; `null` par carte. */
        public readonly int|float|null $processingFee,
        /** La commission Technas. */
        public readonly int|float $commission,
        /** Ce que vous recevez ; `null` par carte (dépend de la carte du client). */
        public readonly int|float|null $netAmount,
        /** Réseau par réseau (mobile money) ; vide par carte. */
        public readonly array $networks,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $amount = D::num($r, 'amount');
        return new self(
            D::str($r, 'method', 'mobile_money'),
            D::str($r, 'currency'),
            D::nstr($r, 'country'),
            D::str($r, 'fees_paid_by', 'partner'),
            $amount,
            D::nnum($r, 'customer_amount') ?? $amount,
            D::nnum($r, 'customer_total') ?? $amount,
            D::nnum($r, 'processing_fee'),
            D::num($r, 'commission'),
            D::nnum($r, 'net_amount'),
            array_map(static fn (array $n): QuoteNetwork => QuoteNetwork::fromApi($n), D::asMaps($r['networks'] ?? null)),
        );
    }
}
