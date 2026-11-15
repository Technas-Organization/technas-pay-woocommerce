<?php
// routes/api.php (Laravel 10+) : les routes toutes faites de Technas Pay, sous /api/technas-pay/…
// composer require technas/pay (depuis GitHub, voir le README) ; TECHNAS_PAY_SECRET dans .env.

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use TechnasPay\App\AppRequest;
use TechnasPay\Bridge\SymfonyBridge;
use TechnasPay\TechnasPay;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\WebhookHandlers;

Route::any('technas-pay/{path}', function (Request $request, string $path) {
    $routes = (new TechnasPay(env('TECHNAS_PAY_SECRET')))->appRoutes(
        // VOTRE authentification (Sanctum ici) ; null → 401.
        authorize: fn (AppRequest $r) => auth('sanctum')->user(),
        // La commande de CET utilisateur, relue en base ; null → 404. Jamais un montant reçu de l'app.
        resolveOrder: function (string $orderId, $user) {
            $o = Order::where('id', $orderId)->where('user_id', $user->id)->first();
            return $o ? ['amount' => $o->total, 'customer' => ['email' => $user->email, 'name' => $user->name]] : null;
        },
        returnUrl: 'https://votre-site.example/paiement/retour',
        // Seul l'avis signé prouve le paiement. Idempotent : un avis peut arriver deux fois.
        webhooks: new WebhookHandlers(
            onCompleted: fn (Event $e) => Order::where('id', $e->payment->metadata['order_id'] ?? '')->update(['paid' => true]),
        ),
    );
    return SymfonyBridge::handle($routes, $request, $path);
})->where('path', '.*');
// Adresse d'avis à déclarer à Technas : https://votre-site.example/api/technas-pay/webhook
