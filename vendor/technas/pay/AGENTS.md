# technas/pay (PHP) : règles pour un agent de code

Consignes pour un assistant de code (Codex, Claude Code…). Appliquez-les dans l'application de l'utilisateur, pas dans ce dépôt. Le README détaille chaque fonction ; des exemples sont dans [`examples/`](examples) (Laravel, Symfony, PHP sans framework).

Même API, mêmes règles que les SDK Node (`@technas/pay`), Python et Dart : mêmes noms de méthodes, en PHP (`$pay->askForPayment([...])`, propriétés `$payment->checkoutUrl`).

## Avant de commencer

1. PHP 8.1 ou plus récent, extension `curl`. Côté SERVEUR uniquement : jamais dans une page envoyée au navigateur ni dans une app mobile.
2. `TECHNAS_PAY_SECRET` doit exister dans l'environnement du serveur (`.env`, ignoré par git) et commencer par `tps_test_`. S'il manque, demandez-le à l'utilisateur. **N'en inventez pas, ne l'affichez pas, ne le loggez pas, ne le commitez pas.**
3. Repérez le modèle de commande : un identifiant, un montant calculé côté serveur, un état « payée », et une colonne pour garder `$payment->id`.
4. Boutique WordPress/WooCommerce : utilisez plutôt l'extension [`technas-pay-woocommerce`](https://github.com/Technas-Organization/technas-pay-woocommerce), sans code.

## Installer

```bash
composer config repositories.technas-pay vcs https://github.com/Technas-Organization/technas-pay-php
composer require technas/pay:^0.1
```

## Quelle méthode pour quoi

| Besoin | Appel |
|---|---|
| Faire payer une commande | `$pay->askForPayment(['amount' => 5000, 'reference' => Status::retryReference($orderId, $attempt), 'customer' => ['email' => …, 'name' => …], 'returnUrl' => …])`, puis rediriger (303) vers `$payment->checkoutUrl` |
| Savoir qu'elle est payée | avis signé : `$pay->handleWebhook($corpsBrut, $signature, new WebhookHandlers(onCompleted: …))` |
| Rattraper un avis manqué | `$pay->getPaymentByReference($reference)` |
| Savoir si on peut relancer | `onExpired`, ou `Status::canRetry($pay->getPayment($id)->status)` |
| Rembourser | `$pay->refund($paymentId, amount: ?, fees: ?, reference: ?)` |
| Noter un remboursement fait hors Technas | `$pay->recordExternalRefund($paymentId, 2000, 'Flooz')` |
| Recharger le solde | `$pay->topUpBalance(20000, 'XOF')`, ouvrir `checkoutUrl` ; `onTopUp` |
| Solde, reversements, historique | `$pay->getBalance()`, `$pay->getSettlements()`, `$pay->listTransactions(limit: 20)` |
| Hors zone CFA | `$pay->getFxMode()`, `$pay->setFxMode('local', acceptRisk: true)` (après accord explicite) |
| Pays où vendre | `$pay->setCountryRules(excluded: ['NG'])`, ou `allowedCountries` / `excludedCountries` par paiement |
| Numéro de reversement (administrateur) | `$pay->getPayoutNetworks('TG')`, `$pay->setPayoutDestination('TG', 'moov_tg', '+228…', 'Nom')` |
| Payer par carte | `$pay->askForPayment(['method' => 'card', 'amount' => 12.5, 'currency' => 'EUR', …])` après `$pay->getCardAccount()->canAcceptPayments` |
| Ouvrir le compte carte | `$pay->startCardOnboarding($returnUrl, $refreshUrl, 'TG')`, rediriger le RESPONSABLE vers `url` |
| Tableau de bord carte | `$pay->getCardDashboardLink()` → https://dashboard.stripe.com/ |
| Tester la route d'avis | `$pay->createTestWebhook('payment.completed', '42-1', 5000, 'XOF')` |
| Jouer le payeur en test | `$pay->simulateTestPayment($id, 'completed' \| 'failed' \| 'expired')` |

## Étapes

