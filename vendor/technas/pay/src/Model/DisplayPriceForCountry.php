<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un prix affiché, avec le réseau qui vous laisse le moins. */
final class DisplayPriceForCountry
{
    /** @param list<DisplayPriceNetwork> $netByNetwork */
    public function __construct(
        /** Le prix à afficher. */
        public readonly int|float $price,
        public readonly ?string $currency,
        /** Le réseau qui vous laisse le moins (au moins `receive`) ; `null` s'il manque. */
        public readonly ?DisplayPriceNetwork $worstNetwork,
        /** Ce que vous recevez, réseau par réseau. */
        public readonly array $netByNetwork,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(D::num($r, 'price'), D::nstr($r, 'currency'), self::worst($r), self::byNetwork($r));
    }

    /** @param array<mixed> $r */
    public static function worst(array $r): ?DisplayPriceNetwork
    {
        $w = D::nmap($r, 'worst_network');
        return $w === null ? null : DisplayPriceNetwork::fromApi($w);
    }

    /**
     * @param array<mixed> $r
     * @return list<DisplayPriceNetwork>
     */
    public static function byNetwork(array $r): array
    {
        return array_map(static fn (array $n): DisplayPriceNetwork => DisplayPriceNetwork::fromApi($n), D::asMaps($r['net_by_network'] ?? null));
    }
}
