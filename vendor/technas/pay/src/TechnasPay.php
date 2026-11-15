<?php

declare(strict_types=1);

namespace TechnasPay;

use TechnasPay\App\AppRoutes;
use TechnasPay\Http\CurlTransport;
use TechnasPay\Internal\Data as D;
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
    public const VERSION = '0.1.2';
    public const DEFAULT_BASE_URL = 'https://pay-api.technas.fr';
    public const SECRET_ENV = 'TECHNAS_PAY_SECRET';

    /**
     * Le secret, gardé dans une fonction et non dans une propriété : ni `var_export`, ni un cast en
     * tableau, ni une trace ne peuvent l'afficher.
     */
    private readonly \Closure $secretSource;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    public function __construct(
        #[\SensitiveParameter] ?string $secret = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?Transport $transport = null,
        private readonly int $webhookTolerance = Signature::DEFAULT_TOLERANCE,
        private readonly ?string $defaultCurrency = null,
    ) {
        $this->secretSource = static fn (): ?string => $secret;
        $this->baseUrl = rtrim($baseUrl, '/');
        // La clé part dans un en-tête : jamais vers une adresse en clair, sauf la machine locale (tests).
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new TechnasPayException('invalid_base_url', 'baseUrl doit être en https');
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /** Le client porte le secret : il ne se sérialise pas (cache, session, file d'attente). */
    public function __serialize(): array
    {
        throw new \LogicException('TechnasPay ne se sérialise pas : il contient votre secret. Recréez-le où il sert.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('TechnasPay ne se désérialise pas.');
    }

    /** Ni secret ni clé dans un `var_dump` ou `print_r`. @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /** @return array{mode: string, apiKey: string, webhookSecret: string} */
    private function secrets(): array
    {
        $env = getenv(self::SECRET_ENV);
        $raw = ($this->secretSource)() ?? (is_string($env) && $env !== '' ? $env : D::nstr($_ENV, self::SECRET_ENV));
        // Le message ne cite jamais la valeur reçue : elle finirait dans des journaux.
        if (!is_string($raw) || !preg_match('/^tps_(live|test)_([^.\s]+)\.([^.\s]+)$/', $raw, $m)) {
            throw new TechnasPayException('invalid_secret', 'Secret Technas absent ou mal formé. Vérifiez la variable ' . self::SECRET_ENV . '.');
        }
        return ['mode' => $m[1], 'apiKey' => $m[2], 'webhookSecret' => $m[3]];
    }

    /** Vrai avec un secret `tps_test_` : aucun argent ne circule. */
    public function isTestMode(): bool
    {
        return $this->secrets()['mode'] === 'test';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function callMap(string $method, string $path, ?array $body = null): array
    {
        return D::asMap($this->call($method, $path, $body));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return list<array<string, mixed>>
     */
    private function callList(string $method, string $path, ?array $body = null): array
    {
        return D::asMaps($this->call($method, $path, $body));
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null): mixed
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secrets()['apiKey'], 'User-Agent' => 'technas-pay-php/' . self::VERSION, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $r = $this->transport->send($method, $this->baseUrl . $path, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $data = null;
        if ($r['body'] !== '') {
            try {
                $data = json_decode($r['body'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                if ($r['status'] < 400) {
                    throw new TechnasPayException('invalid_response', 'Réponse illisible du service de paiement', $r['status']);
                }
            }
        }
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
        $currency = strtoupper(trim(D::str($input, 'currency')));
        if ($currency === '') {
            $country = trim(D::str(D::map($input, 'customer'), 'country'));
            $currency = $country !== '' ? (Status::currencyForCountry($country) ?? '') : strtoupper(trim((string) $this->defaultCurrency));
        }
        if ($method === 'card' && $currency === '') {
            throw new TechnasPayException('currency_required', 'currency est obligatoire pour un paiement par carte (EUR, XOF…)');
        }
        $body = [
            'amount' => $input['amount'],
            'reference' => D::str($input, 'reference'),
            'customer' => D::map($input, 'customer'),
            'return_url' => D::str($input, 'returnUrl'),
            'metadata' => (object) D::map($input, 'metadata'),
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
        return Payment::fromApi($this->callMap('POST', '/v1/payments', $body));
    }

    public function getPayment(string $id): Payment
    {
        return Payment::fromApi($this->callMap('GET', '/v1/payments/' . self::seg($id)));
    }

    /** Pour rattraper un webhook manqué. */
    public function getPaymentByReference(string $reference): Payment
    {
        return Payment::fromApi($this->callMap('GET', '/v1/payments?' . http_build_query(['reference' => $reference])));
    }

    /** @return array{items: list<Payment>, nextCursor: ?string} */
    public function listTransactions(?int $limit = null, ?string $status = null, ?string $cursor = null): array
    {
        $q = http_build_query(array_filter(['limit' => $limit, 'status' => $status, 'cursor' => $cursor], fn ($v) => $v !== null && $v !== ''));
        $raw = $this->callMap('GET', '/v1/transactions' . ($q === '' ? '' : "?$q"));
        return ['items' => array_map(static fn (array $p): Payment => Payment::fromApi($p), D::asMaps($raw['items'] ?? null)), 'nextCursor' => D::nstr($raw, 'next_cursor')];
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
        return Refund::fromApi($this->callMap('POST', '/v1/payments/' . self::seg($paymentId) . '/refunds', $body));
    }

    /** Enregistre un remboursement fait HORS Technas (Flooz, espèces…). Aucun argent ne bouge. */
    public function recordExternalRefund(string $paymentId, int $amount, string $method, ?string $note = null, ?string $recordedBy = null, ?string $reference = null): Refund
    {
        self::positive($amount, true);
        if (trim($method) === '') {
            throw new TechnasPayException('invalid_request', 'method est obligatoire : "Flooz", "Espèces"…');
        }
        $body = array_filter(['amount' => $amount, 'method' => trim($method), 'note' => $note, 'recorded_by' => $recordedBy, 'reference' => $reference], fn ($v) => $v !== null && $v !== '');
        return Refund::fromApi($this->callMap('POST', '/v1/payments/' . self::seg($paymentId) . '/external-refunds', $body));
    }

    public function getRefund(string $id): Refund
    {
        return Refund::fromApi($this->callMap('GET', '/v1/refunds/' . self::seg($id)));
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
        return TopUp::fromApi($this->callMap('POST', '/v1/balance/top-ups', $body));
    }

    /**
     * Ce que Technas vous doit, par devise.
     *
     * @return list<array{currency: string, amount: int|float}>
     */
    public function getBalance(): array
    {
        return array_map(static fn (array $b): array => ['currency' => D::str($b, 'currency'), 'amount' => D::num($b, 'amount')], $this->callList('GET', '/v1/balance'));
    }

    /** @return list<array<string, mixed>> */
    public function getSettlements(): array
    {
        return $this->callList('GET', '/v1/settlements');
    }

    /** @return list<array{code: string, name: string, currency: ?string}> */
    public function getPayoutNetworks(string $country): array
    {
        return array_map(static fn (array $n): array => ['code' => D::str($n, 'code'), 'name' => D::str($n, 'name'), 'currency' => D::nstr($n, 'currency')],
            $this->callList('GET', '/v1/payout-networks?' . http_build_query(['country' => $country])));
    }

    /**
     * Vos numéros de reversement, un par devise.
     *
     * @return array<string, mixed>
     */
    public function getPayoutDestination(): array
    {
        return $this->callMap('GET', '/v1/payout-destination');
    }

    /**
     * Rien ne change avant le clic du responsable du compte dans l'e-mail reçu. Réservé à un administrateur.
     *
     * @return array<string, mixed>
     */
    public function setPayoutDestination(string $country, string $network, string $phone, string $beneficiaryName): array
    {
        return $this->callMap('PUT', '/v1/payout-destination', ['country' => $country, 'network' => $network, 'phone' => $phone, 'beneficiary_name' => $beneficiaryName]);
    }

    // --- Hors zone franc CFA --------------------------------------------------------------------

    /**
     * Votre option hors zone CFA, ses risques (`risks`), les pays ouverts.
     *
     * @return array<string, mixed>
     */
    public function getFxMode(): array
    {
        return $this->callMap('GET', '/v1/fx');
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
        return $this->callMap('PUT', '/v1/fx', $body);
    }

    /**
     * @param string[]|null $allowed `null` : tous les pays
     * @param string[] $excluded
     * @return array{allowed: ?list<string>, excluded: list<string>}
     */
    public function setCountryRules(?array $allowed = null, array $excluded = []): array
    {
        $r = $this->callMap('PUT', '/v1/country-rules', ['allowed' => $allowed, 'excluded' => $excluded]);
        return ['allowed' => D::nstrings($r, 'allowed'), 'excluded' => D::strings($r, 'excluded')];
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
        return CardOnboardingLink::fromApi($this->callMap('POST', '/v1/card/onboarding', $body));
    }

    /** `canAcceptPayments` décide de tout pour `askForPayment(['method' => 'card', …])`. */
    public function getCardAccount(): CardAccount
    {
        return CardAccount::fromApi($this->callMap('GET', '/v1/card/account'));
    }

    /** Tableau de bord de votre compte carte : le responsable s'y connecte avec SES identifiants. */
    public function getCardDashboardLink(): string
    {
        return D::str($this->callMap('POST', '/v1/card/dashboard-link', []), 'url');
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
                'net_amount' => is_int($amount) && is_int($commission) ? $amount - $commission : (float) $amount - (float) $commission,
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
     * @param callable(string, mixed): (App\AppOrder|array<string, mixed>|null) $resolveOrder la commande relue EN BASE ; null → 404
     * @param (callable(App\AppRequest): mixed)|null $authorizeAdmin le MARCHAND (routes carte) ; null → 403
     */
    public function appRoutes(callable $authorize, callable $resolveOrder, ?string $returnUrl = null, ?WebhookHandlers $webhooks = null, bool $card = false, ?callable $authorizeAdmin = null, int $maxAttempts = 20): AppRoutes
    {
        return new AppRoutes($this, $authorize, $resolveOrder, $returnUrl, $webhooks, $card, $authorizeAdmin, $maxAttempts);
    }
}
