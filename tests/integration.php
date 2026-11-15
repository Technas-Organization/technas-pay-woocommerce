<?php

defined('ABSPATH') || exit; // joué par `wp eval-file`, jamais servi par le web
/**
 * Test d'intégration, joué DANS un vrai WordPress + WooCommerce (wp-env, en CI) :
 *
 *   wp eval-file tests/integration.php interne   # tout ce qui se teste dans le processus
 *   (curl d'avis signés sur les vraies adresses, depuis l'hôte)
 *   wp eval-file tests/integration.php http      # les commandes sont bien passées à payées
 *
 * Les appels sortants vers Technas passent par un faux transport (filtre `technas_pay_wc_transport`) ;
 * les avis entrants sont signés avec le même secret que celui configuré, comme en vrai.
 * Chaque constat de la relecture de sécurité a son cas (E1, M1-M3, F1-F5, I1).
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

/** Un paiement tel que Technas le renvoie. `test_mode` faux par défaut : on teste le chemin réel. */
function tp_payment(array $over = []): array
{
    return $over + ['id' => 'pay_1', 'reference' => 'wc-1-1', 'amount' => 5000, 'currency' => null, 'commission' => 100, 'net_amount' => 4900,
        'status' => 'pending', 'method' => 'mobile_money', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_1', 'metadata' => [], 'test_mode' => false];
}

function tp_order(float $total = 5000, string $method = 'technas_pay_mobile_money'): WC_Order
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
    $order->set_payment_method($method);
    $order->calculate_totals();
    $order->save();
    return $order;
}

/** Les métadonnées que l'extension envoie elle-même pour cette commande. */
function tp_meta(WC_Order $order): array
{
    return ['order_id' => (string) $order->get_id(), 'order_key' => $order->get_order_key(), 'source' => 'woocommerce'];
}

function tp_signed_event(string $type, array $data, ?int $ts = null): array
{
    $body = json_encode(['id' => 'evt_' . wp_generate_password(12, false), 'type' => $type, 'created_at' => gmdate('c'), 'data' => $data]);
    return [$body, Signature::sign($body, TP_WEBHOOK_SECRET, $ts)];
}

/** Ouvre le paiement d'une commande par l'extension (faux Technas) ; rend l'id et la référence créés. */
function tp_start(TP_Fake_Transport $fake, Technas_Pay_Gateway $gateway, WC_Order $order, string $payment_id, array $over = []): array
{
    $ref = 'wc-' . $order->get_id() . (($over['method'] ?? 'mobile_money') === 'card' ? '-card' : '') . '-1';
    $fake->queue[] = [201, tp_payment(['id' => $payment_id, 'reference' => $ref, 'checkout_url' => "https://pay-api.technas.fr/checkout/$payment_id"] + $over)];
    $result = $gateway->process_payment($order->get_id());
    return [$payment_id, $ref, $result];
}

/** L'avis `payment.completed` que Technas enverrait pour ce paiement. */
function tp_completed(WC_Order $order, string $id, string $ref, array $over = []): array
{
    return tp_signed_event('payment.completed', $over + tp_payment(['id' => $id, 'reference' => $ref, 'status' => 'completed', 'currency' => 'XOF', 'metadata' => tp_meta($order)]));
}

$phase = $args[0] ?? 'interne';

// --- Réglages, comme un marchand les poserait ------------------------------------------------------
update_option('woocommerce_currency', 'XOF');
update_option('woocommerce_technas_pay_mobile_money_settings', ['enabled' => 'yes', 'title' => 'Mobile money', 'description' => 'Orange Money, MTN…', 'secret' => '']);
update_option('woocommerce_technas_pay_card_settings', ['enabled' => 'yes', 'title' => 'Carte bancaire', 'description' => 'Carte']);
Technas_Pay_WC::save_secret(TP_SECRET);
WC()->payment_gateways()->init();

