<?php

declare(strict_types=1);

namespace TechnasPay;

/**
 * Les réseaux mobile money : le porte-monnaie que le client a dans sa poche, jamais
 * l'intermédiaire qui fait passer le paiement. Une liste fermée : `Network::ALL` à énumérer,
 * `Network::MIXX` (Mixx by Yas, ex-T-Money) ou `Network::MOOV` (Moov Money, Flooz au Togo) à
 * écrire plutôt qu'une chaîne. Ceux réellement ouverts, par pays : `$pay->getPaymentMethods($country)`.
 */
final class Network
{
    public const MIXX = 'mixx';
    public const MOOV = 'moov';
    public const MTN_MOMO = 'mtn_momo';
    public const ORANGE_MONEY = 'orange_money';
    public const WAVE = 'wave';
    public const AIRTEL_MONEY = 'airtel_money';
    public const FREE_MONEY = 'free_money';
    public const CELTIIS_CASH = 'celtiis_cash';
    public const DJAMO = 'djamo';
    public const EXPRESSO = 'expresso';
    public const WIZALL = 'wizall';
    public const MOBICASH = 'mobicash';
    public const MPESA = 'mpesa';
    public const TELECEL_CASH = 'telecel_cash';
    public const AIRTELTIGO_MONEY = 'airteltigo_money';
    public const AFRIMONEY = 'afrimoney';
    public const QMONEY = 'qmoney';
    public const TNM_MPAMBA = 'tnm_mpamba';
    public const EMOLA = 'emola';
    public const ZAMTEL_KWACHA = 'zamtel_kwacha';
    public const EZYPESA = 'ezypesa';
    public const HALOPESA = 'halopesa';
    public const TPESA = 'tpesa';

    /** Tous les réseaux, dans cet ordre. */
    public const ALL = [
        self::MIXX, self::MOOV, self::MTN_MOMO, self::ORANGE_MONEY, self::WAVE, self::AIRTEL_MONEY, self::FREE_MONEY,
        self::CELTIIS_CASH, self::DJAMO, self::EXPRESSO, self::WIZALL, self::MOBICASH, self::MPESA, self::TELECEL_CASH,
        self::AIRTELTIGO_MONEY, self::AFRIMONEY, self::QMONEY, self::TNM_MPAMBA, self::EMOLA, self::ZAMTEL_KWACHA,
        self::EZYPESA, self::HALOPESA, self::TPESA,
    ];

    private function __construct()
    {
    }

    /** Vrai si `$network` est un réseau de `ALL`. */
    public static function isValid(mixed $network): bool
    {
        return in_array($network, self::ALL, true);
    }
}
