<?php
/**
 * Test d'intégration, joué DANS un vrai WordPress + WooCommerce (wp-env, en CI) :
 *
 *   wp eval-file tests/integration.php interne   # tout ce qui se teste dans le processus
 *   (curl d'un avis signé sur la vraie adresse /?wc-api=technas_pay, depuis l'hôte)
 *   wp eval-file tests/integration.php http      # la commande est bien passée à payée
 *
 * Les appels sortants vers Technas passent par un faux transport (filtre `technas_pay_wc_transport`) ;
 * les avis entrants sont signés avec le même secret que celui configuré, comme en vrai.
 */

use TechnasPay\Http\Transport;
use TechnasPay\Webhook\Signature;

const TP_SECRET = 'tps_test_pk_ci.ws_ci_secret';
const TP_WEBHOOK_SECRET = 'ws_ci_secret';
$out_dir = __DIR__ . '/out';

/** @param mixed $actual */
function tp_check(bool $ok, string $what, $actual = null): void
{
    if (!$ok) {
        WP_CLI::error("ÉCHEC : $what" . ($actual === null ? '' : ' — obtenu : ' . var_export($actual, true)));
    }
    WP_CLI::log("ok  $what");
}

final class TP_Fake_Transport implements Transport
{
    public array $calls = [];
    /** @var array<int, array{0: int, 1: mixed}> */
    public array $queue = [];

    public function send(string $method, string $url, array $headers, ?string $body, bool $followRedirects = true): array
    {
        $this->calls[] = ['method' => $method, 'path' => parse_url($url, PHP_URL_PATH), 'body' => $body ? json_decode($body, true) : null, 'auth' => $headers['Authorization'] ?? null];
        if (!$this->queue) {
            throw new \LogicException("appel imprévu : $method $url");
        }
        [$status, $data] = array_shift($this->queue);
        return ['status' => $status, 'body' => json_encode($data)];
    }
}