if ($phase === 'http') {
    $paid = (int) file_get_contents("$out_dir/order-http.txt");
    tp_check(wc_get_order($paid)->is_paid(), "l'avis signé reçu sur la route REST a marqué la commande $paid payée", wc_get_order($paid)->get_status());
    $legacy = (int) file_get_contents("$out_dir/order-http-legacy.txt");
    tp_check(wc_get_order($legacy)->is_paid(), "l'avis signé reçu sur /?wc-api=technas_pay a marqué la commande $legacy payée");
    $bad = (int) file_get_contents("$out_dir/order-http-bad.txt");
    tp_check(!wc_get_order($bad)->is_paid(), "l'avis mal signé n'a rien payé (commande $bad)");
    WP_CLI::success('Intégration HTTP : OK');
    return;
}

$fake = new TP_Fake_Transport();
add_filter('technas_pay_wc_transport', fn () => $fake);

tp_check(has_action('woocommerce_api_technas_pay') !== false, "l'ancienne adresse /?wc-api=technas_pay est branchée");
$gateways = WC()->payment_gateways()->payment_gateways();
$mm = $gateways['technas_pay_mobile_money'] ?? null;
$card = $gateways['technas_pay_card'] ?? null;
tp_check($mm instanceof Technas_Pay_Gateway_Mobile_Money && $card instanceof Technas_Pay_Gateway_Card, 'les deux moyens sont déclarés à WooCommerce');
tp_check($mm->is_available(), 'mobile money disponible (clé valide, boutique en XOF)');
tp_check(str_contains(Technas_Pay_WC::webhook_url(), 'technas-pay/v1/webhook'), "l'adresse d'avis (route REST) s'affiche dans les réglages", Technas_Pay_WC::webhook_url());
tp_check(str_contains($mm->get_title(), 'TEST'), 'M2 : clé de test, le moyen affiche « TEST » au client', $mm->get_title());

// 0. Sécurité de la clé : option à part, sans autoload, jamais réaffichée, jamais dans les réglages WooCommerce.
global $wpdb;
$autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Technas_Pay_WC::SECRET_OPTION));
tp_check(in_array($autoload, ['no', 'off'], true), 'la clé n\'est pas chargée à chaque page (autoload désactivé)', $autoload);
tp_check(!str_contains((string) wp_json_encode(get_option('woocommerce_technas_pay_mobile_money_settings')), 'tps_'), 'la clé n\'est pas dans les réglages WooCommerce');
ob_start();
$mm->admin_options();
$html = (string) ob_get_clean();
tp_check(!str_contains($html, 'ws_ci_secret') && !str_contains($html, 'pk_ci.'), 'la page de réglages ne réaffiche jamais la clé');
tp_check(str_contains($html, '…') && str_contains($html, 'tps_test_'), 'seuls le mode et les 4 derniers caractères sont indiqués');
tp_check(!Technas_Pay_WC::save_secret('pas-une-cle') && Technas_Pay_WC::secret() === TP_SECRET, 'une clé mal formée est refusée, l\'ancienne reste');
tp_check(Technas_Pay_WC::save_secret('tps_test_pk%41b.ws%42c') && Technas_Pay_WC::secret() === 'tps_test_pk%41b.ws%42c', 'I1 : une clé contenant « %xx » est gardée intacte');
Technas_Pay_WC::save_secret(TP_SECRET);
tp_check(file_exists(TECHNAS_PAY_WC_DIR . '/uninstall.php') && str_contains((string) file_get_contents(TECHNAS_PAY_WC_DIR . '/uninstall.php'), "delete_option( 'technas_pay_secret' )"), 'F5 : la désinstallation efface la clé');
tp_check(version_compare(TechnasPay\TechnasPay::VERSION, '0.2.1', '>='), 'M1 : le SDK embarqué est au moins 0.2.1 (durcie en 0.1.1, kind « card » en 0.1.2, seuil d\'identité en 0.2.0, avis complet en 0.2.1)', TechnasPay\TechnasPay::VERSION);

