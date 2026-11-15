# Sécurité

## Signaler une faille

Écrivez à **contact@technas.fr**, objet « Sécurité : technas-pay-woocommerce ». N'ouvrez pas d'issue publique.
Nous accusons réception sous 3 jours ouvrés et vous tenons informé jusqu'au correctif.

Testez en mode test (`tps_test_`) sur votre propre boutique, jamais sur celle d'un autre marchand.

## Ce que l'extension garantit, et comment c'est vérifié

| Garantie | Où | Preuve |
|---|---|---|
| Une commande ne passe « payée » QUE sur un avis signé (HMAC, temps constant, 300 s), au montant de la commande | `includes/class-technas-pay-webhook.php` | `tests/integration.php` (vrai WordPress + WooCommerce) |
| Avis falsifié ou mal signé : 400, rien ne change ; avis rejoué (même id) : acquitté sans être retraité ; vieille signature : refusée | idem | idem, et curl HTTP réel en CI |
| Montant et commande relus côté serveur (commande WooCommerce), jamais pris de la requête | `includes/class-technas-pay-wc.php` | idem (« montant différent : en attente ») |
| Route d'avis REST ouverte, protégée par la signature (aucune action sans signature valide) | `register_route`, `permission_callback` documenté | idem |
| La clé est dans sa propre option, sans autoload, jamais réaffichée (champ vide, seulement mode + 4 derniers caractères) | `Technas_Pay_WC::save_secret`, `generate_technas_secret_html` | idem (« la page de réglages ne réaffiche jamais la clé ») |
| Remboursement : jeton et droit vérifiés par WooCommerce, et `current_user_can('edit_shop_orders')` revérifié | `process_refund` | idem |
| Sorties échappées (`esc_html`, `esc_attr`, `esc_url`), entrées assainies, aucune requête SQL écrite à la main | tout `includes/` | PHPCS WordPress-Extra + WordPress-VIP-Go en CI |
| Pas d'accès direct aux fichiers (`defined('ABSPATH') || exit`) | chaque fichier PHP | revue |
| Journal d'erreur sans message brut (ni montant, ni clé) | `handle_request`, `handle_rest` | revue |

Les réglages passent par l'API de réglages de WooCommerce (jeton et droit `manage_woocommerce`). En CI : PHPCS (WordPress-Extra, WordPress-VIP-Go), `composer audit`, TruffleHog, et le parcours complet dans un vrai WordPress. Dependabot surveille le SDK embarqué et les actions.
