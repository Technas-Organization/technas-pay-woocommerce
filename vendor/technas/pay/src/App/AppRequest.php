<?php

declare(strict_types=1);

namespace TechnasPay\App;

/** La requête, vue indépendamment du framework. `path` est relatif au préfixe de montage (`/payments`…). */
final class AppRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers clés en minuscules
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $rawBody = '',
        /** L'objet de requête du framework, passé tel quel à `authorize`. */
        public readonly mixed $original = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
