# Changelog

## 0.1.2 — 2026-11-15

- Remboursement par carte : `Refund::$kind` vaut désormais `card` (valeurs : `payout`, `external`, `card`). Un ancien `stripe` reçu de l'API est encore accepté et lu comme `card`, le temps de la transition.
- Documentation : tarif écrit en une phrase (carte 1 % plus les frais de votre compte carte, mobile money 2 % dont les frais de l'opérateur sont payés par le client), prestataires non nommés hors de l'inscription du compte carte.

## 0.1.0 — 2026-11-15

Première version, même surface que `@technas/pay` 0.11.0 (Node), Python 0.4 et Dart 0.4 : paiements (mobile money et carte), statut, liste, solde, remboursements, recharges, numéros de reversement, change hors zone CFA, règles de pays, compte carte, avis signés, outils de test. Routes toutes faites pour l'app (`appRoutes`) avec `SymfonyBridge` (Laravel, Symfony) et `Psr15Handler`. Contrat v1 (23 cas) rejoué. Aucune dépendance hors `ext-curl`.
