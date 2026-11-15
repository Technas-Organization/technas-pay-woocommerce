<?php

declare(strict_types=1);

namespace TechnasPay\Http;

use TechnasPay\TechnasPayException;

/** Envoie une requête HTTP. Injectable : un faux transport sert aux tests. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws TechnasPayException `network_error` si le service est injoignable
     */
    public function send(string $method, string $url, array $headers, ?string $body, bool $followRedirects = true): array;
}
