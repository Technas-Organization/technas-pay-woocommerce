<?php

defined('ABSPATH') || exit;

use TechnasPay\TechnasPayException;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\WebhookHandlers;

/**
 * Les avis signés de Technas Pay, reçus sur `/?wc-api=technas_pay`. C'est le SEUL endroit où une
 * commande passe à « payée » : la signature est vérifiée sur le corps brut, le montant comparé à
 * celui de la commande. Un avis peut arriver plusieurs fois : tout est idempotent.
 */
final class Technas_Pay_Webhook
{
    private const CARD_READY_TRANSIENT = 'technas_pay_card_ready';

    /** Point d'entrée WooCommerce (`woocommerce_api_technas_pay`). */
    public static function handle_request(): void
    {
        $raw = (string) file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_TECHNAS_PAYMENT_SIGNATURE']) ? (string) $_SERVER['HTTP_X_TECHNAS_PAYMENT_SIGNATURE'] : null;
        try {
            [$status, $body] = self::handle($raw, $signature);
        } catch (\Throwable $e) {
            // Erreur de notre côté (base indisponible…) : 500, Technas relivrera.
            wc_get_logger()->error('Technas Pay webhook: ' . $e->getMessage(), ['source' => 'technas-pay']);
            [$status, $body] = [500, ['error' => 'handler_failed']];
        }
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($body);
        exit;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public static function handle(string $raw, ?string $signature): array
    {
        if (!Technas_Pay_WC::is_configured()) {
            return [503, ['error' => 'not_configured']];
        }
        $result = Technas_Pay_WC::client()->handleWebhook($raw, $signature, new WebhookHandlers(
            onCompleted: static fn (Event $e) => self::on_completed($e),
            onFailed: static fn (Event $e) => self::note($e, __('Technas Pay: payment declined. This is not final, the customer can still pay on the same page.', 'technas-pay-woocommerce')),
            onExpired: static fn (Event $e) => self::on_expired($e),
            onRefund: static fn (Event $e) => self::on_refund($e),
            onCardAccountUpdated: static fn (Event $e) => set_transient(self::CARD_READY_TRANSIENT, $e->cardAccount?->canAcceptPayments ? 'yes' : 'no', HOUR_IN_SECONDS),
        ));
        return [$result['status'], $result['body']];
    }

    private static function on_completed(Event $e): void
    {
        $payment = $e->payment;
        $order = $payment ? Technas_Pay_WC::order_for($payment) : null;
        if (!$order) {
            return; // Paiement d'une autre application sur la même clé : rien à faire ici.
        }
        if ($order->is_paid()) {
            return; // Avis relivré : déjà traité.
        }
        $method = $payment->method === 'card' ? 'card' : 'mobile_money';
        // Hors zone franc CFA, le montant demandé (en F CFA) est dans originalAmount.
        $paid = $payment->originalAmount ?? $payment->amount;
        $expected = Technas_Pay_WC::amount_for($order, $method);
        if (abs((float) $paid - (float) $expected) > 0.009) {
            $order->update_status('on-hold', sprintf(
                /* translators: 1: amount paid, 2: amount expected */
                __('Technas Pay: amount paid (%1$s) differs from the order total (%2$s). Check before shipping.', 'technas-pay-woocommerce'),
                $paid,
                $expected,
            ));
            return;
        }
        $order->update_meta_data(Technas_Pay_WC::META_PAYMENT_ID, $payment->id);
        $order->update_meta_data(Technas_Pay_WC::META_METHOD, $method);
        $order->payment_complete($payment->id);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: currency, 3: reference */
            __('Technas Pay: payment received, %1$s %2$s (reference %3$s).', 'technas-pay-woocommerce'),
            $payment->amount,
            (string) $payment->currency,
            $payment->reference,
        ));
    }

    private static function on_expired(Event $e): void
    {
        $order = $e->payment ? Technas_Pay_WC::order_for($e->payment) : null;
        if (!$order || $order->is_paid()) {
            return;
        }
        // Définitif : le prochain « Payer » de cette commande crée un NOUVEL essai (référence suivante).
        if (preg_match('/-(\d+)$/', $e->payment->reference, $m)) {
            $order->update_meta_data(Technas_Pay_WC::META_ATTEMPT, max((int) $order->get_meta(Technas_Pay_WC::META_ATTEMPT), (int) $m[1] + 1));
            $order->save();
        }
        $order->add_order_note(__('Technas Pay: the payment link expired, nothing was paid. The customer can pay again from the order page.', 'technas-pay-woocommerce'));
    }

    private static function on_refund(Event $e): void
    {
        $refund = $e->refund;
        $order = $refund ? wc_get_order(self::order_id_from_reference($refund->paymentReference)) : null;
        if (!$order instanceof WC_Order) {
            return;
        }
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: currency, 3: status */
            __('Technas Pay refund update: %1$s %2$s, %3$s.', 'technas-pay-woocommerce'),
            $refund->amount,
            $refund->currency,
            $refund->status,
        ));
    }

    private static function note(Event $e, string $text): void
    {
        $order = $e->payment ? Technas_Pay_WC::order_for($e->payment) : null;
        if ($order && !$order->is_paid()) {
            $order->add_order_note($text);
        }
    }

    private static function order_id_from_reference(string $reference): int
    {
        return preg_match('/^(\d+)-(?:card-)?\d+$/', $reference, $m) ? (int) $m[1] : 0;
    }

    /** Le compte carte peut-il encaisser ? Gardé une heure, et mis à jour par l'avis `card_account.updated`. */
    public static function card_account_ready(): bool
    {
        $cached = get_transient(self::CARD_READY_TRANSIENT);
        if ($cached === 'yes' || $cached === 'no') {
            return $cached === 'yes';
        }
        try {
            $ready = Technas_Pay_WC::client()->getCardAccount()->canAcceptPayments;
        } catch (TechnasPayException) {
            $ready = false;
        }
        set_transient(self::CARD_READY_TRANSIENT, $ready ? 'yes' : 'no', $ready ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
        return $ready;
    }
}
