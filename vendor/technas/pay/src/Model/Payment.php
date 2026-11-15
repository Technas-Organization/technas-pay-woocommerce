<?php

declare(strict_types=1);

namespace TechnasPay\Model;

/**
 * Un paiement. Montants en unité principale (5000 = 5 000 FCFA). Hors zone franc CFA, un montant
 * payé peut avoir des centimes (121.96 GHS) : stockez-le en décimal, ne l'arrondissez pas.
 */
final class Payment
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly int|float $amount,
        /** `null` tant que le payeur n'a pas choisi son pays. */
        public readonly ?string $currency,
        public readonly int|float $commission,
        /** Ce qui vous revient. */
        public readonly int|float $netAmount,
        /** `pending`, `completed`, `failed` (PAS définitif), `expired` (définitif), `amount_mismatch`. */
        public readonly string $status,
        /** `mobile_money` ou `card`. */
        public readonly string $method,
        public readonly int|float $refundedAmount,
        public readonly int|float|null $originalAmount,
        public readonly ?string $originalCurrency,
        /** @var array<string, mixed>|null Change hors zone CFA. */
        public readonly ?array $fx,
        /** `null` après expiration : n'envoyez jamais un ancien lien. */
        public readonly ?string $checkoutUrl,
        public readonly array $metadata,
        public readonly bool $testMode,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $expiredAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            (string) ($r['id'] ?? ''),
            (string) ($r['reference'] ?? ''),
            $r['amount'] ?? 0,
            $r['currency'] ?? null,
            $r['commission'] ?? 0,
            $r['net_amount'] ?? 0,
            (string) ($r['status'] ?? ''),
            (string) ($r['method'] ?? 'mobile_money'),
            $r['refunded_amount'] ?? 0,
            $r['original_amount'] ?? null,
            $r['original_currency'] ?? null,
            is_array($r['fx'] ?? null) ? $r['fx'] : null,
            $r['checkout_url'] ?? null,
            is_array($r['metadata'] ?? null) ? $r['metadata'] : [],
            (bool) ($r['test_mode'] ?? false),
            $r['created_at'] ?? null,
            $r['completed_at'] ?? null,
            $r['expires_at'] ?? null,
            $r['expired_at'] ?? null,
        );
    }

    /**
     * Ce que VOTRE backend renvoie à votre app (`['payment' => $p->toAppJson()]`) : ni commission,
     * ni montant net, ni métadonnées. C'est le contrat que lit `technas_pay_flutter`.
     *
     * @return array{id: string, reference: string, status: string, checkout_url: ?string, amount: int|float, currency: ?string, expires_at: ?string, method: string}
     */
    public function toAppJson(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'checkout_url' => $this->checkoutUrl,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
            'method' => $this->method,
        ];
    }
}
