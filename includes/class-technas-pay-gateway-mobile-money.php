<?php

defined('ABSPATH') || exit;

/**
 * Mobile money (Orange Money, MTN MoMo, Moov, Wave, Mixx…). Porte aussi les réglages communs : la
 * clé Technas Pay et l'adresse d'avis à copier.
 */
final class Technas_Pay_Gateway_Mobile_Money extends Technas_Pay_Gateway
{
    public function __construct()
    {
        $this->id = 'technas_pay_mobile_money';
        $this->method_title = __('Mobile money (Technas Pay)', 'technas-pay-woocommerce');
        $this->method_description = __('Orange Money, MTN MoMo, Moov Money, Wave, Mixx… The customer chooses their country and operator on the Technas Pay page. 2% per successful payment, no subscription.', 'technas-pay-woocommerce');
        parent::__construct();
    }

    protected function technas_method(): string
    {
        return 'mobile_money';
    }

    public function init_form_fields(): void
    {
        $this->form_fields = $this->base_form_fields(
            __('Mobile money', 'technas-pay-woocommerce'),
            __('Pay with Orange Money, MTN MoMo, Moov Money, Wave or Mixx.', 'technas-pay-woocommerce'),
        ) + [
            'secret' => [
                'title' => __('Technas Pay key', 'technas-pay-woocommerce'),
                'type' => 'password',
                'description' => __('Starts with tps_test_ (test, no money moves) or tps_live_ (real payments). Shared by mobile money and card. Ask contact@technas.fr for a test key.', 'technas-pay-woocommerce'),
                'default' => '',
            ],
            'webhook' => [
                'title' => __('Notification address', 'technas-pay-woocommerce'),
                'type' => 'title',
                /* translators: %s: webhook URL */
                'description' => sprintf(__('Give this address to Technas: it is how your orders get marked as paid. <br><code>%s</code>', 'technas-pay-woocommerce'), esc_html(Technas_Pay_WC::webhook_url())),
            ],
        ];
    }

    /** La clé est vérifiée à l'enregistrement : une clé mal copiée ne casse pas la boutique en silence. */
    public function validate_secret_field($key, $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && !preg_match('/^tps_(live|test)_[^.\s]+\.[^.\s]+$/', $value)) {
            WC_Admin_Settings::add_error(__('This Technas Pay key looks wrong: it must start with tps_test_ or tps_live_. Copy it again.', 'technas-pay-woocommerce'));
            return (string) $this->get_option('secret');
        }
        return $value;
    }

    /** Le mobile money encaisse en francs CFA : la boutique doit vendre en XOF ou en XAF. */
    public function is_available(): bool
    {
        return parent::is_available() && in_array(get_woocommerce_currency(), ['XOF', 'XAF'], true);
    }
}
