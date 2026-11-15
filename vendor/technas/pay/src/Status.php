<?php

declare(strict_types=1);

namespace TechnasPay;

/** Règles sans secret, les mêmes dans tous les SDK Technas Pay. */
final class Status
{
    /** Vrai si le statut ne changera plus : `completed`, `expired`, `amount_mismatch`. Jamais `failed`. */
    public static function isFinal(string $status): bool
    {
        return in_array($status, ['completed', 'expired', 'amount_mismatch'], true);
    }

    /** Vrai seulement pour `expired` : relancez avec une NOUVELLE référence. Jamais sur `failed`. */
    public static function canRetry(string $status): bool
    {
        return $status === 'expired';
    }

    /** Une référence = un essai : `"$orderId-$attempt"`. Gardez `$attempt` en base (1, 2, 3…). */
    public static function retryReference(string|int $orderId, int $attempt): string
    {
        if ($attempt < 1) {
            throw new TechnasPayException('invalid_request', 'attempt est un entier >= 1');
        }
        return "$orderId-$attempt";
    }

    private const CURRENCY_BY_COUNTRY = [
        'BJ' => 'XOF', 'BF' => 'XOF', 'CI' => 'XOF', 'GW' => 'XOF', 'ML' => 'XOF', 'NE' => 'XOF', 'SN' => 'XOF', 'TG' => 'XOF',
        'CM' => 'XAF', 'CF' => 'XAF', 'CG' => 'XAF', 'GA' => 'XAF', 'GQ' => 'XAF', 'TD' => 'XAF',
    ];

    /** La devise d'un pays de la zone franc CFA, sinon `null`. */
    public static function currencyForCountry(?string $country): ?string
    {
        return $country === null ? null : (self::CURRENCY_BY_COUNTRY[strtoupper(trim($country))] ?? null);
    }
}
