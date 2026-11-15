<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Où en est votre compte de paiement par carte : `not_started`, `onboarding`, `under_review`,
 * `restricted` (`requirements`, `disabledReason`) ou `enabled`.
 * `canAcceptPayments` est la seule condition pour `askForPayment(['method' => 'card', …])`.
 */
final class CardAccount
{
    /** @param list<string> $requirements */
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

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'status'), D::bool($r, 'can_accept_payments'), D::bool($r, 'payouts_enabled'),
            D::bool($r, 'details_submitted'), D::nstr($r, 'country'), D::strings($r, 'requirements'),
            D::nstr($r, 'disabled_reason'), D::bool($r, 'test_mode'), D::nstr($r, 'updated_at'),
        );
    }

    /**
     * Ce que l'app voit du compte carte : ni pays, ni identifiants.
     *
     * @return array{status: string, can_accept_payments: bool, payouts_enabled: bool, requirements: list<string>, disabled_reason: ?string}
     */
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
