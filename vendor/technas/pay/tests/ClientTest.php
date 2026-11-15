<?php

declare(strict_types=1);

namespace TechnasPay\Tests;

use PHPUnit\Framework\TestCase;
use TechnasPay\Model\KycRequired;
use TechnasPay\Model\Refund;
use TechnasPay\Status;
use TechnasPay\TechnasPay;
use TechnasPay\TechnasPayException;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\Signature;
use TechnasPay\Webhook\WebhookHandlers;
use TechnasPay\Webhook\WebhookVerificationException;

final class ClientTest extends TestCase
{
    private const API_KEY = 'pk_abc-DEF_123';
    private const WEBHOOK_SECRET = 'ws_webhook-secret_456';
    private const SECRET = 'tps_test_' . self::API_KEY . '.' . self::WEBHOOK_SECRET;
    private const CUSTOMER = ['email' => 'awa@exemple.tg', 'name' => 'Awa Mensah'];
    private const PAYMENT = ['id' => 'pay_1', 'reference' => '42-1', 'amount' => 5000, 'currency' => null, 'commission' => 100,
        'net_amount' => 4900, 'status' => 'pending', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_1', 'metadata' => [], 'test_mode' => true];

    private function pay(FakeTransport $t, string $secret = self::SECRET): TechnasPay
    {
        return new TechnasPay($secret, 'https://gw.test/', $t);
    }

    public function testSeuleLaCleApiPartEtDansUnEnTete(): void
    {
        $t = new FakeTransport([['status' => 201, 'body' => self::PAYMENT]]);
        $p = $this->pay($t)->askForPayment(['amount' => 5000, 'reference' => '42-1', 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p.test/merci']);
        $call = $t->calls[0];
        self::assertSame('https://gw.test/v1/payments', $call['url']);
        self::assertSame('Bearer ' . self::API_KEY, $call['headers']['Authorization']);
        $all = $call['url'] . json_encode($call['headers']) . $call['body'];
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, $all);
        self::assertStringNotContainsString('tps_test_', $all);
        $body = $t->jsonBody(0);
        self::assertArrayNotHasKey('currency', $body, 'zone CFA : le client choisit son pays');
        self::assertSame('https://p.test/merci', $body['return_url']);
        self::assertSame(4900, $p->netAmount);
        self::assertSame('mobile_money', $p->method);
        self::assertStringNotContainsString(self::API_KEY, print_r($this->pay($t), true));
    }

    public function testLaDeviseSuitLePaysCfa(): void
    {
        $t = new FakeTransport([['status' => 201, 'body' => self::PAYMENT], ['status' => 201, 'body' => self::PAYMENT]]);
        $pay = $this->pay($t);
        $pay->askForPayment(['amount' => 5000, 'reference' => 'r', 'customer' => self::CUSTOMER + ['country' => 'cm'], 'returnUrl' => 'https://p']);
        self::assertSame('XAF', $t->jsonBody(0)['currency']);
        $pay->askForPayment(['amount' => 5000, 'reference' => 'r2', 'customer' => self::CUSTOMER + ['country' => 'GH'], 'returnUrl' => 'https://p']);
        self::assertArrayNotHasKey('currency', $t->jsonBody(1));
    }

    public function testMontantsRefusesAvantToutAppel(): void
    {
        $t = new FakeTransport();
        foreach ([50.5, 0, -1, '5000', true] as $bad) {
            try {
                $this->pay($t)->askForPayment(['amount' => $bad, 'reference' => 'r', 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p']);
                self::fail('montant accepté : ' . var_export($bad, true));
            } catch (TechnasPayException $e) {
                self::assertSame('invalid_amount', $e->errorCode);
                self::assertSame(0, $e->status);
            }
        }
        self::assertSame([], $t->calls);
    }

    public function testCarteDeviseDecimalesEtCompte(): void
    {
        $t = new FakeTransport([
            ['status' => 201, 'body' => ['method' => 'card', 'amount' => 12.5, 'currency' => 'EUR'] + self::PAYMENT],
            ['status' => 200, 'body' => ['status' => 'enabled', 'can_accept_payments' => true]],
            ['status' => 200, 'body' => ['url' => 'https://dashboard.stripe.com/']],
            ['status' => 409, 'body' => ['code' => 'card_account_not_ready', 'message' => 'm', 'account_status' => 'onboarding']],
        ]);
        $pay = $this->pay($t);
        try {
            $pay->askForPayment(['method' => 'card', 'amount' => 12.5, 'reference' => 'r', 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p']);
            self::fail('carte sans devise acceptée');
        } catch (TechnasPayException $e) {
            self::assertSame('currency_required', $e->errorCode);
        }
        $p = $pay->askForPayment(['method' => 'card', 'amount' => 12.5, 'currency' => 'eur', 'reference' => 'r-card-1', 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p']);
        self::assertSame('card', $p->method);
        self::assertSame(['card', 'EUR', 12.5], [$t->jsonBody(0)['method'], $t->jsonBody(0)['currency'], $t->jsonBody(0)['amount']]);
        self::assertTrue($pay->getCardAccount()->canAcceptPayments);
        self::assertSame('https://dashboard.stripe.com/', $pay->getCardDashboardLink());
        try {
            $pay->startCardOnboarding('https://a', 'https://b', 'fr');
            self::fail('attendu card_account_not_ready');
        } catch (TechnasPayException $e) {
            self::assertSame('onboarding', $e->details['accountStatus']);
            self::assertSame('FR', $t->jsonBody(3)['country']);
        }
    }

    public function testErreursRejouablesOuNon(): void
    {
        $t = new FakeTransport([
            ['status' => 409, 'body' => ['code' => 'payment_expired', 'message' => 'm']],
            ['status' => 503, 'body' => null],
            ['status' => 0, 'body' => null],
        ]);
        $pay = $this->pay($t);
        foreach ([['payment_expired', false, 409], ['request_failed', true, 503], ['network_error', true, 0]] as [$code, $retryable, $status]) {
            try {
                $pay->getBalance();
                self::fail('erreur attendue');
            } catch (TechnasPayException $e) {
                self::assertSame([$code, $retryable, $status], [$e->errorCode, $e->retryable(), $e->status]);
            }
        }
    }

    public function testSecretMalFormeNeFuitPas(): void
    {
        try {
            (new TechnasPay('mon-secret-en-clair', transport: new FakeTransport()))->getBalance();
            self::fail('attendu invalid_secret');
        } catch (TechnasPayException $e) {
            self::assertSame('invalid_secret', $e->errorCode);
            self::assertStringNotContainsString('mon-secret', $e->getMessage());
        }
    }

    public function testIdentifiantEchappeEtSimulation(): void
    {
        $t = new FakeTransport([
            ['status' => 404, 'body' => ['code' => 'payment_not_found', 'message' => 'm']],
            ['status' => 303, 'body' => []],
            ['status' => 200, 'body' => ['status' => 'completed'] + self::PAYMENT],
        ]);
        $pay = $this->pay($t);
        try {
            $pay->getPayment('../balance');
        } catch (TechnasPayException) {
        }
        self::assertSame('https://gw.test/v1/payments/..%2Fbalance', $t->calls[0]['url']);
        self::assertSame('completed', $pay->simulateTestPayment('pay_1', 'completed')->status);
        self::assertFalse($t->calls[1]['follow'], 'le 303 vers returnUrl n\'est pas suivi');
        $this->expectExceptionMessage('tps_test_');
        $this->pay(new FakeTransport(), str_replace('tps_test_', 'tps_live_', self::SECRET))->simulateTestPayment('p', 'completed');
    }

    public function testWebhooks(): void
    {
        $pay = $this->pay(new FakeTransport());
        $hook = $pay->createTestWebhook('payment.completed', '42-1', 5000, 'XOF', 100);
        $seen = [];
        $r = $pay->handleWebhook($hook['body'], $hook['headers'][Signature::HEADER], new WebhookHandlers(onCompleted: function ($e) use (&$seen) {
            $seen[] = $e->payment->netAmount;
        }));
        self::assertSame([200, [4900]], [$r['status'], $seen]);
        foreach ([null, 'nimporte', Signature::sign($hook['body'], 'autre'), Signature::sign($hook['body'], self::WEBHOOK_SECRET, time() - 3600)] as $sig) {
            self::assertSame(400, $pay->handleWebhook($hook['body'], $sig, new WebhookHandlers(onCompleted: fn () => self::fail('appelée')))['status']);
        }
        $this->expectException(WebhookVerificationException::class);
        $pay->verifyWebhook(str_replace('5000', '9', $hook['body']), $hook['headers'][Signature::HEADER]);
    }

    public function testFamillesDEvenementsEtFonctionQuiEchoue(): void
    {
        $pay = $this->pay(new FakeTransport());
        $seen = [];
        $h = new WebhookHandlers(
            onRefund: function ($e) use (&$seen) { $seen[] = 'refund:' . $e->refund->kind; },
            onCardAccountUpdated: function ($e) use (&$seen) { $seen[] = 'card:' . ($e->cardAccount->canAcceptPayments ? 'oui' : 'non'); },
            onExpired: function ($e) use (&$seen) { $seen[] = 'expired:' . $e->payment->reference; },
            onTopUp: function ($e) use (&$seen) { $seen[] = 'topup:' . $e->data['amount']; },
        );
        foreach ([
            ['refund.completed', ['id' => 're', 'payment_id' => 'p', 'payment_reference' => 'r', 'amount' => 1, 'currency' => 'EUR', 'status' => 'completed', 'kind' => 'stripe']],
            ['card_account.updated', ['status' => 'enabled', 'can_accept_payments' => true]],
            ['payment.expired', ['id' => 'p', 'reference' => 'r-1', 'status' => 'expired', 'amount' => 1, 'currency' => 'XOF']],
            ['balance.topped_up', ['id' => 't', 'amount' => 5, 'currency' => 'XOF']],
            ['nouveau.type', ['k' => 1]],
        ] as [$type, $data]) {
            $body = json_encode(['id' => 'e', 'type' => $type, 'created_at' => 'x', 'data' => $data]);
            self::assertSame(200, $pay->handleWebhook($body, Signature::sign($body, self::WEBHOOK_SECRET), $h)['status']);
        }
        self::assertSame(['refund:card', 'card:oui', 'expired:r-1', 'topup:5'], $seen);
        $body = json_encode(['id' => 'e', 'type' => 'payment.completed', 'created_at' => 'x', 'data' => ['id' => 'p', 'reference' => 'r', 'status' => 'completed']]);
        $this->expectException(\RuntimeException::class);
        $pay->handleWebhook($body, Signature::sign($body, self::WEBHOOK_SECRET), new WebhookHandlers(onCompleted: fn () => throw new \RuntimeException('base en panne')));
    }

    public function testRemboursementCarteKindCard(): void
    {
        $base = ['id' => 're', 'payment_id' => 'p', 'payment_reference' => 'r', 'amount' => 1, 'currency' => 'EUR', 'status' => 'completed'];
        self::assertSame('card', Refund::fromApi($base + ['kind' => 'stripe'])->kind, 'ancien nom, transition');
        self::assertSame('card', Refund::fromApi($base + ['kind' => 'card'])->kind);
        self::assertSame('payout', Refund::fromApi($base + ['kind' => 'payout'])->kind);
        self::assertSame('external', Refund::fromApi($base + ['kind' => 'external'])->kind);
    }

    public function testRegles(): void
    {
        self::assertTrue(Status::canRetry('expired'));
        self::assertFalse(Status::canRetry('failed'));
        self::assertTrue(Status::isFinal('amount_mismatch'));
        self::assertSame('CMD-42-2', Status::retryReference('CMD-42', 2));
        self::assertSame('XOF', Status::currencyForCountry(' tg '));
        self::assertNull(Status::currencyForCountry('GH'));
    }

    public function testSeuilDIdentiteLuParLaCleApi(): void
    {
        $full = ['applies' => true, 'stage' => 'soon', 'level' => 'none', 'days_elapsed' => 12, 'warn_from_xof' => 800000,
            'warn_from_date' => '2026-12-01T00:00:00Z', 'collected_xof' => 812500, 'threshold_xof' => 1000000, 'grace_days' => 30,
            'first_collected_at' => '2026-11-03T10:00:00Z', 'deadline' => '2026-12-03T10:00:00Z', 'waiver_until' => '2026-12-10T00:00:00Z',
            'blocked' => false, 'reason' => null, 'console_url' => 'https://pay.technas.fr/console/identite'];
        $nulls = ['applies' => false, 'stage' => 'etat_futur', 'level' => 'niveau_futur', 'days_elapsed' => null, 'warn_from_xof' => 800000,
            'warn_from_date' => null, 'collected_xof' => 0, 'threshold_xof' => 1000000, 'grace_days' => 30, 'first_collected_at' => null,
            'deadline' => null, 'waiver_until' => null, 'blocked' => true, 'reason' => 'raison_future', 'console_url' => 'https://pay.technas.fr/console/identite'];
        $t = new FakeTransport([['status' => 200, 'body' => $full], ['status' => 200, 'body' => $nulls]]);
        $pay = $this->pay($t);
        $k = $pay->getAccountKyc();
        self::assertSame(['GET', 'https://gw.test/v1/account/kyc', 'Bearer ' . self::API_KEY, null],
            [$t->calls[0]['method'], $t->calls[0]['url'], $t->calls[0]['headers']['Authorization'], $t->calls[0]['body']]);
        self::assertSame([true, 'soon', 'none', 12, 800000, '2026-12-01T00:00:00Z', 812500, 1000000, 30, '2026-11-03T10:00:00Z',
            '2026-12-03T10:00:00Z', '2026-12-10T00:00:00Z', false, null, 'https://pay.technas.fr/console/identite'],
            [$k->applies, $k->stage, $k->level, $k->daysElapsed, $k->warnFromXof, $k->warnFromDate, $k->collectedXof, $k->thresholdXof,
                $k->graceDays, $k->firstCollectedAt, $k->deadline, $k->waiverUntil, $k->blocked, $k->reason, $k->consoleUrl]);
        $n = $pay->getAccountKyc();
        self::assertSame([false, 'etat_futur', 'niveau_futur', null, null, null, null, null, true, 'raison_future'],
            [$n->applies, $n->stage, $n->level, $n->daysElapsed, $n->warnFromDate, $n->firstCollectedAt, $n->deadline, $n->waiverUntil, $n->blocked, $n->reason],
            'valeurs inconnues gardées, null lus null');
    }

    public function testAvisSeuilDIdentite(): void
    {
        $pay = $this->pay(new FakeTransport());
        $seen = [];
        $h = new WebhookHandlers(
            onKycRequired: function (Event $e) use (&$seen) { $seen[] = $e->kycRequired; },
            onCardAccountUpdated: fn () => self::fail('mauvaise fonction'),
        );
        $body = json_encode(['id' => 'evt_k', 'type' => 'account.kyc_required', 'created_at' => 'x', 'data' => [
            'reason' => 'amount_80', 'collected_xof' => 812500, 'threshold_xof' => 1000000, 'deadline' => '2026-12-03T10:00:00Z',
            'blocked' => false, 'console_url' => 'https://pay.technas.fr/console/identite']]);
        $r = $pay->handleWebhook($body, Signature::sign($body, self::WEBHOOK_SECRET), $h);
        self::assertSame(200, $r['status']);
        self::assertCount(1, $seen);
        $k = $seen[0];
        self::assertInstanceOf(KycRequired::class, $k);
        self::assertSame(['amount_80', 812500, 1000000, '2026-12-03T10:00:00Z', false, 'https://pay.technas.fr/console/identite'],
            [$k->reason, $k->collectedXof, $k->thresholdXof, $k->deadline, $k->blocked, $k->consoleUrl]);
        // Avis plus ancien, sans les champs de GET /v1/account/kyc : ils restent nuls.
        self::assertSame([null, null, null, null, null], [$k->stage, $k->blockReason, $k->daysElapsed, $k->graceDays, $k->firstCollectedAt]);
        self::assertNull($r['event']?->payment);
        self::assertSame(400, $pay->handleWebhook($body, Signature::sign($body, 'autre'), $h)['status']);

        // Avis complet : les champs de GET /v1/account/kyc sont tous lus.
        $body = json_encode(['id' => 'evt_k2', 'type' => 'account.kyc_required', 'created_at' => 'x', 'data' => [
            'reason' => 'blocked', 'collected_xof' => 1000000, 'threshold_xof' => 1000000, 'deadline' => null,
            'blocked' => true, 'console_url' => 'https://pay.technas.fr/console/identite',
            'stage' => 'blocked', 'block_reason' => 'amount', 'days_elapsed' => 12, 'grace_days' => 30,
            'first_collected_at' => '2026-11-03T10:00:00Z']]);
        self::assertSame(200, $pay->handleWebhook($body, Signature::sign($body, self::WEBHOOK_SECRET), $h)['status']);
        $k = $seen[1];
        self::assertSame(['blocked', true, 'blocked', 'amount', 12, 30, '2026-11-03T10:00:00Z'],
            [$k->reason, $k->blocked, $k->stage, $k->blockReason, $k->daysElapsed, $k->graceDays, $k->firstCollectedAt]);
    }

    public function testRefusSeuilDIdentiteEtContrat(): void
    {
        $t = new FakeTransport([
            ['status' => 409, 'body' => ['code' => 'kyc_required', 'message' => 'm', 'console_url' => 'https://pay.technas.fr/console/identite', 'reason' => 'amount']],
            ['status' => 409, 'body' => ['code' => 'contract_required', 'message' => 'm', 'console_url' => 'https://pay.technas.fr/console/contrat', 'version' => '2026-11']],
        ]);
        $pay = $this->pay($t);
        foreach ([
            ['kyc_required', ['reason' => 'amount', 'consoleUrl' => 'https://pay.technas.fr/console/identite']],
            ['contract_required', ['consoleUrl' => 'https://pay.technas.fr/console/contrat', 'version' => '2026-11']],
        ] as [$code, $details]) {
            try {
                $pay->askForPayment(['amount' => 5000, 'reference' => '42-1', 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p.test/merci']);
                self::fail("attendu $code");
            } catch (TechnasPayException $e) {
                self::assertSame([$code, 409, false], [$e->errorCode, $e->status, $e->retryable()]);
                self::assertEquals($details, $e->details);
            }
        }
    }
}