// 1. Payer : bon montant, référence de la boutique, devise déduite du pays.
$order = tp_order();
$oid = $order->get_id();
[$pid, $ref, $result] = tp_start($fake, $mm, $order, 'pay_1');
tp_check($result['result'] === 'success' && $result['redirect'] === 'https://pay-api.technas.fr/checkout/pay_1', 'process_payment redirige vers la page Technas', $result);
$sent = end($fake->calls);
tp_check($sent['path'] === '/v1/payments' && $sent['body']['amount'] === 5000 && $sent['body']['reference'] === "wc-$oid-1", 'montant entier 5000 et référence propre à la boutique wc-{commande}-1', $sent['body']);
tp_check(($sent['body']['currency'] ?? null) === 'XOF', 'devise déduite du pays du client (TG → XOF)', $sent['body']['currency'] ?? null);
tp_check($sent['auth'] === 'Bearer pk_ci' && !str_contains(json_encode($fake->calls), 'ws_ci_secret'), 'seule la clé d\'API part, jamais le secret des avis');
tp_check(wc_get_order($oid)->get_status() === 'pending' && !wc_get_order($oid)->is_paid(), 'la commande attend : le retour du navigateur ne prouve rien');

// 2. L'avis signé, et lui seul, marque la commande payée ; relivré ou rejoué, il ne fait rien de plus.
[$body, $sig] = tp_completed($order, $pid, $ref);
tp_check(Technas_Pay_Webhook::handle($body, 't=1,s=00')[0] === 400 && !wc_get_order($oid)->is_paid(), 'avis mal signé : 400, commande non payée');
tp_check(Technas_Pay_Webhook::handle($body, $sig)[0] === 200 && wc_get_order($oid)->is_paid(), 'avis signé : commande payée');
$notes = count(wc_get_order_notes(['order_id' => $oid]));
tp_check((Technas_Pay_Webhook::handle($body, $sig)[1]['duplicate'] ?? false) === true, 'avis rejoué (même id) : acquitté sans être retraité');
tp_check(count(wc_get_order_notes(['order_id' => $oid])) === $notes, 'avis relivré : rien en double');
tp_check(Technas_Pay_Webhook::handle($body, Signature::sign($body, TP_WEBHOOK_SECRET, time() - 3600))[0] === 400, 'avis rejoué avec sa vieille signature : refusé (400)');

// 3. E1 : un avis signé qui ne vient pas de CETTE boutique n'agit sur aucune commande.
$victim = tp_order();
[$vid, $vref] = tp_start($fake, $mm, $victim, 'pay_v');
$foreign = [
    'autre application (même numéro, référence sans wc-)' => ['reference' => $victim->get_id() . '-1', 'metadata' => ['order_id' => (string) $victim->get_id()]],
    'sans la marque woocommerce' => ['metadata' => ['order_id' => (string) $victim->get_id(), 'order_key' => $victim->get_order_key()]],
    'mauvaise clé de commande' => ['metadata' => ['order_key' => 'wc_order_faux'] + tp_meta($victim)],
    'paiement jamais créé pour cette commande' => ['id' => 'pay_inconnu'],
    'référence jamais créée pour cette commande' => ['reference' => 'wc-' . $victim->get_id() . '-7'],
];
foreach ($foreign as $what => $over) {
    [$fb, $fs] = tp_completed($victim, $vid, $vref, $over);
    $r = Technas_Pay_Webhook::handle($fb, $fs);
    tp_check($r[0] === 200 && !wc_get_order($victim->get_id())->is_paid() && wc_get_order($victim->get_id())->get_status() === 'pending', "E1 : avis étranger ($what) : ignoré, commande intacte");
}
$cod = tp_order(5000, 'cod');
$cod->update_meta_data(Technas_Pay_WC::META_REFERENCES, ['wc-' . $cod->get_id() . '-1']);
$cod->update_meta_data(Technas_Pay_WC::META_PAYMENT_IDS, ['pay_cod']);
$cod->save();
[$cb, $cs] = tp_completed($cod, 'pay_cod', 'wc-' . $cod->get_id() . '-1');
Technas_Pay_Webhook::handle($cb, $cs);
tp_check(!wc_get_order($cod->get_id())->is_paid(), 'E1 : une commande en paiement à la livraison n\'est jamais marquée payée par Technas');