function tp_payment(array $over = []): array
{
    return $over + ['id' => 'pay_1', 'reference' => '1-1', 'amount' => 5000, 'currency' => null, 'commission' => 100, 'net_amount' => 4900,
        'status' => 'pending', 'method' => 'mobile_money', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_1', 'metadata' => [], 'test_mode' => true];
}

function tp_order(float $total = 5000): WC_Order
{
    $product = new WC_Product_Simple();
    $product->set_name('Tresses');
    $product->set_regular_price((string) $total);
    $product->save();
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_billing_email('awa@exemple.tg');
    $order->set_billing_first_name('Awa');
    $order->set_billing_last_name('Mensah');
    $order->set_billing_country('TG');
    $order->set_billing_phone('+22890000000');
    $order->set_payment_method('technas_pay_mobile_money');
    $order->calculate_totals();
    $order->save();
    return $order;
}

function tp_signed_event(string $type, array $data): array
{
    $body = json_encode(['id' => 'evt_' . wp_generate_password(8, false), 'type' => $type, 'created_at' => gmdate('c'), 'data' => $data]);
    return [$body, Signature::sign($body, TP_WEBHOOK_SECRET)];
}

$phase = $args[0] ?? 'interne';

// --- Réglages, comme un marchand les poserait ------------------------------------------------------
update_option('woocommerce_currency', 'XOF');
update_option('woocommerce_technas_pay_mobile_money_settings', ['enabled' => 'yes', 'title' => 'Mobile money', 'description' => 'Orange Money, MTN…', 'secret' => TP_SECRET]);
update_option('woocommerce_technas_pay_card_settings', ['enabled' => 'yes', 'title' => 'Carte bancaire', 'description' => 'Carte']);
WC()->payment_gateways()->init();

if ($phase === 'http') {
    $order_id = (int) file_get_contents("$out_dir/order-http.txt");
    $order = wc_get_order($order_id);
    tp_check($order->is_paid(), "l'avis signé reçu sur /?wc-api=technas_pay a marqué la commande $order_id payée", $order->get_status());
    $bad_id = (int) file_get_contents("$out_dir/order-http-bad.txt");
    tp_check(!wc_get_order($bad_id)->is_paid(), "l'avis mal signé n'a rien payé (commande $bad_id)");
    WP_CLI::success('Intégration HTTP : OK');
    return;
}

$fake = new TP_Fake_Transport();
add_filter('technas_pay_wc_transport', fn () => $fake);

tp_check(has_action('woocommerce_api_technas_pay') !== false, "l'adresse d'avis /?wc-api=technas_pay est branchée");
$gateways = WC()->payment_gateways()->payment_gateways();
$mm = $gateways['technas_pay_mobile_money'] ?? null;
$card = $gateways['technas_pay_card'] ?? null;
tp_check($mm instanceof Technas_Pay_Gateway_Mobile_Money && $card instanceof Technas_Pay_Gateway_Card, 'les deux moyens sont déclarés à WooCommerce');
tp_check($mm->is_available(), 'mobile money disponible (clé valide, boutique en XOF)');
tp_check(str_contains(Technas_Pay_WC::webhook_url(), 'technas_pay'), "l'adresse d'avis s'affiche dans les réglages", Technas_Pay_WC::webhook_url());

// 1. Payer : le paiement part avec le bon montant, la bonne référence, sans devise (zone CFA).
$order = tp_order();
$oid = $order->get_id();
$fake->queue[] = [201, tp_payment(['reference' => "$oid-1"])];
$result = $mm->process_payment($oid);
tp_check($result['result'] === 'success' && $result['redirect'] === 'https://pay-api.technas.fr/checkout/pay_1', 'process_payment redirige vers la page Technas', $result);
$sent = end($fake->calls);
tp_check($sent['path'] === '/v1/payments' && $sent['body']['amount'] === 5000 && $sent['body']['reference'] === "$oid-1", 'montant entier 5000 et référence {commande}-1', $sent['body']);
tp_check(($sent['body']['currency'] ?? null) === 'XOF', 'devise déduite du pays du client (TG → XOF), jamais de la boutique', $sent['body']['currency'] ?? null);
tp_check($sent['auth'] === 'Bearer pk_ci' && !str_contains(json_encode($fake->calls), 'ws_ci_secret'), 'seule la clé d\'API part, jamais le secret des avis');
tp_check(wc_get_order($oid)->get_status() === 'pending' && !wc_get_order($oid)->is_paid(), 'la commande attend : le retour du navigateur ne prouve rien');

// 2. L'avis signé, et lui seul, marque la commande payée ; relivré, il ne fait rien de plus.
[$body, $sig] = tp_signed_event('payment.completed', tp_payment(['reference' => "$oid-1", 'status' => 'completed', 'currency' => 'XOF', 'metadata' => ['order_id' => (string) $oid]]));
tp_check(Technas_Pay_Webhook::handle($body, 't=1,s=00')[0] === 400 && !wc_get_order($oid)->is_paid(), 'avis mal signé : 400, commande non payée');
tp_check(Technas_Pay_Webhook::handle($body, $sig)[0] === 200 && wc_get_order($oid)->is_paid(), 'avis signé : commande payée');
$notes = count(wc_get_order_notes(['order_id' => $oid]));
Technas_Pay_Webhook::handle($body, $sig);
tp_check(count(wc_get_order_notes(['order_id' => $oid])) === $notes, 'avis relivré : rien en double');

// 3. Montant différent : la commande ne passe pas payée, elle est mise en attente.
$o2 = tp_order();
[$b2, $s2] = tp_signed_event('payment.completed', tp_payment(['reference' => $o2->get_id() . '-1', 'amount' => 100, 'status' => 'completed', 'metadata' => ['order_id' => (string) $o2->get_id()]]));
Technas_Pay_Webhook::handle($b2, $s2);
tp_check(!wc_get_order($o2->get_id())->is_paid() && wc_get_order($o2->get_id())->get_status() === 'on-hold', 'montant différent : en attente, pas payée');

// 4. Une référence = un essai : un essai expiré est sauté, le suivant est créé.
$o3 = tp_order();
$id3 = $o3->get_id();
$fake->queue[] = [409, ['code' => 'payment_expired', 'message' => 'm']];
$fake->queue[] = [201, tp_payment(['id' => 'pay_3', 'reference' => "$id3-2"])];
$mm->process_payment($id3);
$refs = array_map(fn ($c) => $c['body']['reference'] ?? null, array_slice($fake->calls, -2));
tp_check($refs === ["$id3-1", "$id3-2"], 'essai expiré sauté : nouvelle référence {commande}-2', $refs);
tp_check((int) wc_get_order($id3)->get_meta('_technas_pay_attempt') === 2, "l'essai en cours est gardé sur la commande");
[$b3, $s3] = tp_signed_event('payment.expired', tp_payment(['id' => 'pay_3', 'reference' => "$id3-2", 'status' => 'expired', 'checkout_url' => null, 'metadata' => ['order_id' => (string) $id3]]));
Technas_Pay_Webhook::handle($b3, $s3);
tp_check((int) wc_get_order($id3)->get_meta('_technas_pay_attempt') === 3 && !wc_get_order($id3)->is_paid(), 'avis expiré : prochain essai {commande}-3, rien de payé');
[$b4, $s4] = tp_signed_event('payment.failed', tp_payment(['id' => 'pay_3', 'reference' => "$id3-3", 'status' => 'failed', 'metadata' => ['order_id' => (string) $id3]]));
Technas_Pay_Webhook::handle($b4, $s4);
tp_check(wc_get_order($id3)->get_status() === 'pending', 'refus : pas définitif, la commande n\'est pas annulée');

// 5. Rembourser depuis l'admin WooCommerce.
$fake->queue[] = [201, ['id' => 're_1', 'payment_id' => 'pay_1', 'payment_reference' => "$oid-1", 'amount' => 2000, 'sent_amount' => 2000, 'fee' => 100,
    'fees' => 'partner', 'balance_debit' => 2100, 'currency' => 'XOF', 'status' => 'completed', 'kind' => 'payout']];
$refund = wc_create_refund(['order_id' => $oid, 'amount' => 2000, 'reason' => 'Article manquant', 'refund_payment' => true]);
tp_check(!is_wp_error($refund), 'remboursement partiel accepté', is_wp_error($refund) ? $refund->get_error_message() : null);
$sent = end($fake->calls);
tp_check($sent['path'] === '/v1/payments/pay_1/refunds' && $sent['body']['amount'] === 2000 && str_starts_with($sent['body']['reference'], "wc-$oid-r"), 'remboursement envoyé à Technas (montant, référence idempotente)', $sent);
$fake->queue[] = [409, ['code' => 'insufficient_balance', 'message' => 'm', 'missing_amount' => 1500, 'currency' => 'XOF']];
$refused = wc_create_refund(['order_id' => $oid, 'amount' => 1000, 'refund_payment' => true]);
tp_check(is_wp_error($refused) && str_contains($refused->get_error_message(), '1500'), 'solde insuffisant : message clair avec le montant manquant', is_wp_error($refused) ? $refused->get_error_message() : $refused);

// 6. La carte s'ouvre seulement si le compte carte est prêt ; le mobile money exige une boutique en F CFA.
delete_transient('technas_pay_card_ready');
$fake->queue[] = [200, ['status' => 'enabled', 'can_accept_payments' => true]];
tp_check($card->is_available(), 'carte disponible quand le compte carte est prêt');
[$b5, $s5] = tp_signed_event('card_account.updated', ['status' => 'restricted', 'can_accept_payments' => false]);
Technas_Pay_Webhook::handle($b5, $s5);
tp_check(!$card->is_available(), 'avis card_account.updated : carte retirée aussitôt');
$o6 = tp_order(12.5);
update_option('woocommerce_currency', 'EUR');
tp_check(!$mm->is_available(), 'boutique en EUR : mobile money masqué');
$fake->queue[] = [201, tp_payment(['id' => 'pay_c', 'reference' => $o6->get_id() . '-card-1', 'amount' => 12.5, 'currency' => 'EUR', 'method' => 'card', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_c'])];
$o6->set_currency('EUR');
$o6->save();
$card->process_payment($o6->get_id());
$sent = end($fake->calls);
tp_check($sent['body']['method'] === 'card' && $sent['body']['currency'] === 'EUR' && $sent['body']['amount'] === 12.5 && $sent['body']['reference'] === $o6->get_id() . '-card-1', 'carte : method, devise, décimales et référence à part', $sent['body']);
update_option('woocommerce_currency', 'XOF');

// 7. Préparer l'essai HTTP réel (joué depuis l'hôte par curl, puis vérifié en phase « http »).
wp_mkdir_p($out_dir);
$h = tp_order();
[$hb, $hs] = tp_signed_event('payment.completed', tp_payment(['reference' => $h->get_id() . '-1', 'status' => 'completed', 'currency' => 'XOF', 'metadata' => ['order_id' => (string) $h->get_id()]]));
file_put_contents("$out_dir/order-http.txt", (string) $h->get_id());
file_put_contents("$out_dir/webhook-body.json", $hb);
file_put_contents("$out_dir/webhook-signature.txt", $hs);
$bad = tp_order();
[$bb] = tp_signed_event('payment.completed', tp_payment(['reference' => $bad->get_id() . '-1', 'status' => 'completed', 'metadata' => ['order_id' => (string) $bad->get_id()]]));
file_put_contents("$out_dir/order-http-bad.txt", (string) $bad->get_id());
file_put_contents("$out_dir/webhook-bad-body.json", $bb);

WP_CLI::success('Intégration interne : OK');
