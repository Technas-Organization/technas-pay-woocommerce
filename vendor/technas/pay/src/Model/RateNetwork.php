<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un réseau de la grille des frais. */
final class RateNetwork
{
    /**
     * @param list<FeeTier> $feeTiers
     * @param array{customer: ?RateExample, partner: ?RateExample} $example1000
     */
    public function __construct(
        /** Le réseau, de `Network::ALL` (ex. `mixx`) ; une valeur inconnue de ce SDK est gardée telle quelle. */
        public readonly string $network,
        /** Le nom que le client connaît (ex. « Mixx by Yas »). */
        public readonly string $networkName,
        /** Pourcentage du réseau de paiement (palier de 1 000). */
        public readonly int|float $percent,
        public readonly int|float $fixed,
        public readonly array $feeTiers,
        /** Le plus petit montant que le réseau accepte ; `null` : inconnu. */
        public readonly int|float|null $minimumAmount,
        /** Frais propres de l'opérateur du client sur 1 000, hors Technas : « environ ». `null` : inconnus. */
        public readonly int|float|null $operatorFeeEstimate,
        /** Exemple à 1 000, dans chaque mode (`null` : pas payable). */
        public readonly array $example1000,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $network = D::str($r, 'network');
        $exemple = D::map($r, 'example_1000');
        return new self(
            $network,
            D::nstr($r, 'network_name') ?? $network,
            D::num($r, 'percent'),
            D::num($r, 'fixed'),
            array_map(static fn (array $t): FeeTier => FeeTier::fromApi($t), D::asMaps($r['fee_tiers'] ?? null)),
            D::nnum($r, 'minimum_amount'),
            D::nnum($r, 'operator_fee_estimate'),
            ['customer' => RateExample::fromApi(D::nmap($exemple, 'customer')), 'partner' => RateExample::fromApi(D::nmap($exemple, 'partner'))],
        );
    }
}
