<?php

declare(strict_types=1);

namespace TechnasPay\Webhook;

use TechnasPay\Internal\Data;
use TechnasPay\Model\CardAccount;
use TechnasPay\Model\Payment;
use TechnasPay\Model\Refund;

/**
 * Un événement vérifié. Selon `type`, un seul champ de contenu est rempli ; `data` garde le brut.
 * `id` est stable d'une livraison à l'autre : servez-vous-en pour ignorer un doublon.
 */
final class Event
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $createdAt,
        public readonly array $data,
        /** `payment.completed`, `payment.failed` (PAS définitif), `payment.expired` (définitif). */
        public readonly ?Payment $payment = null,
        /** `refund.completed`, `refund.failed`, `refund.recorded`. */
        public readonly ?Refund $refund = null,
        /** `card_account.updated`. */
        public readonly ?CardAccount $cardAccount = null,
    ) {
    }

    public static function parse(mixed $raw): self
    {
        if (!is_array($raw) || !is_string($raw['id'] ?? null) || !is_string($raw['type'] ?? null) || !is_array($raw['data'] ?? null)) {
            throw new WebhookVerificationException('Webhook illisible');
        }
        $d = Data::asMap($raw['data']);
        $t = $raw['type'];
        $base = [$raw['id'], $t, Data::nstr($raw, 'created_at'), $d];
        if (in_array($t, ['payment.completed', 'payment.failed', 'payment.expired'], true)) {
            if (!is_string($d['id'] ?? null)) {
                throw new WebhookVerificationException('Webhook illisible');
            }
            return new self(...$base, payment: Payment::fromApi($d));
        }
        if (in_array($t, ['refund.completed', 'refund.failed', 'refund.recorded'], true)) {
            return new self(...$base, refund: Refund::fromApi($d));
        }
        if ($t === 'card_account.updated') {
            return new self(...$base, cardAccount: CardAccount::fromApi($d));
        }
        // payout_destination.*, balance.topped_up, balance.converted, fx_mode.updated, types inconnus : `data`.
        return new self(...$base);
    }
}
