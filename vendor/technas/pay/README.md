# technas/pay (PHP)

Encaissez des paiements mobile money et par carte via Technas depuis un serveur PHP : Laravel, Symfony, Slim, ou PHP sans framework.

**Technas Pay réunit le mobile money et la carte dans un seul SDK, du serveur jusqu'à l'app Flutter.** Carte : 1 % (plus les frais de votre compte carte, selon le pays : environ 1,5 % + 0,25 € en Europe). Mobile money : 2 % (les frais de l'opérateur sont payés par le client). Sans abonnement, et une clé de test sans papier pour commencer.

Devises et pays, pièces demandées, versements, comparaison avec les autres prestataires : [le hub Technas Pay](https://github.com/Technas-Organization/technas-pay).

Vous intégrez avec un assistant de code ? Dites-lui de lire [AGENTS.md](AGENTS.md).

Une boutique WordPress / WooCommerce ? L'extension [`technas-pay-woocommerce`](https://github.com/Technas-Organization/technas-pay-woocommerce) s'installe sans développeur.

C'est la même API que les SDK [Node](https://github.com/Technas-Organization/technas-pay-node), [Python](https://github.com/Technas-Organization/technas-pay-python) et [Dart](https://github.com/Technas-Organization/technas-pay-dart).

## 1. Installer

PHP 8.1 ou plus récent, extension `curl`. Aucune autre dépendance.

```bash
composer config repositories.technas-pay vcs https://github.com/Technas-Organization/technas-pay-php
composer require technas/pay:^0.2
```

Technas vous remet **un secret**. Mettez-le dans les variables d'environnement du serveur :

```bash
TECHNAS_PAY_SECRET=tps_test_...
```

Un secret `tps_test_` ne déplace aucun argent. Un secret `tps_live_` encaisse pour de vrai. Le code est le même.

## 2. Demander un paiement

```php
use TechnasPay\Status;
use TechnasPay\TechnasPay;

$pay = new TechnasPay(); // lit TECHNAS_PAY_SECRET au premier appel

$payment = $pay->askForPayment([
    'amount' => 5000,                                               // entier : 5 000 FCFA
    'reference' => Status::retryReference($order->id, $order->attempt), // "42-1" : une référence = un essai
    'customer' => ['email' => 'client@exemple.com', 'name' => 'Awa Mensah'],
    'returnUrl' => 'https://votre-site.com/merci',
]);
$order->update(['payment_id' => $payment->id]);
header('Location: ' . $payment->checkoutUrl, true, 303);
```

Calculez le montant côté serveur. Ne passez pas `currency` si vous vendez en francs CFA : la devise suit le pays du client (`customer.country`), ou le client choisit son pays sur la page. Par carte : `'method' => 'card'`, une `currency` (EUR, XOF…), décimales permises.

## 3. Recevoir l'avis de paiement

Technas signe chaque avis (`X-Technas-Payment-Signature: t=…,s=…`). Le SDK vérifie la signature sur le **corps brut**, puis appelle votre fonction.

```php
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\WebhookHandlers;

$result = $pay->handleWebhook(
    file_get_contents('php://input'),                       // Laravel/Symfony : $request->getContent()
    $_SERVER['HTTP_X_TECHNAS_PAYMENT_SIGNATURE'] ?? null,
    new WebhookHandlers(
        onCompleted: fn (Event $e) => marquerPayee($e->payment->reference), // idempotent
        onExpired: fn (Event $e) => essaiSuivant($e->payment->reference),   // seul moment où relancer
    ),
);
http_response_code($result['status']); // 400 si la signature est refusée
echo json_encode($result['body']);
```

Fonctions disponibles : `onCompleted`, `onFailed` (pas définitif), `onExpired` (définitif), `onRefund`, `onTopUp`, `onPayoutDestination`, `onBalanceConverted`, `onFxModeUpdated`, `onCardAccountUpdated`, `onKycRequired` (seuil d'identité, voir plus bas). Une exception dans votre fonction doit remonter : Technas relivrera. Exemples : [examples/](examples).

## 4. Pour une app mobile : les routes toutes faites

Votre app Flutter ([`technas_pay_flutter`](https://github.com/Technas-Organization/technas-pay-dart/tree/main/technas_pay_flutter)) appelle ces routes sans glue. Vous ne fournissez que votre authentification et la lecture de la commande en base (Laravel ici) :

```php
Route::any('technas-pay/{path}', fn (Request $request, string $path) => SymfonyBridge::handle(
    (new TechnasPay())->appRoutes(
        authorize: fn () => auth('sanctum')->user(),                                  // null → 401
        resolveOrder: fn (string $id, $user) => ($o = $user->orders()->find($id))    // relue EN BASE, null → 404
            ? ['amount' => $o->total, 'customer' => ['email' => $user->email, 'name' => $user->name]] : null,
        returnUrl: 'https://votre-app.com/paiement/retour',
        webhooks: new WebhookHandlers(onCompleted: fn (Event $e) => Order::markPaid($e->payment->metadata['order_id'])),
    ),
    $request,
    $path,
))->where('path', '.*');
```

Symfony : `SymfonyBridge::handle($routes, $request, $path)` dans un contrôleur (`examples/symfony-controller.php`). Slim, Mezzio : `new Psr15Handler($routes, $psr17, $psr17, '/api/technas-pay')`.

Routes : `GET /methods?orderId=`, `POST /payments` `{orderId, method?}`, `GET /payments/{id}`, `POST /webhook` (adresse à déclarer à Technas). Les essais expirés sont sautés tout seuls (`42-1`, `42-2`…) : rien à stocker. Carte : `card: true`, et `authorizeAdmin` pour l'écran du marchand (`GET /card/account`, `POST /card/onboarding`). Contrat : [`contract/app-contract.v1.json`](contract/app-contract.v1.json), rejoué par ce paquet, les autres SDK et le client Flutter.

## 5. Tout le reste

| Méthode | Rôle |
|---|---|
| `getPayment($id)`, `getPaymentByReference($ref)` | Statut ; rattraper un avis manqué |
| `listTransactions(limit:, status:, cursor:)` | Historique paginé (`nextCursor`) |
| `refund($paymentId, amount: ?, fees: 'partner'\|'customer', reference: ?)` | Rembourser au numéro ou à la carte qui a payé |
| `recordExternalRefund($paymentId, $amount, $method)` | Noter un remboursement fait hors Technas |
| `getRefund($id)` | Statut d'un remboursement |
| `topUpBalance($amount, $currency)` | Recharger le solde (ouvrir `checkoutUrl`) |
| `getBalance()`, `getSettlements()` | Solde par devise, reversements |
| `getFxMode()`, `setFxMode($mode, acceptRisk:)` | Clients hors zone franc CFA (option à risque) |
| `setCountryRules(allowed:, excluded:)` | Où vous vendez |
| `getPayoutNetworks($country)`, `getPayoutDestination()`, `setPayoutDestination(...)` | Numéro de reversement |
| `startCardOnboarding(...)`, `getCardAccount()`, `getCardDashboardLink()` | Compte carte (puis le tableau de bord de votre compte carte) |
| `getAccountKyc()` | Seuil d'identité du compte (à montrer au marchand) |
| `verifyWebhook($raw, $sig)`, `handleWebhook(...)`, `createTestWebhook(...)` | Avis signés |
| `simulateTestPayment($id, 'completed'\|'failed'\|'expired')` | Jouer le payeur en test |

Aides : `Status::canRetry($status)` (vrai seulement pour `expired`), `Status::isFinal()`, `Status::retryReference()`, `Status::currencyForCountry('TG')`.

## Erreurs

```php
try {
    $pay->askForPayment([...]);
} catch (TechnasPayException $e) {
    if ($e->errorCode === 'payment_expired') { $order->increment('attempt'); } // nouvelle référence
    elseif ($e->retryable()) { /* refaire le même appel */ }
    else { throw $e; }
}
```

- `kyc_required` (409) : seuil d'identité atteint, nouveaux paiements mobile money refusés. `$e->details['consoleUrl']` et `$e->details['reason']` (`amount` ou `duration`). Proposez la carte ou dites au client que le mobile money n'est pas disponible pour le moment ; ne réessayez pas.
- `contract_required` (409) : un contrat est à accepter dans la console. `$e->details['consoleUrl']` et `$e->details['version']`. Ne réessayez pas.

Codes et conduite à tenir : [AGENTS.md](AGENTS.md#erreurs).

## Seuil d'identité

En réel, un compte sans pièce d'identité vérifiée voit ses **nouveaux** paiements mobile money refusés (`kyc_required`) au-delà de 1 000 000 F CFA encaissés en cumul, ou 30 jours après son premier paiement réel encaissé. Rien de ce qui existe n'est bloqué : paiement en cours, remboursement, reversement, lecture. La carte n'est pas concernée. Le responsable du compte dépose sa pièce dans la console (`consoleUrl`).

```php
$kyc = $pay->getAccountKyc(); // à montrer au marchand, jamais au client final
// $kyc->stage : ok | soon | blocked | verified | not_applicable
// $kyc->collectedXof, $kyc->thresholdXof, $kyc->graceDays, $kyc->deadline, $kyc->consoleUrl
```

Les chiffres viennent de l'API : ne les écrivez jamais en dur. L'avis `account.kyc_required` (`onKycRequired`, contenu dans `$e->kycRequired`) arrive à 80 % du seuil, 7 jours avant l'échéance, puis au premier refus : prévenez le responsable. Sur l'erreur `kyc_required`, proposez la carte ou dites au client que le paiement mobile money n'est pas disponible pour le moment ; ne réessayez pas.

## La règle de relance

**Une référence = un essai.** `failed` n'est pas définitif : le client peut encore payer sur la même page pendant une heure. Ne relancez qu'après `payment.expired`, avec une nouvelle référence.

## Développer

```bash
composer install
vendor/bin/phpunit
```

Les tests tournent contre un faux transport (aucun appel réseau) et rejouent le contrat partagé.

## Licence

MIT.
