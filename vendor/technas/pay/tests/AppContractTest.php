<?php

declare(strict_types=1);

namespace TechnasPay\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TechnasPay\App\AppRequest;
use TechnasPay\App\AppRoutes;
use TechnasPay\Bridge\Psr15Handler;
use TechnasPay\Bridge\SymfonyBridge;
use TechnasPay\TechnasPay;
use TechnasPay\Webhook\Signature;
use TechnasPay\Webhook\WebhookHandlers;

/**
 * Rejoue contract/app-contract.v1.json : le même fichier que les SDK Node, Python, Dart et le
 * client Flutter. Puis les adaptateurs PSR-15 et Symfony/Laravel.
 */
final class AppContractTest extends TestCase
{
    private const WEBHOOK_SECRET = 'ws_secret';
    private const SECRET = 'tps_test_pk_abc.' . self::WEBHOOK_SECRET;

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $contract = json_decode((string) file_get_contents(__DIR__ . '/../contract/app-contract.v1.json'), true);
        $out = [];
        foreach ($contract['cases'] as $c) {
            $out[$c['name']] = [$c];
        }
        if (count($out) !== 31) {
            throw new \LogicException('le contrat v1 a 31 cas, lu : ' . count($out));
        }
        return $out;
    }

    /** @param array<string, mixed> $c */
    private static function routes(array $c, TechnasPay $pay, ?WebhookHandlers $webhooks = null): AppRoutes
    {
        return $pay->appRoutes(
            authorize: fn () => $c['user'],
            resolveOrder: fn (string $id, $user) => $user === $c['user'] ? ($c['orders'][$id] ?? null) : null,
            returnUrl: 'https://app.test/retour',
            webhooks: $webhooks,
            card: (bool) ($c['options']['card'] ?? false),
            authorizeAdmin: array_key_exists('admin', $c) ? fn () => $c['admin'] : null,
        );
    }

    /** @param array<string, mixed> $c */
    #[DataProvider('cases')]
    public function testContrat(array $c): void
    {
        $gw = new FakeTransport($c['gateway']);
        $pay = new TechnasPay(self::SECRET, 'https://gw.test', $gw);
        $req = $c['request'];
        $r = self::routes($c, $pay)->handle(new AppRequest(
            $req['method'],
            $req['path'],
            $req['query'] ?? [],
            [],
            array_key_exists('body', $req) ? json_encode($req['body']) : '',
        ));
        self::assertSame($c['expect']['status'], $r->status);
        $body = json_decode($r->json(), true);
        if ($r->status >= 400) {
            self::assertIsString($body['message']);
            unset($body['message']);
        }
        self::assertEquals($c['expect']['body'], $body);
        self::assertCount(count($c['expect_gateway']), $gw->calls, 'nombre d\'appels à la passerelle');
        foreach ($c['expect_gateway'] as $i => $e) {
            self::assertSame($e['method'], $gw->calls[$i]['method']);
            self::assertSame($e['path'], parse_url($gw->calls[$i]['url'], PHP_URL_PATH));
            foreach ($e['body_includes'] ?? [] as $k => $v) {
                self::assertEquals($v, $gw->jsonBody($i)[$k], $k);
            }
            parse_str((string) parse_url($gw->calls[$i]['url'], PHP_URL_QUERY), $query);
            foreach ($e['query_includes'] ?? [] as $k => $v) {
                self::assertSame($v, $query[$k] ?? null, "paramètre $k");
            }
        }
    }

    public function testLeContratA31Cas(): void
    {
        self::assertCount(31, self::cases());
    }

    public function testChangementDOffreAncienEssaiDejaPayeAucunSecondPaiement(): void
    {
        $c = ['user' => 'u1', 'orders' => ['42' => ['amount' => 7500, 'customer' => ['email' => 'awa@exemple.tg', 'name' => 'Awa Mensah']]]];
        $gw = new FakeTransport([
            ['status' => 409, 'body' => ['code' => 'reference_conflict', 'message' => 'm']],
            ['status' => 200, 'body' => ['id' => 'pay_1', 'reference' => '42-1', 'amount' => 5000, 'status' => 'completed']],
        ]);
        $r = self::routes($c, new TechnasPay(self::SECRET, 'https://gw.test', $gw))
            ->handle(new AppRequest('POST', '/payments', [], [], (string) json_encode(['orderId' => '42'])));
        self::assertSame(409, $r->status);
        self::assertSame(['payment_already_completed', false], [json_decode($r->json(), true)['code'], json_decode($r->json(), true)['retryable']]);
        self::assertCount(2, $gw->calls, 'ni annulation ni second paiement');
        self::assertSame('https://gw.test/v1/payments?reference=42-1', $gw->calls[1]['url']);
    }

    public function testWebhookSigneRefuseEtNonBranche(): void
    {
        $paid = [];
        $c = ['user' => 'u1', 'orders' => []];
        $pay = new TechnasPay(self::SECRET);
        $routes = self::routes($c, $pay, new WebhookHandlers(onCompleted: function ($e) use (&$paid) {
            $paid[] = $e->payment->reference;
        }));
        $body = json_encode(['id' => 'e1', 'type' => 'payment.completed', 'created_at' => 'x',
            'data' => ['id' => 'p', 'reference' => '42-1', 'status' => 'completed', 'amount' => 1, 'currency' => 'XOF']]);
        $ok = $routes->handle(new AppRequest('POST', '/webhook', [], ['x-technas-payment-signature' => Signature::sign($body, self::WEBHOOK_SECRET)], $body));
        self::assertSame(200, $ok->status);
        self::assertSame(['42-1'], $paid);
        $bad = $routes->handle(new AppRequest('POST', '/webhook', [], ['x-technas-payment-signature' => 't=1,s=00'], $body));
        self::assertSame(400, $bad->status);
        self::assertSame(404, self::routes($c, $pay)->handle(new AppRequest('POST', '/webhook', [], [], $body))->status);
    }

    public function testPsr15SousUnPrefixe(): void
    {
        $c = self::cases()['démarrer : premier essai'][0];
        $seen = null;
        $pay = new TechnasPay(self::SECRET, 'https://gw.test', new FakeTransport($c['gateway']));
        $routes = $pay->appRoutes(
            authorize: function (AppRequest $r) use (&$seen) {
                $seen = $r->header('Authorization');
                return 'u1';
            },
            resolveOrder: fn (string $id) => $c['orders'][$id] ?? null,
        );
        $f = new Psr17Factory();
        $handler = new Psr15Handler($routes, $f, $f, '/api/technas-pay');
        $req = $f->createServerRequest('POST', 'https://app.test/api/technas-pay/payments')
            ->withHeader('Authorization', 'Bearer jeton')
            ->withBody($f->createStream(json_encode(['orderId' => '42'])));
        $res = $handler->handle($req);
        self::assertSame(200, $res->getStatusCode());
        self::assertEquals($c['expect']['body'], json_decode((string) $res->getBody(), true));
        self::assertSame('Bearer jeton', $seen);
    }

    public function testSymfonyEtLaravel(): void
    {
        $c = self::cases()['moyens ouverts pour une commande'][0];
        $pay = new TechnasPay(self::SECRET, 'https://gw.test', new FakeTransport());
        $routes = self::routes($c, $pay);
        $res = SymfonyBridge::handle($routes, Request::create('/api/technas-pay/methods?orderId=42', 'GET'), 'methods');
        self::assertSame(200, $res->getStatusCode());
        self::assertEquals($c['expect']['body'], json_decode((string) $res->getContent(), true));
    }
}
