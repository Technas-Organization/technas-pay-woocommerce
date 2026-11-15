<?php

declare(strict_types=1);

namespace TechnasPay\Model;

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

    /** @param array<string, mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self((string) $r['url'], (string) $r['kind'], $r['expires_at'] ?? null, CardAccount::fromApi($r['account'] ?? []));
    }
}
