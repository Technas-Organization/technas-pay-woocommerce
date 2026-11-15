<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Le seuil d'identité de votre compte (`getAccountKyc()`), à montrer au MARCHAND, jamais au client.
 *
 * En réel, un compte sans pièce d'identité vérifiée voit ses NOUVEAUX paiements mobile money
 * refusés (`kyc_required`) au-delà de `thresholdXof` F CFA encaissés en cumul, ou `graceDays`
 * jours après son premier paiement réel encaissé. Lisez ces chiffres ici, ne les écrivez jamais
 * en dur. Le responsable dépose sa pièce sur `consoleUrl`.
 *
 * `stage` : `ok`, `soon` (seuil proche), `blocked`, `verified`, `not_applicable`.
 * `level` : `none`, `identity`, `business`. `reason` (si bloqué) : `amount` ou `duration`.
 * Une valeur que cette version ne connaît pas est gardée telle quelle.
 */
final class AccountKyc
{
    public function __construct(
        public readonly bool $applies,
        public readonly string $stage,
        public readonly string $level,
        public readonly ?int $daysElapsed,
        public readonly int $warnFromXof,
        public readonly ?string $warnFromDate,
        public readonly int $collectedXof,
        public readonly int $thresholdXof,
        public readonly int $graceDays,
        public readonly ?string $firstCollectedAt,
        public readonly ?string $deadline,
        public readonly ?string $waiverUntil,
        public readonly bool $blocked,
        public readonly ?string $reason,
        public readonly string $consoleUrl,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::bool($r, 'applies'), D::str($r, 'stage'), D::str($r, 'level'), D::nint($r, 'days_elapsed'),
            D::int($r, 'warn_from_xof'), D::nstr($r, 'warn_from_date'), D::int($r, 'collected_xof'),
            D::int($r, 'threshold_xof'), D::int($r, 'grace_days'), D::nstr($r, 'first_collected_at'),
            D::nstr($r, 'deadline'), D::nstr($r, 'waiver_until'), D::bool($r, 'blocked'),
            D::nstr($r, 'reason'), D::str($r, 'console_url'),
        );
    }
}
