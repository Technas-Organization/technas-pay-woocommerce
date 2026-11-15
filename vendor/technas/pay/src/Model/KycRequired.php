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
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'reason'), D::int($r, 'collected_xof'), D::int($r, 'threshold_xof'),
            D::nstr($r, 'deadline'), D::bool($r, 'blocked'), D::str($r, 'console_url'),
        );
    }
}
