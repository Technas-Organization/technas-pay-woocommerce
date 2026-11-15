<?php

declare(strict_types=1);

namespace TechnasPay\Tests;

use PHPUnit\Framework\TestCase;
use TechnasPay\TechnasPay;
use TechnasPay\TechnasPayException;
use TechnasPay\Webhook\Signature;
use TechnasPay\Webhook\WebhookHandlers;
use TechnasPay\Webhook\WebhookVerificationException;

/**
 * Exigences de sécurité (Jonas, 15/11/2026) prouvées par des tests : un avis falsifié, rejoué
 * ou trop vieux est REFUSÉ ; la clé ne part jamais en clair ; aucune réponse n'est interprétée
 * à moitié.
 */
final class SecurityTest extends TestCase
{
    private const WEBHOOK_SECRET = 'ws_secret_456';
    private const SECRET = 'tps_test_pk_abc.' . self::WEBHOOK_SECRET;

    private function event(): string
    {
        return json_encode(['id' => 'evt_1', 'type' => 'payment.completed', 'created_at' => 'x',
            'data' => ['id' => 'pay_1', 'reference' => '42-1', 'status' => 'completed', 'amount' => 5000, 'currency' => 'XOF']]);
    }

    private function refused(string $body, ?string $signature): void
    {
        $pay = new TechnasPay(self::SECRET);
        $called = false;
        $r = $pay->handleWebhook($body, $signature, new WebhookHandlers(onCompleted: function () use (&$called) {
            $called = true;
        }));
        self::assertSame(400, $r['status']);
        self::assertFalse($called, 'aucune fonction appelée sur un avis refusé');
    }

    public function testAvisFalsifieRefuse(): void
    {
        $body = $this->event();
        $this->refused($body, Signature::sign($body, 'un-autre-secret'));                 // mauvais secret
        $this->refused(str_replace('5000', '1', $body), Signature::sign($body, self::WEBHOOK_SECRET)); // corps modifié
        $this->refused($body, null);                                                        // sans signature
        $this->refused($body, 't=' . time() . ',s=' . str_repeat('0', 64));                // signature inventée
        $this->refused($body, 'n\'importe quoi');                                           // mal formée
    }

    public function testAvisRejoueOuTropVieuxRefuse(): void
    {
        $body = $this->event();
        // Rejoué plus tard avec sa signature d'origine : trop vieux.
        $this->refused($body, Signature::sign($body, self::WEBHOOK_SECRET, time() - 301));
        // Rejoué en réécrivant l'horodatage pour le rajeunir : la signature ne correspond plus.
        $old = Signature::sign($body, self::WEBHOOK_SECRET, time() - 3600);
        $this->refused($body, preg_replace('/^t=\d+/', 't=' . time(), $old));
        // Daté du futur au-delà de la tolérance.
        $this->refused($body, Signature::sign($body, self::WEBHOOK_SECRET, time() + 301));
    }

    public function testCorpsJsonPiegeRefuse(): void
    {
        $nested = str_repeat('[', 100) . str_repeat(']', 100);
        $this->expectException(WebhookVerificationException::class);
        (new TechnasPay(self::SECRET))->verifyWebhook($nested, Signature::sign($nested, self::WEBHOOK_SECRET));
    }

    public function testCleJamaisVersUneAdresseEnClair(): void
    {
        foreach (['http://pay-api.technas.fr', 'http://evil.example', 'ftp://x', 'pay-api.technas.fr'] as $url) {
            try {
                new TechnasPay(self::SECRET, $url);
                self::fail("adresse acceptée : $url");
            } catch (TechnasPayException $e) {
                self::assertSame('invalid_base_url', $e->errorCode);
            }
        }
        self::assertInstanceOf(TechnasPay::class, new TechnasPay(self::SECRET, 'http://localhost:8080'));
    }

    public function testLeClientNeSeSerialisePas(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new TechnasPay(self::SECRET));
    }

    public function testLeSecretNApparaitNullePart(): void
    {
        $pay = new TechnasPay(self::SECRET, 'https://gw.test', new FakeTransport([['status' => 500, 'body' => ['message' => 'panne']]]));
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, print_r($pay, true) . var_export((array) [$pay], true));
        try {
            $pay->getBalance();
        } catch (TechnasPayException $e) {
            self::assertStringNotContainsString('pk_abc', $e->getMessage() . json_encode($e->details));
        }
    }

    public function testReponseIllisibleNonInterpretee(): void
    {
        $t = new FakeTransport([static fn () => ['status' => 200, 'body' => '{"id": "pay_1", tronqué']]);
        try {
            (new TechnasPay(self::SECRET, 'https://gw.test', $t))->getPayment('pay_1');
            self::fail('une réponse illisible a été acceptée');
        } catch (TechnasPayException $e) {
            self::assertSame('invalid_response', $e->errorCode);
        }
    }

    public function testTlsToujoursVerifie(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Http/CurlTransport.php');
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $src);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST => 2', $src);
        self::assertStringContainsString('CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS', $src);
        foreach (glob(__DIR__ . '/../src/{,*/}*.php', GLOB_BRACE) ?: [] as $f) {
            $code = (string) file_get_contents($f);
            self::assertDoesNotMatchRegularExpression('/VERIFYPEER\s*=>\s*false|VERIFYHOST\s*=>\s*0|\bunserialize\(|\beval\(|\bextract\(|\$\$/', $code, basename($f));
        }
    }
}
