<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/** Un exemple à 1 000 dans un mode de frais. */
final class RateExample
{
    public function __construct(
        /** Ce que le client paie. */
        public readonly int|float $customerTotal,
        public readonly int|float $processingFee,
        public readonly int|float $commission,
        /** Ce que vous recevez. */
        public readonly int|float $netAmount,
    ) {
    }

    /** @param array<mixed>|null $r `null` : pas payable dans ce mode. */
    public static function fromApi(?array $r): ?self
    {
        if ($r === null) {
            return null;
        }
        return new self(D::num($r, 'customer_total'), D::num($r, 'processing_fee'), D::num($r, 'commission'), D::num($r, 'net_amount'));
    }
}
