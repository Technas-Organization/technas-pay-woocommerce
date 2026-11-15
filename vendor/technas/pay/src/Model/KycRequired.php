<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Contenu de l'avis `account.kyc_required` : prévenez le RESPONSABLE du compte (pas le client).
 *
 * `reason` : `amount_80` (80 % du seuil encaissés), `deadline_7d` (échéance dans 7 jours) ou
 * `blocked` (premier refus : les nouveaux paiements mobile money sont refusés). Une valeur que
 * cette version ne connaît pas est gardée telle quelle. Le responsable dépose sa pièce sur `consoleUrl`.
 *
 * Champs de `GET /v1/account/kyc` repris par l'avis, tous FACULTATIFS (`null` sur un avis plus ancien) :
 * `stage` (`ok`, `soon`, `blocked`, `verified`, `not_applicable`), `blockReason` (`amount`,
 * `duration` ou `null`), `daysElapsed`, `graceDays`, `firstCollectedAt`. `reason` reste le DÉCLENCHEUR de l'avis.
 */
final class KycRequired
{
    public function __construct(
        public readonly string $reason,
        public readonly int $collectedXof,
        public readonly int $thresholdXof,
        public readonly ?string $deadline,
        public readonly bool $blocked,
        public readonly string $consoleUrl,
        public readonly ?string $stage = null,
        public readonly ?string $blockReason = null,
        public readonly ?int $daysElapsed = null,
        public readonly ?int $graceDays = null,
        public readonly ?string $firstCollectedAt = null,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'reason'), D::int($r, 'collected_xof'), D::int($r, 'threshold_xof'),
            D::nstr($r, 'deadline'), D::bool($r, 'blocked'), D::str($r, 'console_url'),
            D::nstr($r, 'stage'), D::nstr($r, 'block_reason'), D::nint($r, 'days_elapsed'),
            D::nint($r, 'grace_days'), D::nstr($r, 'first_collected_at'),
        );
    }
}
