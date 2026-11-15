<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

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
        /**
         * `payout` (renvoyé par Technas), `card` (carte, depuis VOTRE compte carte), `external` (hors plateforme).
         * Un ancien `stripe` reçu de l'API est lu comme `card`.
         */
        public readonly string $kind,
        public readonly ?string $method,
        public readonly ?string $note,
        public readonly ?string $recordedBy,
        public readonly ?string $createdAt,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'id'), D::str($r, 'payment_id'), D::str($r, 'payment_reference'),
            D::num($r, 'amount'), D::num($r, 'sent_amount'), D::num($r, 'fee'), D::str($r, 'fees', 'partner'),
            D::num($r, 'balance_debit'), D::str($r, 'currency'), D::str($r, 'status'),
            D::nstr($r, 'failure'), self::kind(D::str($r, 'kind', 'payout')), D::nstr($r, 'method'), D::nstr($r, 'note'),
            D::nstr($r, 'recorded_by'), D::nstr($r, 'created_at'),
        );
    }

    /** `stripe` (ancien nom, période de transition) devient `card`. */
    private static function kind(string $kind): string
    {
        return $kind === 'stripe' ? 'card' : $kind;
    }
}
