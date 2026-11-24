<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un palier de la grille du réseau de paiement. */
final class FeeTier
{
    public function __construct(
        public readonly int|float $minAmount,
        /** `null` : sans limite. */
        public readonly int|float|null $maxAmount,
        public readonly int|float $percent,
        public readonly int|float $fixed,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(D::num($r, 'min_amount'), D::nnum($r, 'max_amount'), D::num($r, 'percent'), D::num($r, 'fixed'));
    }
}
