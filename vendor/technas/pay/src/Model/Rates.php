<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * La grille des frais (`$pay->getRates(...)`), par pays ouvert et par réseau : pourcentage,
 * minimum, commission Technas, estimation des frais de l'opérateur, exemple à 1 000 dans chaque mode.
 */
final class Rates
{
    /** @param list<RateCountry> $countries */
    public function __construct(
        /** Le mode de votre compte : `partner` ou `customer`. */
        public readonly string $feesPaidBy,
        public readonly array $countries,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::str($r, 'fees_paid_by', 'partner'),
            array_map(static fn (array $c): RateCountry => RateCountry::fromApi($c), D::asMaps($r['countries'] ?? null)),
        );
    }
}
