<?php

declare(strict_types=1);

namespace TechnasPay\App;

use TechnasPay\Internal\Data as D;

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

    /** Un `AppOrder`, ou un tableau aux mêmes clés (`amount`, `customer`, `currency`…). */
    public static function from(mixed $raw): ?self
    {
        if ($raw instanceof self) {
            return $raw;
        }
        if (!is_array($raw)) {
            return null;
        }
        $customer = D::map($raw, 'customer');
        $c = ['email' => D::str($customer, 'email'), 'name' => D::str($customer, 'name')];
        foreach (['phone', 'country'] as $k) {
            if (D::nstr($customer, $k) !== null) {
                $c[$k] = D::str($customer, $k);
            }
        }
        return new self(D::num($raw, 'amount'), $c, D::nstr($raw, 'currency'), D::nstr($raw, 'description'),
            D::nstr($raw, 'returnUrl') ?? D::nstr($raw, 'return_url'), D::map($raw, 'metadata'));
    }
}
