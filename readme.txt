=== Technas Pay for WooCommerce ===
Contributors: technas
Tags: mobile money, orange money, mtn momo, wave, payments
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept mobile money (Orange Money, MTN MoMo, Moov, Wave…) and card payments in West and Central Africa. Card: 1% (plus your card account fees). Mobile money: 2% (operator fees are paid by the customer). No subscription.

== Description ==

Technas Pay brings mobile money and cards together. Your customers pay with Orange Money, MTN MoMo, Moov Money or Wave, or by card (with Apple Pay and Google Pay).

* Orders are marked paid only on Technas Pay's signed notification, never on the browser return.
* A declined payment is not final: the customer can retry on the same page. An expired link can be paid again from the order, without paying twice.
* Refund from the WooCommerce order screen, in full or in part.
* Works with the classic and block checkout, and with HPOS.

Mobile money requires a store currency in CFA francs (XOF or XAF). Cards require an active Technas Pay card account.

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload, then activate it.
2. WooCommerce > Settings > Payments > Mobile money (Technas Pay): paste your Technas Pay key (ask contact@technas.fr for a test key) and send the notification address shown there to Technas.
3. Enable "Mobile money" and, if you have a card account, "Card".

== Changelog ==

= 0.1.2 =
* Operator list matches the networks open today (Orange Money, MTN MoMo, Moov Money, Wave).

= 0.1.1 =
* Card refunds are reported as "card" (Technas Pay PHP SDK 0.1.2).
* Pricing wording: card 1% plus your card account fees, mobile money 2% (operator fees are paid by the customer).

= 0.1.0 =
* First version: mobile money and card, signed notifications, refunds, block checkout.