1. `$pay = new TechnasPay();` (lit `TECHNAS_PAY_SECRET` au premier appel).
2. Action « payer » : relire la commande en base, `askForPayment`, garder `$payment->id`, rediriger vers `checkoutUrl`.
3. Route d'avis `POST` : corps BRUT (`file_get_contents('php://input')`, `$request->getContent()`), puis `handleWebhook` ; répondre `$result['status']` avec `$result['body']` en JSON. Laravel : exclure la route du CSRF (`VerifyCsrfToken::$except`). Modèles : `examples/`.
4. Test : poster `$pay->createTestWebhook(...)` sur la route, puis le lancer.

## App mobile (Flutter)

Ne réécrivez pas de routes : `$pay->appRoutes(authorize: …, resolveOrder: …, returnUrl: …, webhooks: …, card: ?, authorizeAdmin: ?)`, montées sous `/api/technas-pay` par `SymfonyBridge::handle($routes, $request, $path)` (Laravel, Symfony) ou `new Psr15Handler($routes, $psr17, $psr17)`. `resolveOrder($orderId, $user)` relit la commande EN BASE et rend `null` si elle n'est pas à cet utilisateur. N'y mettez jamais un montant reçu de l'app. Côté app : `TechnasPayClient(baseUrl: '…/api/technas-pay')`.

## Obligatoire

- **`amount`** : en unité principale (`5000` = 5 000 FCFA, `12.5` = 12,50 €, jamais × 100), calculé côté serveur. Entier pour le mobile money ; décimales seulement par carte.
- **`reference`** : l'identifiant unique de la commande.
- **`customer.email`** et **`customer.name`** : de vrais champs du client, jamais inventés.
- **`returnUrl`** : la page de retour, en https en production.
- Le reste est facultatif. Passez `customer.country` et `customer.phone` si l'application les connaît : ils évitent au client de choisir son pays.

## La devise est automatique

- **Ne passez pas `currency`** si l'application vend en francs CFA.
  - La devise suit le pays du client : TG → XOF, CM → XAF, pour le même montant.
  - Sans pays, le client choisit le sien sur la page de paiement.
- **Ne fixez pas `defaultCurrency`** : il empêcherait ce choix.
- **Hors zone, les montants peuvent avoir des centimes** (`121.96` GHS) : stockez-les en décimal, ne les arrondissez pas.
- Dans le webhook, `$event->payment->originalAmount` est le montant demandé en F CFA ; `amount` et `currency` sont ce qui a été payé.

## Où vendre (tout est facultatif)

- **Le client choisit son pays** : sans `currency` ni `customer.country`, la page le lui demande.
- **Pays fixé** : `customer.country`.
- **Pour tout le compte** : `$pay->setCountryRules(['allowed' => …, 'excluded' => …])`. Codes ISO à deux lettres.
- **Pour un seul paiement** : `allowedCountries` / `excludedCountries` dans `askForPayment`.
- **Devise imposée** : `currency: "XAF"` ; ajoutez `askCountry: true` pour que le client choisisse parmi les pays de cette devise.
- **Pays refusé** : `country_not_allowed`. Ne contournez pas ce refus : c'est le choix du marchand.

## Clients hors zone franc CFA : une option à risque, décidée par l'utilisateur

- **Montant** : il reste en F CFA, ne passez pas `currency`.
- **C'est une option** : `$pay->getFxMode()` dit laquelle est active, quels pays sont ouverts, et ce qui manque à chacun (`reason`).
- **Technas ne couvre aucun risque de change.** Avant d'activer `local` ou `converted` :
  1. lisez `$pay->getFxMode().risks[mode]` (et `risks.markup` si un supplément est prévu) ;
  2. **montrez ce texte à l'utilisateur, en entier, et expliquez-le simplement** : ce qu'il peut perdre, dans quelle monnaie il sera payé, quel numéro de reversement il lui faut ;
  3. attendez son accord explicite, en toutes lettres ;
  4. seulement ensuite, appelez `$pay->setFxMode(['mode' => …, 'acceptRisk' => true, 'markupPct' => …])`.
