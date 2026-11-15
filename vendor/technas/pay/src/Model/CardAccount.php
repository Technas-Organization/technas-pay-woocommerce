<?php

declare(strict_types=1);

namespace TechnasPay\Model;

/**
 * Où en est votre compte de paiement par carte : `not_started`, `onboarding`, `under_review`,
 * `restricted` (`requirements`, `disabledReason`) ou `enabled`.
 * `canAcceptPayments` est la seule condition pour `askForPayment(['method' => 'card', …])`.
 */
final class CardAccount
{
    /** @param string[] $requirements */
    public function __construct(
        public readonly string $status,
        public readonly bool $canAcceptPayments,
        public readonly bool $payoutsEnabled,
        public readonly bool $detailsSubmitted,
        public readonly ?string $country,
        public readonly array $requirements,
        public readonly ?string $disabledReason,
        public readonly bool $testMode,
        public readonly ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            (string) ($r['status'] ?? ''), (bool) ($r['can_accept_payments'] ?? false), (bool) ($r['payouts_enabled'] ?? false),
            (bool) ($r['details_submitted'] ?? false), $r['country'] ?? null, array_values($r['requirements'] ?? []),
            $r['disabled_reason'] ?? null, (bool) ($r['test_mode'] ?? false), $r['updated_at'] ?? null,
        );
    }

    /** Ce que l'app voit du compte carte : ni pays, ni identifiants. @return array<string, mixed> */
    public function toAppJson(): array
    {
        return [
            'status' => $this->status,
            'can_accept_payments' => $this->canAcceptPayments,
            'payouts_enabled' => $this->payoutsEnabled,
            'requirements' => $this->requirements,
            'disabled_reason' => $this->disabledReason,
        ];
    }
}
