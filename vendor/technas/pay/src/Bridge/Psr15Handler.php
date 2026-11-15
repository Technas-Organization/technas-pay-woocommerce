<?php

declare(strict_types=1);

namespace TechnasPay\Bridge;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TechnasPay\App\AppRequest;
use TechnasPay\App\AppRoutes;

/**
 * Handler PSR-15 (Slim, Mezzio, tout framework PSR-7). `$basePath` : le préfixe de montage
 * (`/api/technas-pay`), retiré du chemin de la requête.
 */
final class Psr15Handler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AppRoutes $routes,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly string $basePath = '/api/technas-pay',
    ) {
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $prefix = '/' . trim($this->basePath, '/');
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = $values[0] ?? '';
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $r = $this->routes->handle(new AppRequest(
            $request->getMethod(),
            $path,
            self::query($request->getQueryParams()),
            $headers,
            (string) $body,
            $request,
        ));
        return $this->responses->createResponse($r->status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($r->json()));
    }

    /** @return array<string, string> */
    private static function query(mixed $params): array
    {
        $out = [];
        foreach (is_array($params) ? $params : [] as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v))) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }
}
