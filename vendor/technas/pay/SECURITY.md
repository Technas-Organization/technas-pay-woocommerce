# Sécurité

## Signaler une faille

Écrivez à **contact@technas.fr**, objet « Sécurité : technas-pay-php ». N'ouvrez pas d'issue publique.
Nous accusons réception sous 3 jours ouvrés et vous tenons informé jusqu'au correctif.

Merci de ne pas tester sur les comptes d'autres partenaires ni en mode réel (`tps_live_`) : le mode test (`tps_test_`) suffit à tout reproduire.

## Ce que ce SDK garantit, et comment c'est vérifié

| Garantie | Où | Preuve |
|---|---|---|
| Signature des avis vérifiée sur le corps brut, comparaison en temps constant (`hash_equals`) | `src/Webhook/Signature.php` | `tests/SecurityTest.php` |
| Avis falsifié, rejoué, trop vieux (300 s) ou daté du futur : refusé, aucune fonction appelée | idem | `testAvisFalsifieRefuse`, `testAvisRejoueOuTropVieuxRefuse` |
| JSON lu strictement (`JSON_THROW_ON_ERROR`, profondeur bornée) ; une réponse illisible n'est jamais interprétée | `src/TechnasPay.php`, `src/Internal/Data.php` | `testReponseIllisibleNonInterpretee`, `testCorpsJsonPiegeRefuse` |
| Chaque donnée reçue lue par type, jamais convertie en silence | `src/Internal/Data.php` | PHPStan niveau max |
| TLS toujours vérifié (certificat et nom d'hôte), redirections en https seulement, délais posés | `src/Http/CurlTransport.php` | `testTlsToujoursVerifie` |
| La clé ne part que dans l'en-tête `Authorization`, jamais vers une adresse en clair | `src/TechnasPay.php` | `testCleJamaisVersUneAdresseEnClair` |
| Le secret n'apparaît dans aucun message, `print_r`, `var_export`, cast ni sérialisation | idem | `testLeSecretNApparaitNullePart`, `testLeClientNeSeSerialisePas` |
| Jamais `unserialize`, `eval`, `extract` ni `$$` | tout `src/` | `testTlsToujoursVerifie` |
| Aucune dépendance d'exécution (extension `curl` seulement) | `composer.json` | `composer audit` en CI |

En CI, à chaque modification : PHPStan (niveau max), Psalm et son analyse de propagation (`--taint-analysis`), `composer audit`, recherche de secrets (TruffleHog). Dependabot surveille les dépendances et les actions.
