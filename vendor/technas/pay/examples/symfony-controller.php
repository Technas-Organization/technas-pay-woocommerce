<?php
// src/Controller/TechnasPayController.php (Symfony 6.4+)

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TechnasPay\Bridge\SymfonyBridge;
use TechnasPay\TechnasPay;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\WebhookHandlers;

final class TechnasPayController
{
    public function __construct(private OrderRepository $orders, private Security $security)
    {
    }

    #[Route('/api/technas-pay/{path}', requirements: ['path' => '.+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $path): Response
    {
        $routes = (new TechnasPay($_ENV['TECHNAS_PAY_SECRET'] ?? null))->appRoutes(
            authorize: fn () => $this->security->getUser(),
            resolveOrder: function (string $orderId, $user) {
                $o = $this->orders->findOneBy(['id' => $orderId, 'owner' => $user]);
                return $o ? ['amount' => $o->getTotal(), 'customer' => ['email' => $user->getEmail(), 'name' => $user->getName()]] : null;
            },
            returnUrl: 'https://votre-site.example/paiement/retour',
            webhooks: new WebhookHandlers(onCompleted: fn (Event $e) => $this->orders->markPaid($e->payment->metadata['order_id'] ?? '')),
        );
        return SymfonyBridge::handle($routes, $request, $path);
    }
}