// 4. Montant ou devise différents : la commande ne passe pas payée, elle est mise en attente.
$o2 = tp_order();
[$id2, $ref2] = tp_start($fake, $mm, $o2, 'pay_2');
[$b2, $s2] = tp_completed($o2, $id2, $ref2, ['amount' => 100]);
Technas_Pay_Webhook::handle($b2, $s2);
tp_check(!wc_get_order($o2->get_id())->is_paid() && wc_get_order($o2->get_id())->get_status() === 'on-hold', 'montant différent : en attente, pas payée');
$o2b = tp_order();
[$id2b, $ref2b] = tp_start($fake, $mm, $o2b, 'pay_2b');
[$b2b, $s2b] = tp_completed($o2b, $id2b, $ref2b, ['currency' => 'GHS']);
Technas_Pay_Webhook::handle($b2b, $s2b);
tp_check(!wc_get_order($o2b->get_id())->is_paid() && wc_get_order($o2b->get_id())->get_status() === 'on-hold', 'E1 : devise différente (GHS pour une commande en XOF) : en attente, pas payée');
$o2c = tp_order();
[$id2c, $ref2c] = tp_start($fake, $mm, $o2c, 'pay_2c');
[$b2c, $s2c] = tp_completed($o2c, $id2c, $ref2c, ['currency' => 'XAF']);
Technas_Pay_Webhook::handle($b2c, $s2c);
tp_check(wc_get_order($o2c->get_id())->is_paid(), 'mobile money : XAF accepté pour une commande en XOF (même franc CFA)');

// 5. M2 : un paiement de TEST ne rend jamais une commande « payée ».
$ot = tp_order();
[$idt, $reft] = tp_start($fake, $mm, $ot, 'pay_t', ['test_mode' => true]);
[$bt, $st] = tp_completed($ot, $idt, $reft, ['test_mode' => true]);
Technas_Pay_Webhook::handle($bt, $st);
tp_check(!wc_get_order($ot->get_id())->is_paid() && wc_get_order($ot->get_id())->get_status() === 'on-hold', 'M2 : paiement de test : en attente avec la raison, jamais payée');

