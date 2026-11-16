# Technas Pay pour WooCommerce

Encaissez le **mobile money** (Orange Money, MTN MoMo, Moov Money, Wave…) et la **carte bancaire** (avec Apple Pay et Google Pay) sur votre boutique WooCommerce. Sans développeur.

**Technas Pay réunit le mobile money et la carte dans un seul outil.** Carte : 1 % (plus les frais de votre compte carte, selon le pays : environ 1,5 % + 0,25 € en Europe). Mobile money : 2 % (les frais de l'opérateur sont payés par le client). Sans abonnement, et une clé de test sans papier pour commencer. Devises et pays, pièces demandées, versements : [le hub Technas Pay](https://github.com/Technas-Organization/technas-pay).

## Installer (5 minutes)

1. **Téléchargez l'extension** : page [Releases](https://github.com/Technas-Organization/technas-pay-woocommerce/releases), fichier `technas-pay-woocommerce.zip`.
2. Dans WordPress : **Extensions → Ajouter → Téléverser une extension**, choisissez le zip, puis **Activer**.
3. **WooCommerce → Réglages → Paiements → Mobile money (Technas Pay)** :
   - collez votre **clé Technas Pay**. Demandez une clé de test à contact@technas.fr : elle commence par `tps_test_` et ne déplace aucun argent ;
   - **copiez l'adresse d'avis** affichée juste en dessous et envoyez-la à votre contact Technas. C'est par elle que vos commandes passent à « payée » ;
   - cochez **Activer**.
4. Pour la carte : **Carte bancaire (Technas Pay)**, cochez **Activer**. Elle n'apparaît aux clients qu'une fois votre compte carte ouvert chez Technas Pay.

C'est tout. Faites une commande de test : la page de paiement vous propose « réussi », « refusé » ou « expiration ». En réel, remplacez seulement la clé par `tps_live_…`.

## Ce que fait l'extension

- **La commande passe à « payée » uniquement sur l'avis signé de Technas.** Jamais au retour du navigateur : un client qui ferme l'onglet ou rafraîchit ne peut pas faire passer une commande non payée.
- **Un refus n'annule rien.** Le client peut réessayer sur la même page pendant une heure. Si le lien expire, il repaie depuis sa commande, sans risque de payer deux fois (une référence = un essai).
- **Le client revient payer plus tard, ou après avoir modifié sa commande** : depuis sa commande, « Payer » le ramène sur la page déjà ouverte si elle est encore valable. Si le lien a expiré, ou si le total a changé depuis, une nouvelle page est créée au bon montant : il n'est jamais bloqué par un essai qui n'a pas été payé.
- **Un seul paiement ouvert par commande** : quand le client passe du mobile money à la carte (ou l'inverse), ou que le total a changé, l'ancien lien de paiement est fermé avant que le nouveau soit créé. Une commande ne peut donc pas être payée deux fois. Si l'ancien paiement est déjà en cours chez l'opérateur, ou déjà payé, aucun nouveau paiement n'est créé et le client en est averti.
- **Payé deux fois ?** Si le client règle deux pages pour une commande déjà payée (l'ancienne après la nouvelle, ou la carte puis le mobile money), ce second paiement n'est jamais ignoré : la commande passe « en attente » avec une note qui demande de le rembourser. Remboursez-le depuis la commande, comme ci-dessous.
- **Rembourser depuis WooCommerce** : dans la commande, **Rembourser → Rembourser via Technas Pay**, en tout ou en partie. L'argent repart au numéro ou à la carte qui a payé.
- **Montant vérifié** : si le montant payé ne correspond pas à la commande, elle est mise « en attente » avec une note, au lieu de passer payée.
- **Seuil d'identité** : avec une clé `tps_live_`, les réglages Mobile money montrent ce que le compte a encaissé, le seuil, l'échéance et le lien vers la console Technas Pay, et une notice prévient l'administrateur quand le seuil approche ou est atteint. Au-delà du seuil (1 000 000 F CFA encaissés en cumul, ou 30 jours après le premier paiement réel, chiffres lus chez Technas), un compte sans pièce d'identité vérifiée voit ses nouveaux paiements mobile money refusés ; la carte n'est pas concernée, rien de ce qui existe n'est bloqué. Le client voit alors un message neutre qui lui propose la carte.
- Fonctionne avec la **page de paiement classique et la page en blocs** (WooCommerce 8.3+), et le stockage des commandes HPOS.

## Conditions

- WordPress 6.3+, WooCommerce 8.0+, PHP 8.1+.
- **Mobile money** : la boutique vend en **francs CFA** (XOF ou XAF). Le client choisit son pays et son opérateur sur la page Technas Pay.
- **Carte** : toute devise, compte carte Technas Pay actif.

## Questions fréquentes

**Où va l'argent ?** Mobile money : sur votre solde Technas Pay, reversé sur votre numéro mobile money. Carte : directement sur votre compte carte, commission déjà retirée.

**Le client ne voit pas le mobile money.** La boutique doit vendre en XOF ou XAF (WooCommerce → Réglages → Général → Devise), et la clé doit être enregistrée.

**Une commande reste « en attente de paiement ».** L'avis n'est pas arrivé : vérifiez que Technas a bien votre adresse d'avis (réglages Mobile money). Une commande non payée dont le lien a expiré reste en attente : c'est normal.

## Pour les développeurs

Elle s'appuie sur le SDK PHP [`technas/pay`](https://github.com/Technas-Organization/technas-pay-php), embarqué dans `vendor/`. Pour un assistant de code : [AGENTS.md](AGENTS.md). Les tests tournent dans un vrai WordPress + WooCommerce (wp-env) à chaque modification : [tests/integration.php](tests/integration.php).

## Licence

MIT.
