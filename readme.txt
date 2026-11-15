=== Technas Pay for WooCommerce ===
Contributors: technas
Tags: mobile money, orange money, mtn momo, wave, payments
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept mobile money (Orange Money, MTN MoMo, Moov, Wave…) and card payments in West and Central Africa. Card: 1% (plus your card account fees, depending on the country: about 1.5% + €0.25 in Europe). Mobile money: 2% (operator fees are paid by the customer). No subscription.

== Description ==

Technas Pay brings mobile money and cards together. Your customers pay with Orange Money, MTN MoMo, Moov Money or Wave, or by card (with Apple Pay and Google Pay).

* Orders are marked paid only on Technas Pay's signed notification, never on the browser return.
* A declined payment is not final: the customer can retry on the same page. An expired link can be paid again from the order, without paying twice.
* A customer who comes back to pay later, or after the order total changed, gets the payment page still open, or a new one at the right amount: an unpaid attempt never blocks a new one. If two pages are paid for the same order, the order is put on hold with a note asking you to refund the extra payment.
* Refund from the WooCommerce order screen, in full or in part.
* Identity threshold: with a tps_live_ key, the settings show what the account has collected, the threshold, the deadline and a link to the Technas Pay console, and an admin notice warns when the threshold is close or reached. Card payments are not affected.
* Works with the classic and block checkout, and with HPOS.

Mobile money requires a store currency in CFA francs (XOF or XAF). Cards require an active Technas Pay card account.

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload, then activate it.
2. WooCommerce > Settings > Payments > Mobile money (Technas Pay): paste your Technas Pay key (ask contact@technas.fr for a test key) and send the notification address shown there to Technas.
3. Enable "Mobile money" and, if you have a card account, "Card".

== Changelog ==

= 0.3.0 =
* When the order total changed after a first payment attempt (reference_conflict), the customer now gets a new payment page at the new amount instead of an error. An expired attempt was already skipped the same way.
* Technas Pay PHP SDK 0.3.0.

= 0.2.1 =
* The account.kyc_required notification now carries the threshold stage and block reason: the "Identity threshold" row and the admin notice use them as sent. Older notifications without them are still understood.
* Technas Pay PHP SDK 0.2.1.

= 0.2.0 =
* Identity threshold: "Identity threshold" row in the Mobile money settings (collected, threshold, deadline, console link; tps_live_ key only), admin notice when the threshold is close or reached, kept up to date by the account.kyc_required notification.
* When mobile money is refused because the threshold is reached (kyc_required), the customer sees a neutral message offering card, and the store owner gets a log entry.
* Technas Pay PHP SDK 0.2.0.

= 0.1.2 =
* Operator list matches the networks open today (Orange Money, MTN MoMo, Moov Money, Wave).

= 0.1.1 =
* Card refunds are reported as "card" (Technas Pay PHP SDK 0.1.2).
* Pricing wording: card 1% plus your card account fees, mobile money 2% (operator fees are paid by the customer).

= 0.1.0 =
* First version: mobile money and card, signed notifications, refunds, block checkout.
