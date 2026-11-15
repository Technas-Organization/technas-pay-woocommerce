<?php

declare(strict_types=1);

namespace TechnasPay\Http;

use TechnasPay\TechnasPayException;

/** Transport par défaut, sur l'extension cURL (aucune dépendance). */
final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, bool $followRedirects = true): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            throw new TechnasPayException('network_error', 'Service de paiement injoignable');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $raw];
    }
}
