<?php

declare(strict_types=1);

namespace TechnasPay\Model;

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

    /** @param array<string, mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self((string) $r['id'], $r['amount'], (string) $r['currency'], (string) $r['status'], (string) $r['checkout_url'],
            (bool) ($r['test_mode'] ?? false), $r['created_at'] ?? null, $r['completed_at'] ?? null);
    }
}
