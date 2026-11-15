<?php

declare(strict_types=1);

namespace TechnasPay;

use TechnasPay\App\AppRoutes;
use TechnasPay\Http\CurlTransport;
use TechnasPay\Http\Transport;
use TechnasPay\Model\CardAccount;
use TechnasPay\Model\CardOnboardingLink;
use TechnasPay\Model\Payment;
use TechnasPay\Model\Refund;
use TechnasPay\Model\TopUp;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\Signature;
use TechnasPay\Webhook\WebhookHandlers;

/**
 * Client SERVEUR de Technas Pay. Le secret (`tps_test_…` / `tps_live_…`) ne voyage que dans
 * l'en-tête `Authorization` : jamais dans une URL, un message d'erreur, un log ni un `var_dump`.
 *
 * `new TechnasPay()` lit `TECHNAS_PAY_SECRET` au premier appel. Jamais dans une page envoyée au
 * navigateur ni dans une app mobile.
 */
final class TechnasPay
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://pay-api.technas.fr';
    public const SECRET_ENV = 'TECHNAS_PAY_SECRET';

    /** @var array{mode: string, apiKey: string, webhookSecret: string}|null */
    private ?array $parsed = null;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $secret = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?Transport $transport = null,
        private readonly int $webhookTolerance = Signature::DEFAULT_TOLERANCE,
        private readonly ?string $defaultCurrency = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
    }

    /** Ni secret ni clé dans un `var_dump` ou `print_r`. @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** @return array{mode: string, apiKey: string, webhookSecret: string} */
    private function secrets(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }
        $raw = $this->secret ?? (getenv(self::SECRET_ENV) ?: ($_ENV[self::SECRET_ENV] ?? null));
        // Le message ne cite jamais la valeur reçue : elle finirait dans des journaux.
        if (!is_string($raw) || !preg_match('/^tps_(live|test)_([^.\s]+)\.([^.\s]+)$/', $raw, $m)) {
            throw new TechnasPayException('invalid_secret', 'Secret Technas absent ou mal formé. Vérifiez la variable ' . self::SECRET_ENV . '.');
        }
        return $this->parsed = ['mode' => $m[1], 'apiKey' => $m[2], 'webhookSecret' => $m[3]];
    }

    /** Vrai avec un secret `tps_test_` : aucun argent ne circule. */
    public function isTestMode(): bool
    {
        return $this->secrets()['mode'] === 'test';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    private function call(string $method, string $path, ?array $body = null)
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secrets()['apiKey'], 'User-Agent' => 'technas-pay-php/' . self::VERSION, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $r = $this->transport->send($method, $this->baseUrl . $path, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $data = $r['body'] === '' ? null : json_decode($r['body'], true);
        if ($r['status'] >= 400) {
            throw TechnasPayException::fromResponse($r['status'], $data);
        }
        return $data;
    }

    private static function positive(mixed $amount, bool $integer): void
    {
        $ok = $integer ? (is_int($amount) && $amount > 0) : ((is_int($amount) || is_float($amount)) && is_finite((float) $amount) && $amount > 0);
        if (!$ok) {
            throw new TechnasPayException('invalid_amount', $integer
                ? 'amount doit être un entier positif (5000 = 5 000 FCFA)'
                : 'amount doit être positif (5000 = 5 000 FCFA, 12.5 = 12,50 €)');
        }
    }

    private static function seg(string $id): string
    {
        return rawurlencode($id);
    }

    // --- Paiements -----------------------------------------------------------------------------

    /**
     * Crée la demande de paiement ; redirigez ensuite le client (303) vers `$payment->checkoutUrl`.
     *
     * Champs : `amount` (entier en F CFA, jamais × 100 ; décimales seulement par carte), `reference`
     * (UNE RÉFÉRENCE = UN ESSAI : `Status::retryReference($orderId, $attempt)`), `customer`
     * (`['email' => …, 'name' => …, 'phone' => ?, 'country' => ?]`), `returnUrl`.
     * Facultatifs : `method` (`mobile_money` par défaut, ou `card` qui exige `currency`), `currency`
     * (ne la passez pas en zone franc CFA : elle suit le pays du client), `description`, `metadata`,
     * `allowedCountries`, `excludedCountries`, `askCountry`.
     *
     * @param array<string, mixed> $input
     */
    public function askForPayment(array $input): Payment
    {
        $method = $input['method'] ?? 'mobile_money';
        if ($method !== 'mobile_money' && $method !== 'card') {
            throw new TechnasPayException('invalid_request', 'method vaut "mobile_money" ou "card"');
        }
        self::positive($input['amount'] ?? null, $method === 'mobile_money');
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($currency === '') {
            $country = trim((string) ($input['customer']['country'] ?? ''));
            $currency = $country !== '' ? (Status::currencyForCountry($country) ?? '') : strtoupper(trim((string) $this->defaultCurrency));
        }
        if ($method === 'card' && $currency === '') {
            throw new TechnasPayException('currency_required', 'currency est obligatoire pour un paiement par carte (EUR, XOF…)');
        }
        $body = [
            'amount' => $input['amount'],
            'reference' => (string) ($input['reference'] ?? ''),
            'customer' => $input['customer'] ?? [],
            'return_url' => (string) ($input['returnUrl'] ?? ''),
            'metadata' => (object) ($input['metadata'] ?? []),
        ];
        if ($method === 'card') {
            $body['method'] = 'card';
        }
        if ($currency !== '') {
            $body['currency'] = $currency;
        }
        foreach (['description' => 'description', 'allowedCountries' => 'allowed_countries', 'excludedCountries' => 'excluded_countries'] as $in => $out) {
            if (isset($input[$in])) {
                $body[$out] = $input[$in];
            }
        }
        if (!empty($input['askCountry'])) {
            $body['ask_country'] = true;
        }
        return Payment::fromApi($this->call('POST', '/v1/payments', $body));
    }

    public function getPayment(string $id): Payment
    {
        return Payment::fromApi($this->call('GET', '/v1/payments/' . self::seg($id)));
    }

    /** Pour rattraper un webhook manqué. */
    public function getPaymentByReference(string $reference): Payment
    {
        return Payment::fromApi($this->call('GET', '/v1/payments?' . http_build_query(['reference' => $reference])));
    }

    /** @return array{items: Payment[], nextCursor: ?string} */
    public function listTransactions(?int $limit = null, ?string $status = null, ?string $cursor = null): array
    {
        $q = http_build_query(array_filter(['limit' => $limit, 'status' => $status, 'cursor' => $cursor], fn ($v) => $v !== null && $v !== ''));
        $raw = $this->call('GET', '/v1/transactions' . ($q === '' ? '' : "?$q"));
        return ['items' => array_map([Payment::class, 'fromApi'], $raw['items'] ?? []), 'nextCursor' => $raw['next_cursor'] ?? null];
    }

    /** Mode test : `completed`, `failed` (pas définitif) ou `expired` (définitif), sans navigateur. */
    public function simulateTestPayment(string $paymentId, string $outcome): Payment
    {
        if ($this->secrets()['mode'] !== 'test') {
            throw new TechnasPayException('test_only', 'simulateTestPayment exige un secret tps_test_');
        }
        if (!in_array($outcome, ['completed', 'failed', 'expired'], true)) {
            throw new TechnasPayException('invalid_outcome', 'outcome : completed, failed ou expired');
        }
        // Un paiement réussi renvoie vers votre returnUrl : on ne la suit pas.
        $r = $this->transport->send('POST', $this->baseUrl . '/test-checkout/' . self::seg($paymentId) . '/' . $outcome, ['User-Agent' => 'technas-pay-php/' . self::VERSION], null, false);
        if ($r['status'] === 404) {
            throw new TechnasPayException('payment_not_found', 'Paiement de test introuvable', 404);
        }
        return $this->getPayment($paymentId);
    }

    // --- Remboursements ------------------------------------------------------------------------

    /**
     * Rembourse un paiement `completed`, au numéro (ou à la carte) qui a payé. Sans `$amount` : tout
     * ce qui reste. `$fees` : `partner` (défaut) ou `customer`. Réservé à un administrateur.
     */
    public function refund(string $paymentId, int|float|null $amount = null, string $fees = 'partner', ?string $reference = null): Refund
    {
        if ($amount !== null) {
            self::positive($amount, false);
        }
        if ($fees !== 'partner' && $fees !== 'customer') {
            throw new TechnasPayException('invalid_request', 'fees vaut "partner" ou "customer"');
        }
        $body = ['fees' => $fees];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        if ($reference !== null && $reference !== '') {
            $body['reference'] = $reference;
        }
        return Refund::fromApi($this->call('POST', '/v1/payments/' . self::seg($paymentId) . '/refunds', $body));
    }

    /** Enregistre un remboursement fait HORS Technas (Flooz, espèces…). Aucun argent ne bouge. */
    public function recordExternalRefund(string $paymentId, int $amount, string $method, ?string $note = null, ?string $recordedBy = null, ?string $reference = null): Refund
    {
        self::positive($amount, true);
        if (trim($method) === '') {
            throw new TechnasPayException('invalid_request', 'method est obligatoire : "Flooz", "Espèces"…');
        }
        $body = array_filter(['amount' => $amount, 'method' => trim($method), 'note' => $note, 'recorded_by' => $recordedBy, 'reference' => $reference], fn ($v) => $v !== null && $v !== '');
        return Refund::fromApi($this->call('POST', '/v1/payments/' . self::seg($paymentId) . '/external-refunds', $body));
    }

    public function getRefund(string $id): Refund
    {
        return Refund::fromApi($this->call('GET', '/v1/refunds/' . self::seg($id)));
    }

    // --- Solde, recharges, reversements --------------------------------------------------------

    /** Recharge votre solde : ouvrez `checkoutUrl`. Crédit : webhook `balance.topped_up`. */
    public function topUpBalance(int $amount, string $currency, ?string $returnUrl = null): TopUp
    {
        self::positive($amount, true);
        $body = ['amount' => $amount, 'currency' => $currency];
        if ($returnUrl !== null) {
            $body['return_url'] = $returnUrl;
        }
        return TopUp::fromApi($this->call('POST', '/v1/balance/top-ups', $body));
    }

    /** Ce que Technas vous doit, par devise. @return array<int, array{currency: string, amount: int|float}> */
    public function getBalance(): array
    {
        return $this->call('GET', '/v1/balance') ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getSettlements(): array
    {
        return $this->call('GET', '/v1/settlements') ?? [];
    }

    /** @return array<int, array{code: string, name: string, currency: ?string}> */
    public function getPayoutNetworks(string $country): array
    {
        return $this->call('GET', '/v1/payout-networks?' . http_build_query(['country' => $country])) ?? [];
    }

    /** Vos numéros de reversement, un par devise. @return array<string, mixed> */
    public function getPayoutDestination(): array
    {
        return $this->call('GET', '/v1/payout-destination') ?? [];
    }

    /**
     * Rien ne change avant le clic du responsable du compte dans l'e-mail reçu. Réservé à un administrateur.
     *
     * @return array<string, mixed>
     */
    public function setPayoutDestination(string $country, string $network, string $phone, string $beneficiaryName): array
    {
        return $this->call('PUT', '/v1/payout-destination', ['country' => $country, 'network' => $network, 'phone' => $phone, 'beneficiary_name' => $beneficiaryName]) ?? [];
    }

    // --- Hors zone franc CFA --------------------------------------------------------------------

    /** Votre option hors zone CFA, ses risques (`risks`), les pays ouverts. @return array<string, mixed> */
    public function getFxMode(): array
    {
        return $this->call('GET', '/v1/fx') ?? [];
    }

    /**
     * N'appelez avec `$acceptRisk = true` qu'APRÈS avoir montré `getFxMode()['risks'][$mode]` à
     * l'utilisateur et obtenu son accord explicite. `converted` lève `fx_mode_unavailable`.
     *
     * @return array<string, mixed>
     */
    public function setFxMode(string $mode, bool $acceptRisk = false, int|float|null $markupPct = null, ?string $homeCurrency = null): array
    {
        if (!in_array($mode, ['none', 'local', 'converted'], true)) {
            throw new TechnasPayException('invalid_request', 'mode vaut "none", "local" ou "converted"');
        }
        $body = ['mode' => $mode, 'accept_risk' => $acceptRisk];
        if ($markupPct !== null) {
            $body['markup_pct'] = $markupPct;
        }
        if ($homeCurrency !== null) {
            $body['home_currency'] = $homeCurrency;
        }
        return $this->call('PUT', '/v1/fx', $body) ?? [];
    }

    /**
     * @param string[]|null $allowed `null` : tous les pays
     * @param string[] $excluded
     * @return array{allowed: ?array, excluded: array}
     */
    public function setCountryRules(?array $allowed = null, array $excluded = []): array
    {
        return $this->call('PUT', '/v1/country-rules', ['allowed' => $allowed, 'excluded' => $excluded]);
    }

    // --- Carte -----------------------------------------------------------------------------------

    /**
     * Ouvre (ou reprend) votre compte carte : le RESPONSABLE ouvre `url` et y donne son identité et
     * sa banque. À appeler au clic (le lien expire en minutes). `$country` (le vôtre) la 1ʳᵉ fois.
     */
    public function startCardOnboarding(string $returnUrl, string $refreshUrl, ?string $country = null, ?string $email = null, ?string $businessType = null): CardOnboardingLink
    {
        $body = ['return_url' => $returnUrl, 'refresh_url' => $refreshUrl];
        if ($country !== null) {
            $body['country'] = strtoupper(trim($country));
        }
        if ($email !== null) {
            $body['email'] = $email;
        }
        if ($businessType !== null) {
            $body['business_type'] = $businessType;
        }
        return CardOnboardingLink::fromApi($this->call('POST', '/v1/card/onboarding', $body));
    }

    /** `canAcceptPayments` décide de tout pour `askForPayment(['method' => 'card', …])`. */
    public function getCardAccount(): CardAccount
    {
        return CardAccount::fromApi($this->call('GET', '/v1/card/account'));
    }

    /** https://dashboard.stripe.com/ : le responsable s'y connecte avec SES identifiants Stripe. */
    public function getCardDashboardLink(): string
    {
        return (string) $this->call('POST', '/v1/card/dashboard-link', [])['url'];
    }

    // --- Webhooks --------------------------------------------------------------------------------

    /** Vérifie la signature sur le corps BRUT et rend l'événement. Lève `WebhookVerificationException` (répondez 400). */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader): Event
    {
        return Signature::verify($rawBody, $signatureHeader, $this->secrets()['webhookSecret'], $this->webhookTolerance);
    }

    /**
     * Vérifie, appelle la bonne fonction et rend la réponse : `['status' => 400, …]` si la signature
     * est refusée (aucune fonction appelée), 200 sinon. Une exception de vos fonctions REMONTE : 500.
     *
     * @return array{status: int, body: array<string, mixed>, event: ?Event}
     */
    public function handleWebhook(string $rawBody, ?string $signatureHeader, WebhookHandlers $handlers): array
    {
        try {
            $event = $this->verifyWebhook($rawBody, $signatureHeader);
        } catch (Webhook\WebhookVerificationException) {
            return ['status' => 400, 'body' => ['error' => 'invalid_signature'], 'event' => null];
        }
        $fn = $handlers->forType($event->type);
        if ($fn !== null) {
            $fn($event);
        }
        return ['status' => 200, 'body' => ['received' => true], 'event' => $event];
    }

    /**
     * Webhook `payment.*` signé, pour tester votre route. Secret test seulement.
     *
     * @param array<string, mixed> $metadata
     * @return array{body: string, headers: array<string, string>}
     */
    public function createTestWebhook(string $type, string $reference, int|float $amount, string $currency, int|float $commission = 0, array $metadata = []): array
    {
        if ($this->secrets()['mode'] !== 'test') {
            throw new TechnasPayException('test_only', 'createTestWebhook exige un secret tps_test_');
        }
        if (!in_array($type, ['payment.completed', 'payment.failed', 'payment.expired'], true)) {
            throw new TechnasPayException('invalid_request', 'type : payment.completed, payment.failed ou payment.expired');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $completed = $type === 'payment.completed';
        $expired = $type === 'payment.expired';
        $body = json_encode([
            'id' => 'evt_test_' . bin2hex(random_bytes(8)),
            'type' => $type,
            'created_at' => $now,
            'data' => [
                'id' => 'pay_test_' . bin2hex(random_bytes(8)),
                'reference' => $reference,
                'status' => $completed ? 'completed' : ($expired ? 'expired' : 'failed'),
                'amount' => $amount,
                'currency' => $currency,
                'commission' => $commission,
                'net_amount' => $amount - $commission,
                'metadata' => (object) $metadata,
                'completed_at' => $completed ? $now : null,
                'expires_at' => $now,
                'expired_at' => $expired ? $now : null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['body' => $body, 'headers' => ['Content-Type' => 'application/json', Signature::HEADER => Signature::sign($body, $this->secrets()['webhookSecret'])]];
    }

    /**
     * Routes toutes faites pour votre app (`technas_pay_flutter`) : voir `AppRoutes`.
     *
     * @param callable(App\AppRequest): mixed $authorize votre authentification ; null → 401
     * @param callable(string, mixed): (App\AppOrder|array|null) $resolveOrder la commande relue EN BASE ; null → 404
     * @param (callable(App\AppRequest): mixed)|null $authorizeAdmin le MARCHAND (routes carte) ; null → 403
     */
    public function appRoutes(callable $authorize, callable $resolveOrder, ?string $returnUrl = null, ?WebhookHandlers $webhooks = null, bool $card = false, ?callable $authorizeAdmin = null, int $maxAttempts = 20): AppRoutes
    {
        return new AppRoutes($this, $authorize, $resolveOrder, $returnUrl, $webhooks, $card, $authorizeAdmin, $maxAttempts);
    }
}
