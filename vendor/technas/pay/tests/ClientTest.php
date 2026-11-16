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

    /** @var list<string> */
    private const INVALID_IDS = ['../balance', '..', '.', 'a/b', 'a%2Fb', '', 'pay 1', "pay_1\n", 'é'];

    public function testIdentifiantInvalideRefuseSansAppel(): void
    {
        $t = new FakeTransport();
        $pay = $this->pay($t);
        $calls = [
            'getPayment' => fn (string $id) => $pay->getPayment($id),
            'cancelPayment' => fn (string $id) => $pay->cancelPayment($id),
            'getRefund' => fn (string $id) => $pay->getRefund($id),
            'refund' => fn (string $id) => $pay->refund($id),
            'recordExternalRefund' => fn (string $id) => $pay->recordExternalRefund($id, 2000, 'Espèces'),
            'simulateTestPayment' => fn (string $id) => $pay->simulateTestPayment($id, 'completed'),
        ];
        foreach ($calls as $name => $call) {
            foreach ([...self::INVALID_IDS, str_repeat('a', 129)] as $id) {
                try {
                    $call($id);
                    self::fail("$name a accepté " . var_export($id, true));
                } catch (TechnasPayException $e) {
                    self::assertSame(['invalid_request', 0, false], [$e->errorCode, $e->status, $e->retryable()], $name);
                    if ($id !== '' && $id !== '.') {
                        self::assertStringNotContainsString($id, $e->getMessage(), "$name : le message ne cite pas l'identifiant");
                    }
                }
            }
        }
        self::assertSame([], $t->calls, 'aucun appel');
    }

    public function testIdentifiantValidePartTelQuelEtSimulation(): void
    {
        $uuid = '3f2b8c1e-9a4d-4e6b-8f0a-2c7d5e1b9a3f';
        $t = new FakeTransport([
            ['status' => 200, 'body' => ['id' => $uuid] + self::PAYMENT],
            ['status' => 200, 'body' => self::PAYMENT],
            ['status' => 303, 'body' => []],
            ['status' => 200, 'body' => ['status' => 'completed'] + self::PAYMENT],
        ]);
        $pay = $this->pay($t);
        $pay->getPayment($uuid);
        self::assertSame('https://gw.test/v1/payments/' . $uuid, $t->calls[0]['url']);
        $pay->getPayment(str_repeat('a', 128));
        self::assertSame('https://gw.test/v1/payments/' . str_repeat('a', 128), $t->calls[1]['url']);
        self::assertSame('completed', $pay->simulateTestPayment('pay_1', 'completed')->status);
        self::assertSame('https://gw.test/test-checkout/pay_1/completed', $t->calls[2]['url']);
        self::assertFalse($t->calls[2]['follow'], 'le 303 vers returnUrl n\'est pas suivi');
        $this->expectExceptionMessage('tps_test_');
        $this->pay(new FakeTransport(), str_replace('tps_test_', 'tps_live_', self::SECRET))->simulateTestPayment('p', 'completed');
    }

    public function testLeClientChangeDOffreApresUnEssaiExpire(): void
    {
        $id1 = ['id' => 'pay_1', 'reference' => 'F-1', 'amount' => 5000] + self::PAYMENT;
        $t = new FakeTransport([
            ['status' => 201, 'body' => $id1],
            ['status' => 303, 'body' => []],
            ['status' => 200, 'body' => ['status' => 'expired', 'checkout_url' => null] + $id1],
            ['status' => 200, 'body' => ['status' => 'expired', 'checkout_url' => null] + $id1],
            ['status' => 201, 'body' => ['id' => 'pay_2', 'reference' => 'F-2', 'amount' => 7500] + self::PAYMENT],
            ['status' => 409, 'body' => ['code' => 'reference_conflict', 'message' => 'm']],
        ]);
        $pay = $this->pay($t);
        $ask = fn (string $ref, int $amount) => $pay->askForPayment(['amount' => $amount, 'reference' => $ref, 'customer' => self::CUSTOMER, 'returnUrl' => 'https://p.test/merci']);

        self::assertSame('pay_1', $ask(Status::retryReference('F', 1), 5000)->id);
        self::assertSame('expired', $pay->simulateTestPayment('pay_1', 'expired')->status);
        self::assertSame('https://gw.test/test-checkout/pay_1/expired', $t->calls[1]['url']);
        $relu = $pay->getPayment('pay_1');
        self::assertSame(['GET', 'https://gw.test/v1/payments/pay_1'], [$t->calls[3]['method'], $t->calls[3]['url']]);
        self::assertSame('expired', $relu->status);
        self::assertTrue(Status::canRetry($relu->status));

        $p2 = $ask(Status::retryReference('F', 2), 7500);
        self::assertSame(['pay_2', 'F-2', 7500, 'pending'], [$p2->id, $p2->reference, $p2->amount, $p2->status]);

        try {
            $ask('F-1', 7500);
            self::fail('la référence d\'un autre montant a été acceptée');
        } catch (TechnasPayException $e) {
            self::assertSame(['reference_conflict', 409, false], [$e->errorCode, $e->status, $e->retryable()]);
        }

        foreach ([[0, 'F-1', 5000], [4, 'F-2', 7500], [5, 'F-1', 7500]] as [$i, $ref, $amount]) {
            self::assertSame('https://gw.test/v1/payments', $t->calls[$i]['url']);
            self::assertSame([$ref, $amount], [$t->jsonBody($i)['reference'] ?? null, $t->jsonBody($i)['amount'] ?? null]);
        }
        self::assertSame([], $t->queue);
    }

    public function testAnnulerLAncienEssaiPuisCreerLeNouveau(): void
    {
        $annule = ['id' => 'pay_1', 'reference' => 'F-1', 'status' => 'expired', 'checkout_url' => null,
            'canceled_at' => '2026-11-16T09:00:00Z', 'cancel_reason' => 'canceled_by_partner'] + self::PAYMENT;
        $t = new FakeTransport([
            ['status' => 200, 'body' => $annule],
            ['status' => 200, 'body' => $annule],
            ['status' => 409, 'body' => ['code' => 'payment_in_progress', 'message' => 'm']],
            ['status' => 409, 'body' => ['code' => 'payment_already_completed', 'message' => 'm']],
        ]);
        $pay = $this->pay($t);
        $p = $pay->cancelPayment('pay_1');
        self::assertSame(['POST', 'https://gw.test/v1/payments/pay_1/cancel', '{}'], [$t->calls[0]['method'], $t->calls[0]['url'], $t->calls[0]['body']]);
        self::assertSame('Bearer ' . self::API_KEY, $t->calls[0]['headers']['Authorization']);
        self::assertSame(['expired', null, '2026-11-16T09:00:00Z', 'canceled_by_partner'], [$p->status, $p->checkoutUrl, $p->canceledAt, $p->cancelReason]);
        self::assertTrue(Status::canRetry($p->status));
        self::assertSame('2026-11-16T09:00:00Z', $pay->cancelPayment('pay_1')->canceledAt, 'rejouer rend le même résultat');
        foreach ([['payment_in_progress', true], ['payment_already_completed', false]] as [$code, $retryable]) {
            try {
                $pay->cancelPayment('pay_1');
                self::fail("$code non levé");
            } catch (TechnasPayException $e) {
                self::assertSame([$code, 409, $retryable], [$e->errorCode, $e->status, $e->retryable()]);
            }
        }
        // Même règle que getPayment : un identifiant hors du motif ne part pas.
        foreach (['../balance', '', '  '] as $invalide) {
            try {
                $pay->cancelPayment($invalide);
                self::fail('identifiant invalide accepté');
            } catch (TechnasPayException $e) {
                self::assertSame(['invalid_request', 0], [$e->errorCode, $e->status]);
            }
        }
        self::assertCount(4, $t->calls, 'identifiant invalide : aucun appel');
        // Un paiement lu sans annulation n'a ni canceledAt ni cancelReason.
        $lu = \TechnasPay\Model\Payment::fromApi(self::PAYMENT);
        self::assertSame([null, null, null], [$lu->canceledAt, $lu->cancelReason, $lu->reason]);
    }

    public function testAvisExpireDitPourquoi(): void
    {
        $pay = $this->pay(new FakeTransport());
        $seen = [];
        $h = new WebhookHandlers(onExpired: function ($e) use (&$seen) {
            $seen[] = var_export($e->payment->reason, true) . '/' . var_export($e->payment->canceledAt, true);
        });
        $base = ['id' => 'p', 'reference' => 'F-1', 'status' => 'expired', 'amount' => 1, 'currency' => 'XOF'];
        foreach ([$base + ['reason' => 'canceled', 'canceled_at' => '2026-11-16T09:00:00Z'], $base + ['reason' => 'timeout'], $base] as $data) {
            $body = (string) json_encode(['id' => 'e', 'type' => 'payment.expired', 'created_at' => 'x', 'data' => $data]);
            self::assertSame(200, $pay->handleWebhook($body, Signature::sign($body, self::WEBHOOK_SECRET), $h)['status']);
        }
        self::assertSame(["'canceled'/'2026-11-16T09:00:00Z'", "'timeout'/NULL", 'NULL/NULL'], $seen);
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
