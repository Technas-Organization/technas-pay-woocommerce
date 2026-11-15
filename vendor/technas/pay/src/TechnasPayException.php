<?php

declare(strict_types=1);

namespace TechnasPay;

/**
 * Une erreur rendue par Technas, ou détectée avant tout appel (`status` vaut alors 0).
 *
 * Testez `$e->errorCode` (stable), jamais le message : `payment_expired`, `reference_conflict`,
 * `risk_not_accepted`, `card_account_not_ready`, `insufficient_balance`…
 * `retryable()` : refaire LE MÊME appel, mêmes valeurs, a du sens.
 * Le message ne cite jamais le secret.
 */
class TechnasPayException extends \RuntimeException
{
    /**
     * @param array<int, array{field?: string, message?: string}>|null $errors
     * @param array{missingAmount?: int|float, currency?: string, refundableAmount?: int|float, reason?: string, risk?: string, accountStatus?: string} $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?array $errors = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** Vrai si refaire le même appel, avec la même référence, a du sens. */
    public function retryable(): bool
    {
        return in_array($this->errorCode, ['network_error', 'payment_in_progress'], true)
            || $this->status === 429
            || $this->status >= 500;
    }

    /** @param mixed $data */
    public static function fromResponse(int $status, $data): self
    {
        $d = is_array($data) ? $data : [];
        $details = [];
        foreach (['missing_amount' => 'missingAmount', 'currency' => 'currency', 'refundable_amount' => 'refundableAmount',
            'reason' => 'reason', 'risk' => 'risk', 'account_status' => 'accountStatus'] as $from => $to) {
            if (array_key_exists($from, $d) && $d[$from] !== null) {
                $details[$to] = $d[$from];
            }
        }
        return new self(
            is_string($d['code'] ?? null) ? $d['code'] : 'request_failed',
            is_string($d['message'] ?? null) ? $d['message'] : "Réponse HTTP $status",
            $status,
            is_array($d['errors'] ?? null) ? $d['errors'] : null,
            $details,
        );
    }
}
