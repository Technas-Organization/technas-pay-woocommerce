<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un pays de la grille des frais. */
final class RateCountry
{
    /**
     * @param array{percent: int|float, fixed: int|float} $commission
     * @param list<RateNetwork> $networks
     */
    public function __construct(
        public readonly string $country,
        public readonly string $currency,
        /** La commission Technas. */
        public readonly array $commission,
        public readonly array $networks,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        $commission = D::map($r, 'commission');
        return new self(
            D::str($r, 'country'),
            D::str($r, 'currency'),
            ['percent' => D::num($commission, 'percent'), 'fixed' => D::num($commission, 'fixed')],
            array_map(static fn (array $n): RateNetwork => RateNetwork::fromApi($n), D::asMaps($r['networks'] ?? null)),
        );
    }
}
