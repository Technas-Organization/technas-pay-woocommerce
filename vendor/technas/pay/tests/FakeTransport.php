<?php

declare(strict_types=1);

namespace TechnasPay\Tests;

use TechnasPay\Http\Transport;
use TechnasPay\TechnasPayException;

/** Rend les réponses prévues, dans l'ordre, et note chaque appel. */
final class FakeTransport implements Transport
{
    /** @var array<int, array{method: string, url: string, headers: array<string,string>, body: ?string, follow: bool}> */
    public array $calls = [];

    /** @param array<int, array{status: int, body: mixed}|\Closure> $queue */
    public function __construct(public array $queue = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, bool $followRedirects = true): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'follow' => $followRedirects];
        if ($this->queue === []) {
            throw new \LogicException("appel imprévu à la passerelle : $method $url");
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Closure) {
            return $next($method, $url, $body);
        }
        if ($next['status'] === 0) {
            throw new TechnasPayException('network_error', 'Service de paiement injoignable');
        }
        return ['status' => $next['status'], 'body' => $next['body'] === null ? '<html>panne</html>' : json_encode($next['body'])];
    }

    /** @return array<string, mixed>|null */
    public function jsonBody(int $i): ?array
    {
        return $this->calls[$i]['body'] === null ? null : json_decode($this->calls[$i]['body'], true);
    }
}