- **N'activez jamais une option de vous-même**, et ne passez jamais `acceptRisk: true` sans cet accord.
- **`local`** : le solde est tenu et reversé dans la monnaie du client. Il faut un numéro de reversement **confirmé** dans cette monnaie (`setPayoutDestination`, réservé à un administrateur), sinon le pays reste fermé (`no_payout_number`).
- **`converted`** : PAS ENCORE OUVERT. `setFxMode(['mode' => "converted"])` lève `fx_mode_unavailable` : ne le proposez pas. Seules les options de `$pay->getFxMode().availableModes` existent (aujourd'hui `none` et `local`).
- **`markupPct`** (0 à 20) : supplément payé par le client, affiché, qui revient au marchand.
- **Ne promettez jamais** un pays que `getFxMode()` ne donne pas `available: true`.
- **Webhooks** :
  - branchez `onFxModeUpdated` : un changement d'option que l'utilisateur n'a pas fait signale une clé compromise ;
  - branchez `onBalanceConverted` en mode `converted`.

## Sécurité

- **Le secret reste côté serveur.**
  - Jamais dans un template rendu au navigateur, une variable JavaScript, une app mobile ni un `phpinfo()`.
  - `TechnasPay` masque le secret dans `var_dump`/`print_r` ; ne l'écrivez pas vous-même dans un log.
  - Jamais de secret dans une page, une réponse JSON, une URL, un log ou un commit.
- **Vérifiez la signature de chaque webhook avec le bon secret.**
  - `handleWebhook` (ou `verifyWebhook`) le fait, avec le secret passé à `new TechnasPay()`.
  - Un secret `tps_test_` ne vérifie que les webhooks de test, un secret `tps_live_` que ceux du réel.
  - Le serveur qui reçoit les webhooks réels doit avoir le secret `tps_live_`.
  - Ne contournez jamais un refus de signature (réponse 400).
- **Conservez le corps brut** : `file_get_contents('php://input')` ou `$request->getContent()`, jamais `json_decode` puis `json_encode` avant la vérification. Laravel : `$request->all()` ne convient pas.
- **Seul le webhook prouve le paiement.** Ne marquez jamais une commande payée depuis la page `returnUrl`.
- **Réservez à un administrateur** les remboursements et le numéro de reversement. Ne les exposez jamais sur une page publique.

## La règle de relance (à respecter strictement)

**Une référence = un essai. Relancez avec une NOUVELLE référence seulement après `payment.expired` (ou `getPayment` → `expired`), jamais sur `failed`.**

- Ne prenez pas l'identifiant de commande tel quel comme `reference` : `Status::retryReference($orderId, $tentative)` compose `"$orderId-$tentative"` ; gardez la tentative en base, pour pouvoir en créer une nouvelle.
- Rejouer la référence d'un paiement expiré lève `payment_expired` (409) : ne réessayez pas le même appel, créez une nouvelle référence.
- Après expiration, `checkoutUrl` vaut `null` : n'envoyez jamais l'ancien lien au client.

- `failed` n'est **pas** définitif : le client peut encore payer sur la même page pendant une heure, et `onCompleted` arriver ensuite. Dans `onFailed`, ne marquez pas la commande annulée, n'envoyez pas de nouveau lien.
- `expired` est définitif : le lien est fermé, Technas a vérifié chez l'opérateur que rien n'a été payé ni n'est en cours. `Status::canRetry($payment->status)` vaut `true` seulement là.
- La même référence rend le même paiement expiré : générez-en une nouvelle (ex. `"$orderId-2"`) et gardez le nouveau `$payment->id`.
- Expliquez cette règle à l'utilisateur : un client relancé sur `failed` pourrait payer deux fois.

## Webhooks

- **Ils peuvent arriver plusieurs fois** : vos fonctions doivent le supporter. Vérifiez l'état en base, ou gardez `$event->id`.
- **Ne rattrapez pas leurs erreurs pour répondre 200.** Une erreur doit remonter pour que le webhook soit relivré.
- **`onFailed` n'est pas définitif** : `onCompleted` peut arriver après.
- **`onExpired` est définitif** : seul moment où relancer le client (nouvelle référence).
- **`onRefund`** : prévenez le client, par e-mail ou SMS. Technas ne lui écrit pas.
  - `refund.completed` : l'argent est arrivé, `sentAmount` sur son téléphone.
  - `refund.failed` : rien n'est parti et votre solde est recrédité. Proposez un autre moyen, ou refaites le même appel `refund` : il est permis après un échec.

## Paiement par carte (`method: 'card'`)

Ce n'est PAS le même parcours que le mobile money. Ne le proposez que si l'utilisateur le demande.

1. **Un compte carte d'abord, une seule fois.** Ajoutez dans les réglages de l'application un bouton « Activer le paiement par carte », réservé au responsable du compte. Au clic, côté serveur :
   ```php
   $link = $pay->startCardOnboarding("$site/reglages/carte?fin=1", "$site/reglages/carte?relance=1", country: 'FR');
   return redirect($link->url); // Laravel ; Symfony : new RedirectResponse($link->url)
   ```
   - `country` est le pays de l'UTILISATEUR (pas de ses clients). Demandez-le-lui, ne le devinez pas.
   - Le lien expire en quelques minutes : générez-le au clic, ne l'envoyez jamais par e-mail.
   - Sur `refreshUrl`, rappelez `startCardOnboarding` et redirigez à nouveau.
   - Sur `returnUrl`, affichez `$pay->getCardAccount()` : `status` dit où il en est (`onboarding`, `under_review`, `restricted`, `enabled`) et `requirements` ce qui manque.
   - Le responsable y donne son identité et sa banque au prestataire carte. Ni vous ni Technas ne voyez son IBAN.
2. **Proposer la carte seulement si `canAcceptPayments` est vrai.** Gardez l'état en base via `onCardAccountUpdated`, ou relisez `getCardAccount()`. Sinon, ne montrez que le mobile money.
3. **Faire payer :** `$pay->askForPayment(['method' => 'card', 'amount' => 49.9, 'currency' => "EUR", 'reference' => …, 'customer' => …, 'returnUrl' => …])`, puis redirection vers `$payment->checkoutUrl`. `currency` est obligatoire par carte. Le reste est identique : même webhook `onCompleted`, même règle « une référence = un essai », même `payment.expired` au bout d'une heure.
4. **L'argent va directement sur le compte carte de l'utilisateur**, commission Technas déjà retirée. Il n'apparaît PAS dans `getBalance()` et ne passe pas par les reversements. Les virements vers sa banque se règlent dans son tableau de bord carte (`getCardDashboardLink()`).
5. **Rembourser :** le même `$pay->refund($paymentId, amount: ?)`. La carte est remboursée depuis son compte carte (`$refund->kind === 'stripe'`), sans toucher au solde Technas ; `fees` est sans objet. La commission n'est pas rendue.
6. **Erreur `card_account_not_ready` (409)** : le compte n'encaisse pas encore. `$e->details['accountStatus']` dit pourquoi. Ne réessayez pas : proposez le mobile money, ou renvoyez le responsable vers `startCardOnboarding`.
7. **En test** (`tps_test_`), la carte de test `4242 4242 4242 4242`, une date future et n'importe quel code valident le paiement ; l'inscription de test accepte des données fictives.

## Rembourser

- **Appel** : `$pay->refund($paymentId)` rembourse tout ce qui reste ; `$pay->refund($paymentId, amount: 2000)` rembourse une partie.
- **Destination** : l'argent repart au numéro qui a payé, jamais à un autre.
- **Plafond** : le total remboursé ne dépasse jamais le montant payé.
- **Commission** : la commission Technas n'est pas rendue.
- **Frais du renvoi**, choisis à chaque appel avec `fees` :
  - `'partner'` (défaut) : le client reçoit tout, les frais sont pris en plus sur votre solde ;
  - `'customer'` : les frais sont retirés de ce qu'il reçoit (`sentAmount`).
- **Idempotence** : rejouer le même appel ne rembourse pas deux fois. Pour deux remboursements partiels identiques et voulus, donnez à chacun sa `reference`, par exemple l'identifiant de la décision.
- **Statut** : la réponse peut être `pending`. Le résultat arrive dans `onRefund`, ou par `$pay->getRefund($id)`.
- **Solde insuffisant** : l'erreur `insufficient_balance` donne `$e->details['missingAmount']`. Rechargez avec `$pay->topUpBalance`, puis rappelez `refund`.
- **Remboursement fait hors Technas** (Flooz, T-Money, espèces) : `$pay->recordExternalRefund($paymentId, $amount, $method)`.
  - Aucun argent ne bouge, le solde ne change pas.
  - Il compte dans le même plafond que `refund`.
  - Même idempotence, webhook `refund.recorded`.
  - N'appelez jamais `refund` pour un remboursement déjà fait à la main : le client serait remboursé deux fois.
- **Total remboursé** : `$payment->refundedAmount` additionne les deux sortes.

## Erreurs

- `card_account_not_ready` (409) : paiement par carte demandé sans compte carte actif. Voir « Paiement par carte ».
- `card_payments_unavailable` (503) : la carte n'est pas encore ouverte chez Technas. Proposez le mobile money.
- `currency_required` : `method: 'card'` sans `currency`.

`$e->errorCode`, avec `$e->retryable()` (vrai : refaites **le même appel**, mêmes valeurs) et `$e->details`.

| `$e->errorCode` | Que faire |
|---|---|
| `invalid_secret` | Variable absente ou mal copiée : demandez à l'utilisateur de la vérifier |
| `invalid_api_key` | Secret refusé : contacter Technas |
| `invalid_request` | Corriger le champ indiqué dans `$e->errors` |
| `invalid_amount`, `amount_too_low` | Montant entier positif, assez grand pour couvrir les frais |
| `currency_not_enabled` | Devise non ouverte sur ce compte : contacter Technas |
| `currency_conversion_unavailable` | Client hors zone CFA, mode de change `none` : ne pas proposer ce pays ; voir `getFxMode()` |
| `country_not_allowed` | Le marchand ne vend pas dans ce pays : ne pas le proposer |
| `country_not_available` | Pays pas prêt : `$e->details['reason']` dit quoi (`no_payout_number`…) |
| `risk_not_accepted` | Montrer `$e->details['risk']` à l'utilisateur ; n'activer qu'avec son accord explicite |
| `fx_mode_unavailable`, `fx_channel_unavailable` | Mode `converted` pas ouvert : proposer `local` (avec l'accord de l'utilisateur sur le risque) ou rester en `none` |
| `quote_expired` | Le client rechoisit son pays sur la page, rien à faire côté code |
| `reference_conflict` | Même référence, autres valeurs : ne pas réutiliser la référence |
| `payment_in_progress` | Réessayer dans une minute, même appel |
| `payment_not_found`, `refund_not_found` | Mauvais identifiant ou autre compte |
| `payment_expired` | Cette référence est un essai expiré : nouveau paiement avec une NOUVELLE référence, jamais le même appel |
| `payment_not_refundable` | Seul un paiement `completed` se rembourse |
| `refund_exceeds_payment` | Rembourser au plus `$e->details['refundableAmount']` |
| `refund_conflict` | Cette `reference` de remboursement a déjà servi avec d'autres valeurs |
| `insufficient_balance` | Recharger au moins `$e->details['missingAmount']` avec `topUpBalance`, puis rappeler |
| `payer_unknown`, `refund_network_unavailable` | Remboursement impossible par Technas : rembourser le client autrement, prévenir Technas |
| `provider_unavailable`, `unavailable`, `network_error` | Panne passagère : refaire le même appel |
| `rate_limited` | Ralentir, réessayer |

## Vérifier l'intégration

Avec un secret `tps_test_`, aucun argent ne circule.

1. Lancez l'application et déclenchez un paiement : `checkoutUrl` ouvre une page de test (choix du pays, opérateurs, boutons « réussi » ou « refusé »).
2. Cliquez sur « Simuler un paiement réussi », puis contrôlez côté serveur avec `$pay->getPaymentByReference($reference)`.
   Testez aussi la relance : sur un autre paiement, « Simuler un refus » (rien ne doit être relancé), puis « Simuler l'expiration du lien » (`onExpired` doit relancer avec une nouvelle référence). Sans navigateur : `$pay->simulateTestPayment($id, 'failed')` puis `'expired'`. Un paiement de test non payé expire aussi tout seul au bout d'une heure.
3. Remboursez avec `$pay->refund($payment->id)` : en test, le remboursement est terminé aussitôt, avec des frais réalistes, et aucun argent ne part. Si le solde manque, `$pay->topUpBalance` ouvre une page de test pour le recharger.
4. Le webhook n'arrive sur votre poste que si Technas connaît une adresse publique. Sinon, testez la route avec `$pay->createTestWebhook`, et demandez à l'utilisateur de transmettre à son contact Technas l'adresse d'un tunnel.

## Passer en réel

Remplacez seulement le secret par `tps_live_…`. `returnUrl` doit alors être en https. Cette étape revient à l'utilisateur et à Technas : ne la faites pas de votre propre initiative.
