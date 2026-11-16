<?php

declare(strict_types=1);

namespace TechnasPay\App;

use TechnasPay\Internal\Data as D;
use TechnasPay\Model\Payment;
use TechnasPay\Status;
use TechnasPay\TechnasPay;
use TechnasPay\TechnasPayException;
use TechnasPay\Webhook\WebhookHandlers;

/**
 * Routes toutes faites pour une app (`technas_pay_flutter`, `TechnasPayClient`) : contrat
 * `contract/app-contract.v1.json`, rejoué ici ET par les SDK Node, Python, Dart et le client Flutter.
 *
 * Routes : `GET /methods?orderId=`, `POST /payments` `{orderId, method?}`, `GET /payments/{id}`,
 * `POST /payments/{id}/cancel` (le client change d'offre : l'essai affiché est annulé),
 * `POST /webhook`, et pour le marchand `GET /card/account`, `POST /card/onboarding`.
 *
 * Vous ne fournissez que `authorize` (votre authentification) et `resolveOrder` (la commande relue
 * EN BASE). Le montant ne vient jamais de l'app. Les essais expirés sont sautés tout seuls ; un essai
 * d'un autre montant (le client a changé d'offre) est annulé s'il est encore ouvert, puis sauté. Payé,
 * ou demande en cours chez l'opérateur : aucun second paiement, l'erreur remonte à l'app.
 */
final class AppRoutes
{
    /** @var callable */
    private $authorize;
    /** @var callable */
    private $resolveOrder;
    /** @var callable|null */
    private $authorizeAdmin;

    public function __construct(
        private readonly TechnasPay $pay,
        callable $authorize,
        callable $resolveOrder,
        private readonly ?string $returnUrl = null,
        private readonly ?WebhookHandlers $webhooks = null,
        private readonly bool $card = false,
        ?callable $authorizeAdmin = null,
        private readonly int $maxAttempts = 20,
    ) {
        $this->authorize = $authorize;
        $this->resolveOrder = $resolveOrder;
        $this->authorizeAdmin = $authorizeAdmin;
    }

    private static function err(int $status, string $code, string $message, bool $retryable = false): AppResponse
    {
        return new AppResponse($status, ['code' => $code, 'message' => $message, 'retryable' => $retryable]);
    }

    private static function fromPayError(TechnasPayException $e): AppResponse
    {
        // Panne ou réseau côté Technas : 502, à relire plus tard. Sinon le refus est transmis tel quel.
        if ($e->errorCode === 'network_error' || $e->status >= 500 || $e->status === 0) {
            return self::err(502, $e->errorCode, $e->getMessage(), true);
        }
        return self::err($e->status, $e->errorCode, $e->getMessage(), $e->retryable());
    }

    /** `42-3` ou `42-card-3` → `42`. */
    private static function orderIdOf(string $reference): string
    {
        return preg_match('/^(.+?)-(?:card-)?\d+$/', $reference, $m) ? $m[1] : $reference;
    }

    private function resolve(string $orderId, mixed $user): ?AppOrder
    {
        return AppOrder::from(($this->resolveOrder)($orderId, $user));
    }

    private function start(string $orderId, AppOrder $order, string $method): Payment
    {
        // Une référence = un essai : `orderId-1`, `-2`… (carte : `orderId-card-1`…). Un essai expiré lève
        // payment_expired, un essai d'un autre montant (le client a changé d'offre) lève reference_conflict :
        // dans les deux cas on passe au suivant, jamais un refus. Un essai en cours (ou payé) est rendu tel quel.
        for ($attempt = 1; ; $attempt++) {
            $input = [
                'amount' => $order->amount,
                'reference' => $method === 'card' ? "$orderId-card-$attempt" : Status::retryReference($orderId, $attempt),
                'customer' => $order->customer,
                'returnUrl' => $order->returnUrl ?? $this->returnUrl ?? '',
                'metadata' => $order->metadata + ['order_id' => $orderId],
            ];
            if ($method === 'card') {
                $input['method'] = 'card';
            }
            if ($order->currency !== null) {
                $input['currency'] = $order->currency;
            }
            if ($order->description !== null) {
                $input['description'] = $order->description;
            }
            try {
                return $this->pay->askForPayment($input);
            } catch (TechnasPayException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
                if ($e->errorCode === 'payment_expired') {
                    continue; // essai expiré : le suivant
                }
                if ($e->errorCode !== 'reference_conflict') {
                    throw $e;
                }
                // Le client a changé d'offre (autre montant) : l'ancien essai est annulé s'il est encore
                // ouvert, puis le suivant est créé. Payé ou demande en cours chez l'opérateur : aucun
                // second paiement, l'erreur remonte à l'app.
                $this->closePrevious($input['reference']);
            }
        }
    }

