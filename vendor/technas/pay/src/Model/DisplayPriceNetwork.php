<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Ce que vous recevez, réseau par réseau, au prix affiché. */
final class DisplayPriceNetwork
{
    public function __construct(
        public readonly ?string $country,
        /** Le réseau, de `Network::ALL` (ex. `mixx`) ; une valeur inconnue de ce SDK est gardée telle quelle. */
        public readonly string $network,
        /** Le nom que le client connaît (ex. « Mixx by Yas »). */
        public readonly string $networkName,
        /** Ce que le client paie : jamais plus que le prix affiché. */
        public readonly int|float $customerTotal,
        public readonly int|float $processingFee,
        public readonly int|float $commission,
        /** Ce que vous recevez (au moins `receive`). */
        public readonly int|float $netAmount,
        /** Frais propres de l'opérateur du client, hors Technas : « environ ». `null` : inconnus. */
        public readonly int|float|null $operatorFeeEstimate,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $network = D::str($r, 'network');
        return new self(
            D::nstr($r, 'country'),
            $network,
            D::nstr($r, 'network_name') ?? $network,
            D::num($r, 'customer_total'),
            D::num($r, 'processing_fee'),
            D::num($r, 'commission'),
            D::num($r, 'net_amount'),
            D::nnum($r, 'operator_fee_estimate'),
        );
    }
}
