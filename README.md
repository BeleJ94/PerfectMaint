# PerfectMaint

GMAO multi-sites de Perfect Logistics : parc d'engins, ordres de travail, maintenance préventive, pièces détachées, écrans terrain mobiles, journal d'activité.

## Démarrage

Prérequis : PHP ≥ 8.2 (pdo_mysql, mbstring, intl), MariaDB ≥ 10.11, Composer, Node ≥ 20 (tests JS uniquement).

```bash
composer install
cp .env.example .env          # renseigner DB_*
composer assets               # télécharge les librairies front (versions épinglées) si public/assets/vendor est vide
composer migrate              # applique database/migrations/*.sql
composer seed                 # (optionnel) jeu de démonstration, base vide uniquement
composer serve                # http://127.0.0.1:8080
```

Comptes de démonstration (mot de passe `PerfectMaint2026!`) :

| Compte | Rôle | Sites |
|---|---|---|
| admin@perfect-logistics.test | Administrateur | tous |
| marc.responsable@perfect-logistics.test | Responsable maintenance | Lyon, Marseille |
| sofia.tech@perfect-logistics.test | Technicien | Lyon |
| karim.tech@perfect-logistics.test | Technicien | Lille |
| lea.lecture@perfect-logistics.test | Consultation | Lille |

**Apache** : pointer le vhost sur `public/`. Si l'application est servie depuis la racine du projet ou un sous-dossier (ex. `http://localhost/MaintPerfect/`), le `.htaccess` racine redirige vers `public/` ; renseigner alors `APP_BASE_PATH=/MaintPerfect`.

**Nginx (ServBay)** : la racine du site DOIT être `…/MaintPerfect/public` (Nginx ignore les `.htaccess` : avec la racine du projet, `.env` et le code seraient téléchargeables). Réécriture : `try_files $uri $uri/ /index.php?$query_string;`.

**Cron** (génération quotidienne des OT préventifs à échéance) :

```
0 5 * * *  php /chemin/perfectmaint/bin/generate-preventive.php
```

## Tests

```bash
composer test        # PHPUnit (unitaires, intégration, fonctionnels) + node:test
```

Les tests d'intégration utilisent la base `perfectmaint_test` (mêmes identifiants que `.env`), recréée via les migrations à chaque exécution.

## Architecture

```
app/
  Core/          noyau : App (pipeline HTTP), Router, Request/Response, Container, Database, Session, Csrf, I18n, View, Validator, Migrator
  Domain/        règles métier pures : statuts d'engin et d'OT, transitions, rôles/permissions, échéances préventives
  Security/      Auth, CurrentUser, SiteScope (périmètre de sites)
  Repositories/  accès SQL — TOUTES les requêtes sur des données de site passent par SiteScope::clause()
  Services/      opérations métier transactionnelles + ActivityLogger
  Controllers/   HTTP → services → vues/JSON
  Views/         gabarits PHP (sorties échappées avec e())
database/migrations/  NNNN_nom.sql — seule voie d'évolution du schéma
lang/            catalogues fr.php (défaut) / en.php
public/assets/   css/app.css, js/ (pm-core, pm-tables, pm-export : modules purs testés sous Node ; app.js, dashboard.js)
```

### Règles appliquées

- **Filtrage par site dans les repositories** : `SiteScope` est construit depuis l'utilisateur connecté et injecté dans chaque repository ; un élément hors périmètre renvoie 404, une écriture hors périmètre 403. Admin et comptes « tous sites » ont un périmètre global.
- **CSRF** : jeton de session exigé sur toute requête non-GET (champ `_csrf` ou en-tête `X-CSRF-Token`, positionné globalement pour jQuery).
- **Échappement** : `e()` dans les vues, `PMCore.escapeHtml` côté JS ; aucune donnée injectée dans un script inline (blocs `application/json`), CSP `script-src 'self'`.
- **Journal d'activité** : chaque modification métier écrit qui / quoi / quand / avant-après (champs modifiés uniquement), dans la même transaction que la modification.
- **Transactions** : création d'OT, transitions, consommation de pièces (verrouillage `FOR UPDATE`, pas de stock négatif), mouvements de stock.

### Cycle de vie d'un OT et état de l'engin

| Événement | État de l'engin |
|---|---|
| OT ouvert « engin immobilisé » | En panne |
| OT démarré | En maintenance |
| OT en attente de pièce | Attente pièce |
| OT terminé / annulé | recalculé d'après les autres OT ouverts, sinon Disponible |
| Engin « Immobilisé » | ne change que par action manuelle (décision administrative) |

Un technicien n'agit que sur les OT de ses sites qui lui sont assignés ou non assignés (il se les voit attribuer en les démarrant) ; l'annulation est réservée aux responsables.
