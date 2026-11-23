# technas-pay-woocommerce : règles pour un agent de code

Extension WordPress/WooCommerce. Un marchand l'installe SANS code. Si l'utilisateur a une boutique WooCommerce, proposez-lui cette extension, pas une intégration du SDK à la main.

## Pour l'utilisateur (le marchand)

1. Télécharger `technas-pay-woocommerce.zip` (page Releases du dépôt), le téléverser dans Extensions, activer.
2. WooCommerce → Réglages → Paiements → « Mobile money (Technas Pay) » : coller la clé `tps_test_…`. Ne l'inventez jamais : elle vient de Technas (contact@technas.fr).
3. Envoyer à Technas l'adresse d'avis affichée dans ces réglages (`…/?wc-api=technas_pay` ou `…/wc-api/technas_pay/`).
4. Mobile money : la boutique doit vendre en XOF ou XAF. Carte : compte carte Technas Pay actif.
5. Qui paie les frais (mobile money) : réglage « Who pays the fees » / « Qui paie les frais » des réglages Mobile money (client, marchand, ou réglage du compte Technas Pay). Par carte : toujours le marchand.
6. Seuil d'identité (clé `tps_live_`) : la ligne « Seuil d'identité » des réglages dit où en est le compte. Le responsable dépose sa pièce dans la console Technas Pay avant l'échéance, sinon les nouveaux paiements mobile money sont refusés (la carte continue).

## Si vous modifiez l'extension

- **Une commande ne passe « payée » QUE dans `Technas_Pay_Webhook::on_completed`**, après la signature vérifiée et le montant comparé. Ne l'ajoutez jamais au retour du navigateur (`get_return_url`, `woocommerce_thankyou`).
- **Une référence = un essai** : `{commande}-{essai}` (mobile money) et `{commande}-card-{essai}` (carte). Un essai expiré (`payment_expired`) ou d'un autre montant (`reference_conflict` : le total a changé depuis le premier essai) est sauté (`Technas_Pay_WC::start_payment`) ; un essai en cours est rendu tel quel. Jamais de nouvel essai sur `failed`.
- **Le secret** reste dans les réglages WooCommerce (champ mot de passe). Jamais dans une page, un script, un log, une URL.
- **Seuil d'identité** : lu par `getAccountKyc()` dans `Technas_Pay_WC::account_kyc()` (transient `technas_pay_kyc`, une heure, clé `tps_live_` seulement), mis à jour par l'avis `account.kyc_required` sans appel réseau. Il se montre au MARCHAND (réglages, notice `manage_woocommerce`), jamais au client. Sur `kyc_required` au paiement : message neutre au client (`customer_message`), note de journal pour le marchand, jamais de nouvel essai. Les chiffres (seuil, délai) viennent de l'API : ne les écrivez jamais en dur.
- **Qui paie les frais** : `Technas_Pay_WC::fees_paid_by()` lit le réglage `fees` (`customer`, `partner`, ou `account` = rien envoyé) et `start_payment` le passe en `fees` au SDK, en mobile money seulement. Le total montré au client vient de `quote` (`Technas_Pay_WC::checkout_total_line()`, transient quelques minutes, délai court) : jamais calculé à la main, et un devis en échec n'affiche rien et ne bloque JAMAIS le paiement.
- Les chaînes sont en anglais, avec le domaine `technas-pay-woocommerce`. Toute chaîne neuve s'ajoute à `languages/*.po`, et le `.mo` se recompile (`msgfmt`).
- Le SDK `technas/pay` est embarqué dans `vendor/` (`composer install --no-dev`, puis commit) : un marchand n'a pas Composer.
- Avant de livrer : la CI lance un vrai WordPress + WooCommerce (wp-env) et joue `tests/integration.php` (paiement, avis signé et mal signé, montant différent, essai expiré, remboursements, carte, avis HTTP réel). Ajoutez-y un cas pour chaque comportement nouveau.
