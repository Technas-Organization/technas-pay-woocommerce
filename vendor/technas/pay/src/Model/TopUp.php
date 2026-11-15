<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

final class TopUp
{
    public function __construct(
        public readonly string $id,
        public readonly int|float $amount,
        public readonly string $currency,
        public readonly string $status,
        /** Ouvrez cette page pour payer la recharge. */
        public readonly string $checkoutUrl,
        public readonly bool $testMode,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(D::str($r, 'id'), D::num($r, 'amount'), D::str($r, 'currency'), D::str($r, 'status'), D::str($r, 'checkout_url'),
            D::bool($r, 'test_mode'), D::nstr($r, 'created_at'), D::nstr($r, 'completed_at'));
    }
}
