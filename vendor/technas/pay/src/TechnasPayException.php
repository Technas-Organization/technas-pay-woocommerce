<?php

declare(strict_types=1);

namespace TechnasPay;

use TechnasPay\Internal\Data;

/**
 * Une erreur rendue par Technas, ou détectée avant tout appel (`status` vaut alors 0).
 *
 * Testez `$e->errorCode` (stable), jamais le message : `payment_expired`, `reference_conflict`,
 * `risk_not_accepted`, `card_account_not_ready`, `insufficient_balance`…
 * `retryable()` : refaire LE MÊME appel, mêmes valeurs, a du sens.
 * Le message ne cite jamais le secret.
 */
final class TechnasPayException extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}>|null $errors
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

    public static function fromResponse(int $status, mixed $data): self
    {
        $d = Data::asMap($data);
        $details = [];
        foreach (['missing_amount' => 'missingAmount', 'refundable_amount' => 'refundableAmount'] as $from => $to) {
            if (($n = Data::nnum($d, $from)) !== null) {
                $details[$to] = $n;
            }
        }
        foreach (['currency' => 'currency', 'reason' => 'reason', 'risk' => 'risk', 'account_status' => 'accountStatus'] as $from => $to) {
            if (($v = Data::nstr($d, $from)) !== null) {
                $details[$to] = $v;
            }
        }
        $errors = null;
        if (is_array($d['errors'] ?? null)) {
            $errors = [];
            foreach (Data::asMaps($d['errors']) as $e) {
                $errors[] = ['field' => Data::str($e, 'field'), 'message' => Data::str($e, 'message')];
            }
        }
        return new self(Data::str($d, 'code', 'request_failed'), Data::str($d, 'message', "Réponse HTTP $status"), $status, $errors, $details);
    }
}
