<?php

declare(strict_types=1);

namespace TechnasPay\Webhook;

/**
 * `X-Technas-Payment-Signature: t=<horodatage>,s=<hmac_sha256_hex>`, HMAC de `"$t.$corpsBrut"` avec
 * la partie webhook du secret. Toujours sur le corps BRUT (`file_get_contents('php://input')`,
 * `$request->getContent()`), jamais un JSON re-sérialisé.
 */
final class Signature
{
    public const HEADER = 'X-Technas-Payment-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /** Valeur de l'en-tête pour ce corps (sert aux tests). */
    public static function sign(string $body, string $webhookSecret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        return sprintf('t=%d,s=%s', $ts, hash_hmac('sha256', "$ts.$body", $webhookSecret));
    }

    /** Vérifie la signature sur le corps BRUT et rend l'événement. */
    public static function verify(string $rawBody, ?string $header, string $webhookSecret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        if (!preg_match('/^t=(\d+),s=([0-9a-fA-F]+)$/', trim((string) $header), $m)) {
            throw new WebhookVerificationException('Signature absente ou mal formée');
        }
        $ts = (int) $m[1];
        if (abs(($now ?? time()) - $ts) > $tolerance) {
            throw new WebhookVerificationException('Signature trop ancienne');
        }
        $expected = hash_hmac('sha256', "$ts.$rawBody", $webhookSecret);
        if (!hash_equals($expected, strtolower($m[2]))) {
            throw new WebhookVerificationException('Signature invalide');
        }
        try {
            $raw = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationException('Webhook illisible');
        }
        return Event::parse($raw);
    }
}
