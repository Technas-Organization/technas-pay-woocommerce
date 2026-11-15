<?php

defined('ABSPATH') || exit;

use TechnasPay\TechnasPayException;

/**
 * Base des deux moyens de paiement Technas Pay. Le client paie sur la page Technas (opérateur,
 * pays, carte) ; la commande n'est marquée payée QUE sur l'avis signé (`Technas_Pay_Webhook`),
 * jamais au retour du navigateur.
 */
abstract class Technas_Pay_Gateway extends WC_Payment_Gateway
{
    /** `mobile_money` ou `card`. */
    abstract protected function technas_method(): string;

    public function __construct()
    {
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    /** Champs communs : activer, titre, description. */
    protected function base_form_fields(string $title, string $description): array
    {
        return [
            'enabled' => [
                'title' => __('Enable', 'technas-pay-woocommerce'),
                'type' => 'checkbox',
                'label' => sprintf(/* translators: %s: payment method title */ __('Enable %s', 'technas-pay-woocommerce'), $title),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'technas-pay-woocommerce'),
                'type' => 'text',
                'description' => __('What the customer sees at checkout.', 'technas-pay-woocommerce'),
                'default' => $title,
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'technas-pay-woocommerce'),
                'type' => 'textarea',
                'default' => $description,
            ],
        ];
    }

    public function is_available(): bool
    {
        return parent::is_available() && Technas_Pay_WC::is_configured();
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'technas-pay-woocommerce'), 'error');
            return ['result' => 'failure'];
        }
        try {
            $payment = Technas_Pay_WC::start_payment($order, $this->technas_method(), $this->get_return_url($order));
        } catch (TechnasPayException $e) {
            $order->add_order_note(sprintf(
                /* translators: 1: error code */
                __('Technas Pay: the payment could not be started (%1$s).', 'technas-pay-woocommerce'),
                $e->errorCode,
            ));
            wc_add_notice(Technas_Pay_WC::customer_message($e), 'error');
            return ['result' => 'failure'];
        }
        if ($payment->status === 'completed') {
            // Essai déjà payé (même référence rejouée) : l'avis signé l'a peut-être déjà marquée.
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        if (!$payment->checkoutUrl) {
            wc_add_notice(__('The payment could not be started. Please try again.', 'technas-pay-woocommerce'), 'error');
            return ['result' => 'failure'];
        }
        $order->update_status('pending', __('Technas Pay: waiting for the customer to pay on the payment page.', 'technas-pay-woocommerce'));
        WC()->cart?->empty_cart();
        return ['result' => 'success', 'redirect' => $payment->checkoutUrl];
    }

    /**
     * Remboursement depuis l'admin WooCommerce (« Rembourser via Technas Pay »). L'argent repart au
     * numéro ou à la carte qui a payé ; la commission Technas n'est pas rendue.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        $payment_id = $order ? (string) $order->get_meta(Technas_Pay_WC::META_PAYMENT_ID) : '';
        if ($payment_id === '') {
            return new WP_Error('technas_pay_refund', __('This order has no Technas Pay payment to refund.', 'technas-pay-woocommerce'));
        }
        $method = (string) $order->get_meta(Technas_Pay_WC::META_METHOD) ?: 'mobile_money';
        $value = $amount === null ? null : ($method === 'card' ? round((float) $amount, 2) : (int) round((float) $amount));
        // Une référence par remboursement WooCommerce : rejouer la même demande ne rembourse pas deux fois.
        $reference = 'wc-' . $order->get_id() . '-r' . count($order->get_refunds());
        try {
            $refund = Technas_Pay_WC::client()->refund($payment_id, $value, 'partner', $reference);
        } catch (TechnasPayException $e) {
            $message = match ($e->errorCode) {
                'insufficient_balance' => sprintf(
                    /* translators: 1: missing amount, 2: currency */
                    __('Your Technas Pay balance is too low: top it up with at least %1$s %2$s, then refund again.', 'technas-pay-woocommerce'),
                    $e->details['missingAmount'] ?? '?',
                    $e->details['currency'] ?? '',
                ),
                'refund_exceeds_payment' => __('This amount is more than what can still be refunded.', 'technas-pay-woocommerce'),
                'payment_not_refundable' => __('Only a paid order can be refunded.', 'technas-pay-woocommerce'),
                default => sprintf(/* translators: %s: error code */ __('Technas Pay refused the refund (%s).', 'technas-pay-woocommerce'), $e->errorCode),
            };
            return new WP_Error('technas_pay_refund', $message);
        }
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: currency, 3: status, 4: reason */
            __('Technas Pay refund: %1$s %2$s (%3$s). %4$s', 'technas-pay-woocommerce'),
            $refund->amount,
            $refund->currency,
            $refund->status,
            $reason,
        ));
        return true;
    }
}