    private function closePrevious(string $reference): void
    {
        try {
            $previous = $this->pay->getPaymentByReference($reference);
        } catch (TechnasPayException $e) {
            if ($e->errorCode === 'payment_not_found') {
                return;
            }
            throw $e;
        }
        if ($previous->status === 'completed') {
            throw new TechnasPayException('payment_already_completed', "L'essai précédent de cette commande est déjà payé", 409);
        }
        if ($previous->status !== 'expired') {
            try {
                $this->pay->cancelPayment($previous->id);
            } catch (TechnasPayException $e) {
                // 404 (paiement introuvable, ou serveur qui n'a pas encore l'annulation) : essai suivant,
                // comme avant. Tout autre refus remonte : aucun second paiement.
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
    }

    /** @return array{method: string, available: bool, reason: ?string} */
    private function cardMethod(AppOrder $order): array
    {
        if (!$this->card) {
            return ['method' => 'card', 'available' => false, 'reason' => 'not_enabled'];
        }
        if ($order->currency === null) {
            return ['method' => 'card', 'available' => false, 'reason' => 'currency_required'];
        }
        return $this->pay->getCardAccount()->canAcceptPayments
            ? ['method' => 'card', 'available' => true, 'reason' => null]
            : ['method' => 'card', 'available' => false, 'reason' => 'card_account_not_ready'];
    }

    /** @return array<string, mixed>|null */
    private static function jsonBody(AppRequest $req): ?array
    {
        if ($req->rawBody === '') {
            return [];
        }
        try {
            $d = json_decode($req->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($d) ? D::asMap($d) : null;
    }

    private function cardRoutes(AppRequest $req, string $path): AppResponse
    {
        if ($this->authorizeAdmin === null) {
            return self::err(404, 'not_found', 'Route inconnue');
        }
        $admin = ($this->authorizeAdmin)($req);
        if ($admin === null || $admin === false) {
            return self::err(403, 'forbidden', 'Réservé au marchand');
        }
        try {
            if ($path === '/card/account') {
                return new AppResponse(200, ['account' => $this->pay->getCardAccount()->toAppJson()]);
            }
            $b = self::jsonBody($req);
            if ($b === null) {
                return self::err(400, 'invalid_request', 'Corps JSON illisible');
            }
            $returnUrl = D::nstr($b, 'returnUrl');
            $refreshUrl = D::nstr($b, 'refreshUrl');
            if ($returnUrl === null || $refreshUrl === null) {
                return self::err(400, 'invalid_request', 'returnUrl et refreshUrl sont obligatoires');
            }
            $link = $this->pay->startCardOnboarding($returnUrl, $refreshUrl, D::nstr($b, 'country'), D::nstr($b, 'email'), D::nstr($b, 'businessType'));
            return new AppResponse(200, ['url' => $link->url, 'kind' => $link->kind, 'expires_at' => $link->expiresAt, 'account' => $link->account->toAppJson()]);
        } catch (TechnasPayException $e) {
            return self::fromPayError($e);
        }
    }

    /** Le cœur, sans framework. */
    public function handle(AppRequest $req): AppResponse
    {
        $path = '/' . trim($req->path, '/');
        $method = strtoupper($req->method);

        if ($method === 'POST' && $path === '/webhook') {
            if ($this->webhooks === null) {
                return self::err(404, 'not_found', 'Webhook non branché');
            }
            $r = $this->pay->handleWebhook($req->rawBody, $req->header('x-technas-payment-signature'), $this->webhooks);
            return new AppResponse($r['status'], $r['body']);
        }

        if (($method === 'GET' && $path === '/card/account') || ($method === 'POST' && $path === '/card/onboarding')) {
            return $this->cardRoutes($req, $path);
        }

        $paymentId = preg_match('#^/payments/([^/]+)$#', $path, $pm) === 1 ? rawurldecode($pm[1]) : '';
        $cancelId = preg_match('#^/payments/([^/]+)/cancel$#', $path, $cm) === 1 ? rawurldecode($cm[1]) : '';
        $isCancel = $cancelId !== '';
        $known = ($method === 'GET' && ($path === '/methods' || $paymentId !== ''))
            || ($method === 'POST' && ($path === '/payments' || $isCancel));
        if (!$known) {
            return self::err(404, 'not_found', 'Route inconnue');
        }

        $user = ($this->authorize)($req);
        if ($user === null || $user === false) {
            return self::err(401, 'unauthorized', 'Connexion requise');
        }

        try {
            if ($path === '/methods') {
                $orderId = $req->query['orderId'] ?? '';
                if ($orderId === '') {
                    return self::err(400, 'invalid_request', 'orderId manquant');
                }
                $order = $this->resolve($orderId, $user);
                if ($order === null) {
                    return self::err(404, 'order_not_found', 'Commande introuvable');
                }
                return new AppResponse(200, [
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'methods' => [['method' => 'mobile_money', 'available' => true, 'reason' => null], $this->cardMethod($order)],
                ]);
            }

            if ($path === '/payments') {
                $b = self::jsonBody($req);
                if ($b === null) {
                    return self::err(400, 'invalid_request', 'Corps JSON illisible');
                }
                $orderId = (is_string($b['orderId'] ?? null) || is_int($b['orderId'] ?? null)) ? (string) $b['orderId'] : '';
                if ($orderId === '') {
                    return self::err(400, 'invalid_request', 'orderId manquant');
                }
                $payMethod = $b['method'] ?? 'mobile_money';
                if ($payMethod !== 'mobile_money' && $payMethod !== 'card') {
                    return self::err(400, 'invalid_request', 'method vaut "mobile_money" ou "card"');
                }
                if ($payMethod === 'card' && !$this->card) {
                    return self::err(400, 'method_not_available', "Le paiement par carte n'est pas ouvert");
                }
                $order = $this->resolve($orderId, $user);
                if ($order === null) {
                    return self::err(404, 'order_not_found', 'Commande introuvable');
                }
                if ($payMethod === 'card' && $order->currency === null) {
                    return self::err(400, 'currency_required', "La commande n'a pas de devise : carte impossible");
                }
                return new AppResponse(200, ['payment' => $this->start($orderId, $order, $payMethod)->toAppJson()]);
            }

            // GET /payments/{id} et POST /payments/{id}/cancel : seulement si la commande de ce paiement
            // est à cet utilisateur.
            try {
                $payment = $this->pay->getPayment($isCancel ? $cancelId : $paymentId);
            } catch (TechnasPayException $e) {
                if ($e->errorCode === 'payment_not_found') {
                    return self::err(404, 'payment_not_found', 'Paiement introuvable');
                }
                throw $e;
            }
            if ($this->resolve(self::orderIdOf($payment->reference), $user) === null) {
                return self::err(404, 'payment_not_found', 'Paiement introuvable');
            }
            if ($isCancel) {
                // Le client change d'offre : l'essai affiché est annulé avant d'en démarrer un autre.
                $payment = $this->pay->cancelPayment($payment->id);
            }
            return new AppResponse(200, ['payment' => $payment->toAppJson()]);
        } catch (TechnasPayException $e) {
            return self::fromPayError($e);
        }
    }
}
