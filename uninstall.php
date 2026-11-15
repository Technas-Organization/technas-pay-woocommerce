<?php
/**
 * Désinstallation : la clé et les états gardés par l'extension sont effacés de la base.
 * Les métadonnées des commandes restent (historique comptable).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'technas_pay_secret' );
delete_option( 'woocommerce_technas_pay_mobile_money_settings' );
delete_option( 'woocommerce_technas_pay_card_settings' );
delete_transient( 'technas_pay_card_ready' );
