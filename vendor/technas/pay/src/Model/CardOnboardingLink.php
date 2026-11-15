<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

final class CardOnboardingLink
{
    public function __construct(
        /** À ouvrir par le RESPONSABLE du compte. Expire en minutes : à générer au clic. */
        public readonly string $url,
        /** `onboarding` ou `dashboard`. */
        public readonly string $kind,
        public readonly ?string $expiresAt,
        public readonly CardAccount $account,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(D::str($r, 'url'), D::str($r, 'kind'), D::nstr($r, 'expires_at'), CardAccount::fromApi(D::map($r, 'account')));
    }
}
