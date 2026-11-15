<?php

declare(strict_types=1);

namespace TechnasPay\Http;

use TechnasPay\TechnasPayException;

/** Transport par défaut, sur l'extension cURL (aucune dépendance). */
final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds >= 1');
        }
    }

    #[\Override]
    public function send(string $method, string $url, array $headers, ?string $body, bool $followRedirects = true): array
    {
        if ($method === '') {
            throw new \InvalidArgumentException('method vide');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TechnasPayException('network_error', 'Service de paiement injoignable');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            // TLS toujours vérifié (certificat ET nom d'hôte) : jamais désactivable par une option.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            // Une redirection ne part jamais en clair, et pas plus de 3 fois.
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 3,
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
