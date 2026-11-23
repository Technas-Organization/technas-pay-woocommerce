<?php

declare(strict_types=1);

namespace TechnasPay\Model;

use TechnasPay\Internal\Data as D;

/**
 * Les moyens et réseaux ouverts pour votre compte (`getPaymentMethods`, `setPaymentMethods`), ce
 * que vous avez activé (`enabled`) et votre ordre d'affichage. Une valeur que ce SDK ne connaît pas
 * encore (réseau ouvert après sa sortie) est gardée telle quelle.
 */
final class PaymentMethods
{
    /**
     * @param list<array{method: string, enabled: bool}> $methods
     * @param list<array{network: string, name: string, country: string, currency: ?string, enabled: bool}> $networks dans VOTRE ordre
     * @param list<string> $networkOrder
     * @param list<string> $allNetworks tous les réseaux que le service connaît
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $networks,
        public readonly array $networkOrder,
        public readonly array $allNetworks,
    ) {
    }

    /** @param array<mixed> $r */
    public static function fromApi(array $r): self
    {
        return new self(
            array_map(static fn (array $m): array => ['method' => D::str($m, 'method'), 'enabled' => D::bool($m, 'enabled')], D::asMaps($r['methods'] ?? null)),
            array_map(static fn (array $n): array => [
                'network' => D::str($n, 'network'),
                'name' => D::str($n, 'name'),
                'country' => D::str($n, 'country'),
                'currency' => D::nstr($n, 'currency'),
                'enabled' => D::bool($n, 'enabled'),
            ], D::asMaps($r['networks'] ?? null)),
            D::strings($r, 'network_order'),
            D::strings($r, 'all_networks'),
        );
    }
}
