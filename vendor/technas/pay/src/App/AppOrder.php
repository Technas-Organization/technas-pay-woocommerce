<?php

declare(strict_types=1);

namespace TechnasPay\App;

/** Ce que `resolveOrder` rend : relu en base, jamais pris de la requête de l'app. Un tableau aux mêmes clés convient aussi. */
final class AppOrder
{
    /**
     * @param array{email: string, name: string, phone?: string, country?: string} $customer
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        /** Unité principale (5000 = 5 000 FCFA ; 12.5 = 12,50 € par carte). Entier pour le mobile money. */
        public readonly int|float $amount,
        public readonly array $customer,
        /** Facultative : sans elle, la devise suit le pays du client (zone franc CFA). Obligatoire pour la carte. */
        public readonly ?string $currency = null,
        public readonly ?string $description = null,
        /** Page de retour (lien qui rouvre l'app) ; sinon `returnUrl` des routes. */
        public readonly ?string $returnUrl = null,
        public readonly array $metadata = [],
    ) {
    }

    public static function from(self|array $raw): self
    {
        if ($raw instanceof self) {
            return $raw;
        }
        return new self($raw['amount'], $raw['customer'], $raw['currency'] ?? null, $raw['description'] ?? null,
            $raw['returnUrl'] ?? ($raw['return_url'] ?? null), $raw['metadata'] ?? []);
    }
}
