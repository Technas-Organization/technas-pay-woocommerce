<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Contenu des avis `settlement.completed` et `settlement.failed` : l'issue d'un reversement de
 * votre solde vers votre numéro mobile money.
 *
 * Tous les champs sont FACULTATIFS : un champ absent, ou d'un autre type, se lit `null`.
 * Sur `settlement.failed`, `amount` est recrédité sur votre solde.
 */
final class Settlement
{
    public function __construct(
        public readonly ?string $id = null,
        /** Ce qui a quitté votre solde. */
        public readonly int|float|null $amount = null,
        /** Frais du versement mobile money, à votre charge. */
        public readonly int|float|null $fee = null,
        /** Ce qui arrive sur votre numéro. */
        public readonly int|float|null $netAmount = null,
        public readonly ?string $currency = null,
        /** `completed` ou `failed`. Une valeur que cette version ne connaît pas est gardée telle quelle. */
        public readonly ?string $status = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            D::nstr($r, 'id'), D::nnum($r, 'amount'), D::nnum($r, 'fee'), D::nnum($r, 'net_amount'),
            D::nstr($r, 'currency'), D::nstr($r, 'status'), D::nstr($r, 'created_at'),
        );
    }
}
