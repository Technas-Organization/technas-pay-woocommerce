<?php

declare(strict_types=1);

namespace TechnasPay\Internal;

/**
 * Lecture typée d'une donnée reçue (réponse de l'API, avis, requête de l'app). Une valeur du
 * mauvais type devient la valeur par défaut : jamais une conversion silencieuse d'un tableau en
 * chaîne, ni d'une chaîne en montant.
 *
 * @internal
 */
final class Data
{
    /** @return array<string, mixed> */
    public static function asMap(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $item) {
            if (is_string($k)) {
                $out[$k] = $item;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function asMaps(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $out[] = self::asMap($item);
            }
        }
        return $out;
    }

    /** @param array<mixed> $a */
    public static function str(array $a, string $k, string $default = ''): string
    {
        $v = $a[$k] ?? null;
        return is_string($v) ? $v : (is_int($v) ? (string) $v : $default);
    }

    /** @param array<mixed> $a */
    public static function nstr(array $a, string $k): ?string
    {
        $v = $a[$k] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<mixed> $a */
    public static function num(array $a, string $k): int|float
    {
        $v = $a[$k] ?? null;
        return is_int($v) || is_float($v) ? $v : 0;
    }

    /** @param array<mixed> $a */
    public static function nnum(array $a, string $k): int|float|null
    {
        $v = $a[$k] ?? null;
        return is_int($v) || is_float($v) ? $v : null;
    }

    /**
     * Un entier ; un nombre décimal entier (`5000.0`) est accepté, rien d'autre.
     *
     * @param array<mixed> $a
     */
    public static function int(array $a, string $k): int
    {
        return self::nint($a, $k) ?? 0;
    }

    /** @param array<mixed> $a */
    public static function nint(array $a, string $k): ?int
    {
        $v = $a[$k] ?? null;
        if (is_int($v)) {
            return $v;
        }
        return is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 2 ** 53 ? (int) $v : null;
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $k): bool
    {
        return ($a[$k] ?? false) === true;
    }

    /**
     * @param array<mixed> $a
     * @return array<string, mixed>
     */
    public static function map(array $a, string $k): array
    {
        return self::asMap($a[$k] ?? null);
    }

    /**
     * @param array<mixed> $a
     * @return array<string, mixed>|null
     */
    public static function nmap(array $a, string $k): ?array
    {
        return is_array($a[$k] ?? null) ? self::asMap($a[$k]) : null;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    public static function strings(array $a, string $k): array
    {
        $v = $a[$k] ?? null;
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /**
     * @param array<mixed> $a
     * @return list<string>|null
     */
    public static function nstrings(array $a, string $k): ?array
    {
        return is_array($a[$k] ?? null) ? self::strings($a, $k) : null;
    }
}
