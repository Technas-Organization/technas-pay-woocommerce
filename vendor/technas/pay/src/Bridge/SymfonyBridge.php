<?php

declare(strict_types=1);

namespace TechnasPay\Bridge;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TechnasPay\App\AppRequest;
use TechnasPay\App\AppRoutes;

/**
 * Laravel et Symfony (la requête de Laravel EST celle de Symfony).
 *
 * Laravel (`routes/api.php`) :
 *     Route::any('technas-pay/{path}', fn (Request $r, string $path) => SymfonyBridge::handle($routes, $r, $path))
 *         ->where('path', '.*');
 *     // et exclure `api/technas-pay/webhook` du CSRF si la route est dans `web.php`.
 *
 * Symfony (contrôleur) :
 *     #[Route('/api/technas-pay/{path}', requirements: ['path' => '.+'])]
 *     public function pay(Request $r, string $path): Response { return SymfonyBridge::handle($this->routes, $r, $path); }
 */
final class SymfonyBridge
{
    public static function handle(AppRoutes $routes, Request $request, string $path): JsonResponse
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        $r = $routes->handle(new AppRequest(
            $request->getMethod(),
            '/' . ltrim($path, '/'),
            self::query($request->query->all()),
            $headers,
            $request->getContent(), // corps BRUT : la signature du webhook porte dessus
            $request,
        ));
        return new JsonResponse($r->body, $r->status);
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