// 6. M3 : un second paiement réussi pour une commande déjà payée est signalé, jamais ignoré.
$o3 = tp_order();
[$id3, $ref3] = tp_start($fake, $mm, $o3, 'pay_3a');
[$b3, $s3] = tp_completed($o3, $id3, $ref3);
Technas_Pay_Webhook::handle($b3, $s3);
$fake->queue[] = [201, tp_payment(['id' => 'pay_3b', 'reference' => 'wc-' . $o3->get_id() . '-card-1', 'method' => 'card', 'currency' => 'XOF', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_3b'])];
Technas_Pay_WC::start_payment(wc_get_order($o3->get_id()), 'card', 'https://boutique.test/merci');
[$b3b, $s3b] = tp_completed(wc_get_order($o3->get_id()), 'pay_3b', 'wc-' . $o3->get_id() . '-card-1', ['method' => 'card']);
Technas_Pay_Webhook::handle($b3b, $s3b);
tp_check(wc_get_order($o3->get_id())->get_status() === 'on-hold', 'M3 : second paiement reçu : commande en attente, à rembourser', wc_get_order($o3->get_id())->get_status());

// 7. Une référence = un essai : un essai expiré est sauté, le suivant est créé.
$o4 = tp_order();
$id4 = $o4->get_id();
$fake->queue[] = [409, ['code' => 'payment_expired', 'message' => 'm']];
$fake->queue[] = [201, tp_payment(['id' => 'pay_4', 'reference' => "wc-$id4-2"])];
$mm->process_payment($id4);
$refs = array_map(fn ($c) => $c['body']['reference'] ?? null, array_slice($fake->calls, -2));
tp_check($refs === ["wc-$id4-1", "wc-$id4-2"], 'essai expiré sauté : nouvelle référence wc-{commande}-2', $refs);
tp_check((int) wc_get_order($id4)->get_meta('_technas_pay_attempt') === 2, "l'essai en cours est gardé sur la commande");
[$b4, $s4] = tp_signed_event('payment.expired', tp_payment(['id' => 'pay_4', 'reference' => "wc-$id4-2", 'status' => 'expired', 'checkout_url' => null, 'metadata' => tp_meta($o4)]));
Technas_Pay_Webhook::handle($b4, $s4);
tp_check((int) wc_get_order($id4)->get_meta('_technas_pay_attempt') === 3 && !wc_get_order($id4)->is_paid(), 'avis expiré : prochain essai wc-{commande}-3, rien de payé');
[$b4f, $s4f] = tp_signed_event('payment.failed', tp_payment(['id' => 'pay_4', 'reference' => "wc-$id4-2", 'status' => 'failed', 'metadata' => tp_meta($o4)]));
Technas_Pay_Webhook::handle($b4f, $s4f);
tp_check(wc_get_order($id4)->get_status() === 'pending', 'refus : pas définitif, la commande n\'est pas annulée');

// 8. F3 : une adresse de paiement inattendue n'est jamais suivie.
$o5 = tp_order();
$fake->queue[] = [201, tp_payment(['id' => 'pay_5', 'reference' => 'wc-' . $o5->get_id() . '-1', 'checkout_url' => 'https://pay-api.technas.fr.hameconnage.example/checkout/x'])];
tp_check($mm->process_payment($o5->get_id())['result'] === 'failure', 'F3 : page de paiement sur un autre hôte : aucune redirection');
$o5b = tp_order();
$fake->queue[] = [201, tp_payment(['id' => 'pay_5b', 'reference' => 'wc-' . $o5b->get_id() . '-1', 'checkout_url' => 'javascript:alert(1)'])];
tp_check($mm->process_payment($o5b->get_id())['result'] === 'failure', 'F3 : adresse javascript: : aucune redirection');

// 9. F1 et F4 : livraison concurrente et corps démesuré.
$o6 = tp_order();
[$id6, $ref6] = tp_start($fake, $mm, $o6, 'pay_6');
[$b6, $s6] = tp_completed($o6, $id6, $ref6);
$event_id = json_decode($b6, true)['id'];
add_option('technas_pay_lock_' . md5($event_id), time(), '', false);
tp_check(Technas_Pay_Webhook::handle($b6, $s6)[0] === 409 && !wc_get_order($o6->get_id())->is_paid(), 'F1 : même avis en cours de traitement : 409, relivré plus tard');
delete_option('technas_pay_lock_' . md5($event_id));
tp_check(Technas_Pay_Webhook::handle($b6, $s6)[0] === 200 && wc_get_order($o6->get_id())->is_paid(), 'F1 : relivré après : traité une seule fois');
tp_check(Technas_Pay_Webhook::handle(str_repeat('x', 70000), $s6)[0] === 413, 'F4 : corps de plus de 64 Ko : refusé sans rien lire');

// 10. Rembourser depuis l'admin WooCommerce (F2 : référence par remboursement, montant contrôlé).
wp_set_current_user(1);
$fake->queue[] = [201, ['id' => 're_1', 'payment_id' => $pid, 'payment_reference' => $ref, 'amount' => 2000, 'sent_amount' => 2000, 'fee' => 100,
    'fees' => 'partner', 'balance_debit' => 2100, 'currency' => 'XOF', 'status' => 'completed', 'kind' => 'payout']];
$refund = wc_create_refund(['order_id' => $oid, 'amount' => 2000, 'reason' => 'Article <b>manquant</b>', 'refund_payment' => true]);
tp_check(!is_wp_error($refund), 'remboursement partiel accepté', is_wp_error($refund) ? $refund->get_error_message() : null);
$sent = end($fake->calls);
tp_check($sent['path'] === "/v1/payments/$pid/refunds" && $sent['body']['amount'] === 2000 && $sent['body']['reference'] === "wc-$oid-refund-" . $refund->get_id(), 'F2 : référence = identifiant du remboursement WooCommerce', $sent['body']);
$fake->queue[] = [201, ['id' => 're_1', 'payment_id' => $pid, 'payment_reference' => $ref, 'amount' => 2000, 'currency' => 'XOF', 'status' => 'completed', 'kind' => 'payout']];
$mismatch = wc_create_refund(['order_id' => $oid, 'amount' => 500, 'refund_payment' => true]);
tp_check(is_wp_error($mismatch), 'F2 : Technas rend un autre montant : le remboursement WooCommerce est refusé', $mismatch);
$fake->queue[] = [409, ['code' => 'insufficient_balance', 'message' => 'm', 'missing_amount' => 1500, 'currency' => 'XOF']];
$refused = wc_create_refund(['order_id' => $oid, 'amount' => 1000, 'refund_payment' => true]);
tp_check(is_wp_error($refused) && str_contains($refused->get_error_message(), '1500'), 'solde insuffisant : message clair avec le montant manquant', is_wp_error($refused) ? $refused->get_error_message() : $refused);
wp_set_current_user(0);

// 11. La carte s'ouvre seulement si le compte carte est prêt ; le mobile money exige une boutique en F CFA.
delete_transient('technas_pay_card_ready');
$fake->queue[] = [200, ['status' => 'enabled', 'can_accept_payments' => true]];
tp_check($card->is_available(), 'carte disponible quand le compte carte est prêt');
[$b7, $s7] = tp_signed_event('card_account.updated', ['status' => 'restricted', 'can_accept_payments' => false]);
Technas_Pay_Webhook::handle($b7, $s7);
tp_check(!$card->is_available(), 'avis card_account.updated : carte retirée aussitôt');
$o8 = tp_order(12.5, 'technas_pay_card');
update_option('woocommerce_currency', 'EUR');
tp_check(!$mm->is_available(), 'boutique en EUR : mobile money masqué');
$o8->set_currency('EUR');
$o8->save();
$fake->queue[] = [201, tp_payment(['id' => 'pay_c', 'reference' => 'wc-' . $o8->get_id() . '-card-1', 'amount' => 12.5, 'currency' => 'EUR', 'method' => 'card', 'checkout_url' => 'https://pay-api.technas.fr/checkout/pay_c'])];
$card->process_payment($o8->get_id());
$sent = end($fake->calls);
tp_check($sent['body']['method'] === 'card' && $sent['body']['currency'] === 'EUR' && $sent['body']['amount'] === 12.5 && $sent['body']['reference'] === 'wc-' . $o8->get_id() . '-card-1', 'carte : method, devise, décimales et référence à part', $sent['body']);
update_option('woocommerce_currency', 'XOF');
$rk = ['id' => 're_c', 'payment_id' => 'pay_c', 'payment_reference' => 'wc-1-card-1', 'amount' => 12.5, 'currency' => 'EUR', 'status' => 'completed'];
tp_check(\TechnasPay\Model\Refund::fromApi($rk + ['kind' => 'stripe'])->kind === 'card' && \TechnasPay\Model\Refund::fromApi($rk + ['kind' => 'card'])->kind === 'card', 'remboursement carte : kind « card », ancien « stripe » lu comme « card » (SDK embarqué)');

// 12. Seuil d'identité : ligne des réglages et notice (clé tps_live_ seulement), avis account.kyc_required,
//     refus kyc_required au paiement (message neutre au client, note de journal pour le marchand).
$console = 'https://pay.technas.fr/console/identite';
$kyc_soon = ['applies' => true, 'stage' => 'soon', 'level' => 'none', 'days_elapsed' => 12, 'warn_from_xof' => 800000, 'warn_from_date' => null,
    'collected_xof' => 812500, 'threshold_xof' => 1000000, 'grace_days' => 30, 'first_collected_at' => '2026-11-03T10:00:00Z',
    'deadline' => '2026-12-03T10:00:00Z', 'waiver_until' => null, 'blocked' => false, 'reason' => null, 'console_url' => $console];
$kyc_html = function () use ($mm): string {
    ob_start();
    $mm->admin_options();
    return (string) ob_get_clean();
};
$kyc_notice = function (): string {
    ob_start();
    Technas_Pay_WC::kyc_admin_notice();
    return (string) ob_get_clean();
};
$calls = count($fake->calls);
tp_check(!str_contains($kyc_html(), 'technas-pay-kyc') && count($fake->calls) === $calls, 'seuil : clé de test, aucune ligne « Seuil d\'identité » et aucun appel à Technas');
Technas_Pay_WC::save_secret('tps_live_pk_ci.' . TP_WEBHOOK_SECRET);
$fake->queue[] = [200, $kyc_soon];
$html = $kyc_html();
$sent = end($fake->calls);
tp_check($sent['method'] === 'GET' && $sent['path'] === '/v1/account/kyc' && $sent['auth'] === 'Bearer pk_ci', 'seuil : clé tps_live_, lu par getAccountKyc (GET /v1/account/kyc)', $sent);
tp_check(str_contains($html, 'technas-pay-kyc') && str_contains($html, number_format_i18n(812500)) && str_contains($html, number_format_i18n(1000000)) && str_contains($html, $console)
    && str_contains($html, wp_date(get_option('date_format'), strtotime('2026-12-03T10:00:00Z'))), 'seuil : la ligne des réglages montre l\'encaissé, le seuil, l\'échéance et le lien vers la console');
$calls = count($fake->calls);
$kyc_html();
tp_check(count($fake->calls) === $calls && is_array(get_transient(Technas_Pay_WC::KYC_TRANSIENT)), 'seuil : gardé une heure dans un transient (pas de second appel)');
tp_check(has_action('admin_notices', [Technas_Pay_WC::class, 'kyc_admin_notice']) !== false, 'seuil : la notice est branchée sur admin_notices');
wp_set_current_user(1);
$notice = $kyc_notice();
tp_check(str_contains($notice, 'notice-warning') && str_contains($notice, number_format_i18n(812500)) && str_contains($notice, $console), 'seuil : stage « soon », notice d\'avertissement pour un administrateur', $notice);
wp_set_current_user(0);
tp_check($kyc_notice() === '', 'seuil : pas de notice sans le droit manage_woocommerce');
wp_set_current_user(1);
Technas_Pay_WC::store_kyc(\TechnasPay\Model\AccountKyc::fromApi(['stage' => 'ok'] + $kyc_soon));
tp_check($kyc_notice() === '', 'seuil : stage « ok », aucune notice');
$calls = count($fake->calls);
[$bk, $sk] = tp_signed_event('account.kyc_required', ['reason' => 'blocked', 'collected_xof' => 1004000, 'threshold_xof' => 1000000, 'deadline' => '2026-12-03T10:00:00Z', 'blocked' => true, 'console_url' => $console]);
$notes_before = count(wc_get_order_notes(['order_id' => $oid]));
tp_check(Technas_Pay_Webhook::handle($bk, $sk)[0] === 200, 'avis account.kyc_required signé : acquitté (200)');
$cached = get_transient(Technas_Pay_WC::KYC_TRANSIENT);
tp_check(is_array($cached) && $cached['stage'] === 'blocked' && $cached['collected_xof'] === 1004000 && $cached['blocked'] === true, 'avis account.kyc_required : le transient est mis à jour (bloqué, encaissé)', $cached);
tp_check(is_array($cached) && $cached['stage'] === 'blocked' && $cached['reason'] === null && $cached['days_elapsed'] === 12, 'avis account.kyc_required sans stage (avis ancien) : stage déduit de blocked, motif et compteurs gardés', $cached);
tp_check(count($fake->calls) === $calls && count(wc_get_order_notes(['order_id' => $oid])) === $notes_before, 'avis account.kyc_required : rien d\'autre (aucun appel, aucune commande touchée)');
$notice = $kyc_notice();
tp_check(str_contains($notice, 'notice-error') && str_contains($notice, $console), 'seuil : stage « blocked », notice d\'erreur pour un administrateur', $notice);
// Avis complet : stage et block_reason lus tels quels, pas déduits (blocked=false et stage « blocked » : seule la lecture donne « blocked »).
[$bk2, $sk2] = tp_signed_event('account.kyc_required', ['reason' => 'deadline_7d', 'collected_xof' => 640000, 'threshold_xof' => 1000000, 'deadline' => '2026-12-03T10:00:00Z', 'blocked' => false, 'console_url' => $console,
    'stage' => 'blocked', 'block_reason' => 'duration', 'days_elapsed' => 31, 'grace_days' => 30, 'first_collected_at' => '2026-11-01T10:00:00Z']);
tp_check(Technas_Pay_Webhook::handle($bk2, $sk2)[0] === 200, 'avis account.kyc_required avec stage : acquitté (200)');
$cached = get_transient(Technas_Pay_WC::KYC_TRANSIENT);
tp_check(is_array($cached) && $cached['stage'] === 'blocked' && $cached['reason'] === 'duration' && $cached['days_elapsed'] === 31 && $cached['grace_days'] === 30
    && $cached['first_collected_at'] === '2026-11-01T10:00:00Z' && $cached['collected_xof'] === 640000, 'avis account.kyc_required avec stage : stage et block_reason lus tels quels (pas déduits de blocked)', $cached);
wp_set_current_user(0);
$logged = [];
$log_filter = function ($message) use (&$logged) {
    $logged[] = (string) $message;
    return $message;
};
add_filter('woocommerce_logger_log_message', $log_filter);
$ok = tp_order();
$fake->queue[] = [409, ['code' => 'kyc_required', 'message' => 'm', 'console_url' => $console, 'reason' => 'amount']];
$r = $mm->process_payment($ok->get_id());
remove_filter('woocommerce_logger_log_message', $log_filter);
$neutral = 'Mobile money payment is not available right now. Please choose card or try again later.';
tp_check($r['result'] === 'failure', 'refus kyc_required au paiement : la commande n\'est pas envoyée vers Technas', $r);
tp_check(Technas_Pay_WC::customer_message(new \TechnasPay\TechnasPayException('kyc_required', 'm', 409, null, ['consoleUrl' => $console])) === $neutral, 'refus kyc_required : message client neutre, sans rien du compte du marchand');
$mo = new MO();
$mo->import_from_file(TECHNAS_PAY_WC_DIR . '/languages/technas-pay-woocommerce-fr_FR.mo');
tp_check($mo->translate($neutral) === 'Le paiement mobile money n\'est pas disponible pour le moment. Choisissez la carte ou réessayez plus tard.', 'refus kyc_required : message traduit en français (.mo recompilé)', $mo->translate($neutral));
tp_check($mo->translate('Identity threshold') === 'Seuil d\'identité', 'seuil : libellé de la ligne traduit en français (.mo recompilé)');
$journal = implode("\n", $logged);
tp_check(str_contains($journal, 'kyc_required') && str_contains($journal, $console), 'refus kyc_required : note de journal pour le marchand, avec le lien vers la console', $logged);
tp_check(get_transient(Technas_Pay_WC::KYC_TRANSIENT) === false, 'refus kyc_required : l\'état gardé est relu au prochain affichage');
Technas_Pay_WC::save_secret(TP_SECRET);
tp_check(get_transient(Technas_Pay_WC::KYC_TRANSIENT) === false && Technas_Pay_WC::account_kyc() === null, 'seuil : nouvelle clé (test), état effacé et plus rien de lu');

// 13. Préparer l'essai HTTP réel (joué depuis l'hôte par curl, puis vérifié en phase « http »).
wp_mkdir_p($out_dir);
$h = tp_order();
[$hid, $href] = tp_start($fake, $mm, $h, 'pay_http');
[$hb, $hs] = tp_completed($h, $hid, $href);
file_put_contents("$out_dir/order-http.txt", (string) $h->get_id());
file_put_contents("$out_dir/webhook-body.json", $hb);
file_put_contents("$out_dir/webhook-signature.txt", $hs);
$h2 = tp_order();
[$hid2, $href2] = tp_start($fake, $mm, $h2, 'pay_http2');
[$hb2, $hs2] = tp_completed($h2, $hid2, $href2);
file_put_contents("$out_dir/order-http-legacy.txt", (string) $h2->get_id());
file_put_contents("$out_dir/webhook-legacy-body.json", $hb2);
file_put_contents("$out_dir/webhook-legacy-signature.txt", $hs2);
$bad = tp_order();
[$bid, $bref] = tp_start($fake, $mm, $bad, 'pay_http_bad');
[$bb] = tp_completed($bad, $bid, $bref);
file_put_contents("$out_dir/order-http-bad.txt", (string) $bad->get_id());
file_put_contents("$out_dir/webhook-bad-body.json", $bb);

WP_CLI::success('Intégration interne : OK');
