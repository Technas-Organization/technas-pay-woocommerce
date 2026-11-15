<?php

declare(strict_types=1);

namespace TechnasPay\Model;

final class Refund
{
    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $paymentReference,
        /** Décompté du paiement : jamais plus que le montant payé, au total. */
        public readonly int|float $amount,
        /** Ce qui arrive au client. */
        public readonly int|float $sentAmount,
        public readonly int|float $fee,
        public readonly string $fees,
        /** Ce qui a quitté votre solde. */
        public readonly int|float $balanceDebit,
        public readonly string $currency,
        /** `pending`, `completed`, `failed`. */
        public readonly string $status,
        public readonly ?string $failure,
        /** `payout` (renvoyé par Technas), `stripe` (carte, depuis VOTRE compte carte), `external` (hors plateforme). */
        public readonly string $kind,
        public readonly ?string $method,
        public readonly ?string $note,
        public readonly ?string $recordedBy,
        public readonly ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            (string) ($r['id'] ?? ''), (string) ($r['payment_id'] ?? ''), (string) ($r['payment_reference'] ?? ''),
            $r['amount'] ?? 0, $r['sent_amount'] ?? 0, $r['fee'] ?? 0, (string) ($r['fees'] ?? 'partner'),
            $r['balance_debit'] ?? 0, (string) ($r['currency'] ?? ''), (string) ($r['status'] ?? ''),
            $r['failure'] ?? null, (string) ($r['kind'] ?? 'payout'), $r['method'] ?? null, $r['note'] ?? null,
            $r['recorded_by'] ?? null, $r['created_at'] ?? null,
        );
    }
}
