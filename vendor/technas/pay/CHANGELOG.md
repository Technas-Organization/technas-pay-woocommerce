# Changelog

## 0.4.0 — 2026-11-16

- `cancelPayment($id)` (`POST /v1/payments/{id}/cancel`) : annule définitivement un paiement que personne n'a commencé à payer (le client change d'offre). Rend le paiement `expired`, sans lien ; rejouer rend le même résultat. Refus : `payment_already_completed` (non rejouable) et `payment_in_progress` (rejouable, comme avant). Un identifiant vide est refusé sans appel.
- `Payment` : `canceledAt`, `cancelReason` et `reason` (avis `payment.expired` : `canceled` ou `timeout`), tous facultatifs.
- Routes toutes faites : `POST /payments/{id}/cancel` (mêmes contrôles que `GET /payments/{id}`) ; sur `reference_conflict`, l'ancien essai est relu par sa référence et annulé s'il est encore ouvert avant l'essai suivant. Payé, ou annulation refusée (`payment_in_progress`, `payment_already_completed`) : aucun second paiement, l'erreur remonte à l'app. Une annulation qui répond 404 (serveur qui ne l'a pas encore) laisse passer à l'essai suivant, comme avant.
- Contrat v1 à jour (31 cas, `query_includes` vérifié).
- Documentation : « Le client change d'offre ou revient plus tard » annule l'ancien essai avant d'en créer un nouveau.

## 0.3.0 — 2026-11-15

- Routes toutes faites : un essai d'un autre montant (`reference_conflict`, le client a changé d'offre) fait passer à l'essai suivant, comme un essai expiré, dans la même limite `maxAttempts`. Avant, le client recevait un refus alors que rien n'était payé.
- Contrat v1 à jour (24 cas) : « démarrer : le client a changé d'offre (autre montant) → essai suivant, jamais un refus ».
- Documentation : « Le client change d'offre ou revient plus tard » dans AGENTS.md, README et llms.txt, avec un exemple (relire le dernier essai, nouvel essai `n + 1`, rembourser un essai payé en trop).

## 0.2.1 — 2026-11-15

- Avis `account.kyc_required` : `KycRequired` lit aussi `stage`, `blockReason`, `daysElapsed`, `graceDays` et `firstCollectedAt` (champs de `GET /v1/account/kyc`), tous facultatifs : `null` sur un avis qui ne les porte pas. `reason` reste le déclencheur de l'avis.

## 0.2.0 — 2026-11-15

- Seuil d'identité : `getAccountKyc()` (`GET /v1/account/kyc`) rend un `AccountKyc` typé (`stage`, `level`, `collectedXof`, `thresholdXof`, `graceDays`, `deadline`, `consoleUrl`…). Les valeurs inconnues de `stage`, `level` et `reason` sont gardées telles quelles.
- Avis `account.kyc_required` : fonction `onKycRequired`, contenu typé dans `$event->kycRequired` (`KycRequired`).
- Erreurs `kyc_required` et `contract_required` (409, non rejouables) : `details` porte `consoleUrl`, et `version` pour le contrat.
- README et AGENTS.md : section « Seuil d'identité » et conduite à tenir sur ces erreurs.

## 0.1.2 — 2026-11-15

- Remboursement par carte : `Refund::$kind` vaut désormais `card` (valeurs : `payout`, `external`, `card`). Un ancien `stripe` reçu de l'API est encore accepté et lu comme `card`, le temps de la transition.
- Documentation : tarif écrit en une phrase (carte 1 % plus les frais de votre compte carte, mobile money 2 % dont les frais de l'opérateur sont payés par le client), prestataires non nommés hors de l'inscription du compte carte.

## 0.1.0 — 2026-11-15

Première version, même surface que `@technas/pay` 0.11.0 (Node), Python 0.4 et Dart 0.4 : paiements (mobile money et carte), statut, liste, solde, remboursements, recharges, numéros de reversement, change hors zone CFA, règles de pays, compte carte, avis signés, outils de test. Routes toutes faites pour l'app (`appRoutes`) avec `SymfonyBridge` (Laravel, Symfony) et `Psr15Handler`. Contrat v1 (23 cas) rejoué. Aucune dépendance hors `ext-curl`.
