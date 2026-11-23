<?php

declare(strict_types=1);

namespace TechnasPay;

/**
 * Les moyens de paiement : une liste fermée. `PaymentMethod::ALL` à énumérer ;
 * `PaymentMethod::MOBILE_MONEY`, `PaymentMethod::CARD` à écrire plutôt qu'une chaîne.
 */
final class PaymentMethod
{
    public const MOBILE_MONEY = 'mobile_money';
    public const CARD = 'card';

    /** Tous les moyens, dans cet ordre. */
    public const ALL = [self::MOBILE_MONEY, self::CARD];

    private function __construct()
    {
    }

    /** Vrai si `$method` est un moyen de `ALL`. */
    public static function isValid(mixed $method): bool
    {
        return in_array($method, self::ALL, true);
    }
}
