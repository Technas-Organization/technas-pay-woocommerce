<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un réseau du devis : ce que le client paie s'il paie avec lui. */
final class QuoteNetwork
{
    public function __construct(
        /**
         * Le réseau, de `Network::ALL` (ex. `mixx`). Avant le 23/11/2026 le service rendait un code
         * technique (`togocel`) ; une valeur inconnue de ce SDK est gardée telle quelle.
         */
        public readonly string $network,
        /** Le nom que le client connaît (ex. « Mixx by Yas »). */
        public readonly string $networkName,
        /** Ce que le client paie, frais du réseau de paiement compris : le montant à afficher. */
        public readonly int|float $customerTotal,
        /** Les frais du réseau de paiement dans `customerTotal`. */
        public readonly int|float $processingFee,
        /** La commission Technas. */
        public readonly int|float $commission,
        /** Ce que vous recevez. */
        public readonly int|float $netAmount,
        /**
         * Les frais propres de l'opérateur du client, prélevés sur son téléphone EN PLUS, hors
         * Technas : une estimation (« environ »), jamais promise. `null` : inconnus.
         */
        public readonly int|float|null $operatorFeeEstimate,
        /** Le client doit obtenir un code sur son téléphone avant de payer (`otpInstructions`). */
        public readonly bool $otpRequired,
        public readonly ?string $otpInstructions,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $network = D::str($r, 'network');
        return new self(
            $network,
            D::nstr($r, 'network_name') ?? $network,
            D::num($r, 'customer_total'),
            D::num($r, 'processing_fee'),
            D::num($r, 'commission'),
            D::num($r, 'net_amount'),
            D::nnum($r, 'operator_fee_estimate'),
            D::bool($r, 'otp_required'),
            D::nstr($r, 'otp_instructions'),
        );
    }
}
