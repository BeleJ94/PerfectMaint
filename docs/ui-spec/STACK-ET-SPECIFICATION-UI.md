# PerfectMaint — Stack technologique et spécification UI

> Document de référence : technologies utilisées et système de design / d'interaction de PerfectMaint
> (GMAO multi-sites de Perfect Logistics, éditée par BELEJ-CONSULTING).

---

## A. Stack technologique

### A.1 Backend

| Élément | Choix |
|---|---|
| Langage | PHP ≥ 8.2 (`strict_types`, enums) — PHP 8.4 en production |
| Framework | Aucun : noyau maison `app/Core` (Router, Container, Request/Response, Session, Csrf, I18n, View, Validator, Migrator) |
| Base de données | MariaDB ≥ 10.11 via PDO (`pdo_mysql`), extensions `mbstring`, `intl`, `json` |
| Schéma | Migrations SQL numérotées `database/migrations/NNNN_nom.sql` (`php bin/migrate.php`) — seule voie d'évolution |
| Dépendances Composer | `phpmailer/phpmailer` 6.9 (SMTP) ; dev : `phpunit/phpunit` ^11.5 |
| Autoload | PSR-4 `App\` → `app/`, helpers `app/Core/helpers.php` |
| Architecture | Controllers → Services (transactions + ActivityLogger) → Repositories (filtrage `SiteScope::clause()`) → Views (gabarits PHP, `e()`) ; règles métier pures dans `app/Domain` |
| Sécurité | `app/Security` : Auth, CurrentUser, SiteScope (périmètre de sites) ; rôles admin / manager / technician / viewer / encoder |
| Tâches planifiées | `bin/generate-preventive.php` (OT préventifs quotidiens), `bin/send-alerts.php` (e-mail d'alertes) |
| Fichiers | `storage/uploads` (photos, documents) hors racine web, servis après contrôle d'accès |

### A.2 Frontend (bibliothèques auto-hébergées, versions épinglées dans `bin/fetch-vendor-assets.sh`)

| Librairie | Version | Usage |
|---|---|---|
| jQuery | 3.7.1 | DOM, AJAX (en-tête CSRF global) |
| DataTables | 2.1.8 | Tableaux en mode serveur |
| Chart.js | 4.4.7 | Graphiques (tableau de bord, rapports, index) |
| SweetAlert2 | 11.14.5 | Modales de saisie et de confirmation |
| Lucide | 0.460.0 | Icônes |
| ExcelJS | 4.4.0 | Export Excel (chargé à la demande) |
| pdfmake | 0.2.14 | Export PDF (chargé à la demande) |
| IBM Plex Sans | 5.1.0 (woff2) | Police 400 / 500 / 600 |

JavaScript maison sans bundler ni framework :
- modules purs testés sous Node : `pm-core.js`, `pm-tables.js`, `pm-export.js`, `pm-reading.js` ;
- scripts d'écran : `app.js`, `dashboard.js`, `meters.js`, `meters-screen.js`, `entry.js`, `reports.js`, `activity.js` ;
- feuille de style unique : `public/assets/css/app.css`.

### A.3 Outillage, tests et infrastructure

| Domaine | Outil |
|---|---|
| Tests PHP | PHPUnit (unitaires, intégration sur `perfectmaint_test`, fonctionnels) |
| Tests JS | `node:test` (Node ≥ 20, sans dépendance) |
| Documentation | Playwright : captures (`npm run screenshots`), guide PDF (`npm run guide`) |
| Serveur web | Apache (vhost sur `public/`, `.htaccess`) ou Nginx (`try_files … /index.php`) ; local : `composer serve` (`php -S`) |
| Configuration | `.env` (DB_*, APP_BASE_PATH, APP_CURRENCY, APP_TIMEZONE = Africa/Lubumbashi) |
| Déploiement | GitHub Actions → SSH → VPS Plesk (`git reset --hard origin/main`) ; `composer install --no-dev` et `php bin/migrate.php` à lancer manuellement |

---

## B. Spécification UI


## B.1 Principes

| Principe | Règle concrète |
|---|---|
| **Dense mais lisible** | Tableaux compacts (15 lignes par défaut), libellés courts, chiffres en `tabular-nums`. Un écran = une question métier. |
| **L'état d'abord** | Toute entité a un état visible en couleur (pastille, bordure gauche, point). Les éléments urgents sont triés en premier. |
| **Tout chiffre est cliquable** | Un indicateur (KPI, compteur, notification) ouvre la liste filtrée correspondante (« drill-down »). |
| **Mobile = mode terrain dédié** | Bureau : sidebar + tableaux. Terrain : écrans simplifiés, cibles ≥ 44 px, champs 16 px (pas de zoom iOS). |
| **Aucune donnée perdue** | Les modifications sont historisées (qui / quand / avant-après). Les renouvellements créent une nouvelle ligne, l'ancienne passe en historique. |
| **Bilingue** | Toute chaîne visible passe par une clé de traduction (FR par défaut, EN). Aucune chaîne en dur dans les vues ni le JS. |
| **Sécurité par défaut** | Échappement systématique, CSP stricte sans script inline, CSRF sur toute requête non-GET, périmètre de données appliqué côté serveur. |

---

## B.2 Jetons de design (CSS custom properties)

```css
:root {
  /* Marque */
  --accent: #1F4E79;        --accent-hover: #173B5C;   --accent-soft: #E8EEF4;

  /* États (sémantiques, jamais décoratifs) */
  --st-available:   #1E7A4C;  /* OK / disponible / valide          */
  --st-breakdown:   #B42318;  /* critique / en panne / expiré       */
  --st-maintenance: #1F4E79;  /* en cours / en traitement           */
  --st-waiting-part:#B54708;  /* attention / en attente / bientôt   */
  --st-immobilized: #8D9098;  /* neutre / arrêté / sans objet       */

  /* Surfaces et texte */
  --bg: #F5F6F8;  --surface: #FFFFFF;  --surface-alt: #F9FAFB;
  --border: #DDE1E6;  --border-strong: #C4CAD2;
  --text: #1B2430;  --text-muted: #5B6573;
  --focus: 0 0 0 3px rgba(31, 78, 121, .35);

  /* Forme */
  --font: "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --radius: 6px;  --radius-lg: 10px;
  --shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.08);
  --sidebar-w: 232px;
  --touch: 44px;                       /* cible tactile minimale */
  --space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px; --space-5: 24px; --space-6: 32px;
}
```

**Fonds clairs associés aux états** (pastilles, cellules) : vert `#E7F3EC`, rouge `#FBEAE8`, orange `#FCEFE3`, bleu `--accent-soft`, gris `#EEEFF0`, neutre `#EEF0F3`.

**Typographie** : IBM Plex Sans 400 / 500 / 600 auto-hébergée (`woff2`, `font-display: swap`). Corps 15 px, interligne 1,5. `h1` 1,375 rem, `h2` 1,125 rem, `h3` 1 rem, tous en 600. Petits libellés 0,8125 rem ; en-têtes de tableau 0,8125 rem MAJUSCULES, `letter-spacing .03em`, couleur atténuée.

**Icônes** : Lucide (`<i data-lucide="nom">`), 18 px par défaut, trait 2. Une icône accompagne chaque titre de page, bouton d'action et entrée de menu.

**Points de rupture** : 1600 px (grand écran, tableaux plus denses), 1100 px (grilles 3 → 2, colonne latérale passe sous le contenu), 900 px (connexion sans visuel), **860 px (bascule mobile)**, 560 px (modales sur une colonne). `prefers-reduced-motion` coupe les transitions ; une feuille `@media print` masque navigation et actions.

---

## B.3 Structure des écrans

### B.3.1 Gabarits (layouts)

| Gabarit | Usage | Structure |
|---|---|---|
| `app` | Back-office (bureau) | Sidebar fixe + barre supérieure + zone de contenu |
| `field` | Terrain (mobile d'abord) | Barre d'en-tête colorée collante 56 px, contenu max 640 px centré, actions en bas collantes |
| `entry` | Saisie rapide d'un rôle restreint (ex. relevés) | Liste de cartes de saisie, barre de progression, onglets |
| `screen` | Affichage mural (TV, salle de contrôle) | Plein écran sombre/contrasté, horloge, pagination automatique, sans navigation |
| `minimal` | Connexion, erreurs | Centré, panneau unique, visuel à gauche sur grand écran |

### B.3.2 Gabarit `app`

```
┌──────────────┬──────────────────────────────────────────────────────┐
│  SIDEBAR     │ TOPBAR  [☰ mobile] Titre(mobile)   FR|EN  🔔3  (AV) │
│  232 px      ├──────────────────────────────────────────────────────┤
│  fond accent │ PAGE-HEADER  [icône] Titre        [Exporter][+ Nouveau]
│              │              sous-titre / méta                         │
│  Marque      │ KPIS  [ 3 ][ 12 ][ 40 ][ 52 ]  (cliquables)          │
│  Site actif ▾│ CARD  ┌ filtres ─────────────────────── réinit. ┐     │
│              │       │ Site ▾  Type ▾  État ▾   2 filtres actifs│     │
│  SECTION     │       └──────────────────────────────────────────┘     │
│   • Entrée   │       [Rechercher…]                    [15 ▾]         │
│   • Entrée 3 │       ┌ tableau compact, lignes cliquables ┐          │
│  SECTION     │       └────────────────────────────────────┘          │
│   …          │       1-15 sur 432                    ‹ 1 2 3 ›       │
│  Paramètres ▾│                                                       │
│  Mode terrain│                                                       │
└──────────────┴──────────────────────────────────────────────────────┘
```

- **Sidebar** : fond `--accent`, texte blanc 88 % d'opacité. Entrées de 44 px de haut, icône + libellé ; entrée courante `aria-current="page"` sur fond blanc 16 %. Sections en petites capitales (0,7 rem, opacité .6). Section « Paramètres » repliable (`<details>`). Bouton « réduire » : sidebar en icônes seules (préférence mémorisée). Pastille de compteur (`nav__badge`) à droite d'une entrée quand des alertes la concernent.
- **Sélecteur de site de travail** en tête de sidebar : restreint toutes les listes au site choisi (contexte de session).
- **Topbar** : 60 px, collante, fond blanc. Contient bascule de langue, cloche de notifications, avatar (initiales sur cercle `--accent-soft`). Le titre n'y est visible qu'en mobile (sinon il est dans la page).
- **Mobile (< 860 px)** : la sidebar devient un tiroir (`transform: translateX(-100%)`, voile sombre), ouvert par ☰ ; tous les boutons et champs passent à 44 px ; grilles sur une colonne.

### B.3.3 Organisation du menu (exemple transposable)

| Section | Entrées |
|---|---|
| Pilotage | Tableau de bord (ou accueil du site), Rapports, Alertes, Planning |
| Maintenance | Ordres de travail, Préventif, Suivi des index, Saisie |
| Parc | Engins, **Documents de bord**, Équipes & personnel |
| Magasin | Pièces, Besoins achats |
| Paramètres (repliable) | Sites, Utilisateurs, Référentiel, E-mails, Journal d'activité |
| Pied | Mode terrain |

Chaque entrée déclare : chemin, icône, clé de libellé, **permission requise** (l'entrée est masquée sans le droit) et **clés d'alerte** qui alimentent sa pastille.

---

## B.4 Composants

### B.4.1 En-tête de page (`page-header`)
Icône dans un carré coloré (`page-title__icon`, variante `--alert` rouge) + `h1` + ligne de méta (sous-titre, compteur). À droite, actions : exports (secondaires) puis action principale (`btn--primary`, icône `plus`). En impression, les actions disparaissent.

### B.4.2 Boutons
| Variante | Usage | Spécification |
|---|---|---|
| `btn` | Action secondaire | 40 px (44 en mobile), bordure `--border-strong`, fond blanc, texte 500 |
| `btn--primary` | Action principale (1 par zone) | Fond `--accent`, texte blanc, survol `--accent-hover` |
| `btn--danger` | Action destructive ou d'alerte (« Déclarer une panne ») | Fond `--st-breakdown` |
| `btn--ghost` | Action tertiaire, réinitialisation | Sans bordure ni fond |
| `btn--sm` / `btn--lg` / `btn--block` | Taille / pleine largeur | 32 px / 56 px |
| `icon-btn` (+ `--danger`, `--lg`) | Action sur une ligne (éditer, supprimer, appeler) | Carré, `title` + `aria-label` obligatoires |
| `link-btn` | Action dans un texte | Apparence de lien |

Toujours : icône + libellé pour les actions principales ; `disabled` pendant un appel réseau (évite le double envoi).

### B.4.3 Pastilles d'état (`badge`)
Pilule arrondie, point coloré `::before` de 8 px, texte 0,8125 rem 500. Une classe par état (`badge--available`, `--breakdown`, `--maintenance`, `--waiting_part`, `--immobilized`, `--neutral`). Variantes sans point : `--critical` (fond rouge plein, texte blanc), `--high`, `--cause-*`.
**Règle** : la même couleur signifie la même chose partout (vert = OK/valide, orange = bientôt/en attente, rouge = expiré/critique, gris = neutre/manquant).

Autres marqueurs d'état : `dot--*` (point seul), `chip` / `chip--warn` (étiquette), `tag` (méta de fiche, ex. plaque en `tag--plate` monospace), bordure gauche colorée sur les cartes et éléments de liste (`wo-card--critical`, `doc-list__item--expired`).

### B.4.4 Indicateurs (`kpi`)
Carte avec libellé (0,8125 rem atténué), valeur (1,75 rem 600, chiffres tabulaires), indice (aide sous la valeur). Variantes : `kpi--alert` (valeur rouge), `kpi--warn`, `kpi--hero` (mise en avant), `kpi--button` (cliquable).
Grille `kpis--compact` : `repeat(auto-fit, minmax(150px, 1fr))`. **Interaction** : un KPI est soit un lien vers une section/page, soit un bouton `data-set-filter="champ" data-value="valeur"` qui positionne le filtre de la liste en dessous et y fait défiler la page.

### B.4.5 Cartes (`card`)
Fond blanc, bordure, rayon 10 px, ombre légère, padding 24 px (16 en mobile). `card__title` : `h2` à gauche (icône `title-icon` optionnelle, variantes `--bad` / `--warn`), lien « Voir tout » ou petit bouton à droite. État vide : `empty` (texte atténué) ou `empty-state` (icône + message ; `--ok` vert « rien à signaler » ; `--drop` zone de dépôt).

### B.4.6 Tableaux de données
- **Serveur** (`<table data-table="nom" data-source="/x/data" data-row-link="/x/{id}">`) : DataTables en mode serveur, 15 lignes par défaut (15/25/50/100), recherche avec délai 350 ms, tri sur liste blanche serveur, ligne entière cliquable (`tr.row-link`, accessible au clavier par Entrée), en-tête compact.
- **Définition des colonnes** centralisée dans un module JS pur (`pm-tables.js`) : pour chaque colonne `data` (nom serveur, sert au tri), `title` (traduit), `html(row)` pour l'affichage (échappé), `text(row)` pour l'export, `orderable`, `className`. Un test vérifie que toute colonne triable figure dans la liste blanche PHP et que tout rendu échappe le HTML.
- **Statiques** (`table.table`) : au-delà de 15 lignes, pagination/tri/recherche côté client automatiques ; `data-no-dt` pour désactiver.
- **Contenu de cellule** : ligne principale en gras + ligne secondaire atténuée (`<br><span class="muted">`), nombres alignés à droite (`num`), dates courtes, retards en rouge (`overdue`).
- **Actions de ligne** : icônes à droite ; un clic sur un lien/bouton ne déclenche pas la navigation de ligne.

### B.4.7 Barre de filtres (`form.filters`)
Formulaire `data-filters-for="nom-du-tableau"` contenant des `select[data-filter]` (site, type, état…). Changement → rechargement AJAX du tableau (sans recharger la page). Le script ajoute automatiquement « N filtre(s) actif(s) » et un bouton « Réinitialiser » visibles seulement si un filtre est posé. Valeurs spéciales utiles : `action` = « tout ce qui demande une action » (ex. expirés + à renouveler).

### B.4.8 Exports
Deux boutons `data-export="excel|pdf" data-export-table="nom"` dans l'en-tête de page. L'export reprend **tous** les enregistrements correspondant aux filtres et au tri courants (requête `length=-1`, plafond 5 000 avec mention de troncature), avec titre, date et nombre de lignes. Excel : en-tête à la couleur d'accent, filtre automatique, volets figés, largeurs ajustées, types natifs (date, nombre, monnaie). PDF : paysage, pied de page avec nom de l'application. Bibliothèques chargées à la demande (ExcelJS, pdfmake).

### B.4.9 Formulaires
- Grille `form-grid` 2 colonnes (1 en mobile), `span-2` pour les champs larges ; formulaires longs en `form--sections` avec `form-section` titrées.
- Champ (`field`) : `label` (classe `required` → astérisque), contrôle `input` 40 px (44 px et 16 px en mobile), `hint` sous le champ, `error` en rouge + `aria-invalid="true"` sur le contrôle.
- Après une erreur serveur, le formulaire est réaffiché avec les valeurs saisies (`old()`) et les messages par champ.
- Contrôles spécifiques : `segmented` (choix exclusif en boutons radio stylés, ex. jour/nuit/rotation), `switch`, `check`, `range` avec `<output>` en direct, `input-icon` (ex. afficher/masquer le mot de passe), `textarea[data-autogrow]`.
- **Sélecteur d'entité avec recherche** (`data-equipment-picker`) : champ de recherche → liste de résultats à grandes cibles (52 px) → bloc « sélectionné » sur fond `--accent-soft` avec bouton « Changer ». Valeur dans un `input type=hidden`.
- **Fichier joint** : `input type=file` avec `accept` explicite, rappel des formats et de la taille max, lien vers le fichier actuel + case « Retirer le fichier » en modification. Formulaire en `multipart/form-data`.
- Actions de formulaire en bas à droite : « Annuler » (lien) puis « Enregistrer » (`btn--primary`, icône `save`).

### B.4.10 Fenêtres de dialogue
- **Saisie rapide** : un bouton `data-open="#id-template"` ouvre le contenu d'un `<template>` dans une modale (SweetAlert2). Boutons Annuler / Enregistrer (inversés : principal à droite), focus sur le premier champ, validation HTML5 native avant envoi, puis soumission classique du formulaire (redirection + message flash).
- **Confirmation** : `form[data-confirm="Message ?"]` → modale de confirmation avant toute suppression ou action irréversible.
- **Détail d'événement** (planning) : modale avec bandeau supérieur coloré selon le type, icône, grille libellé/valeur, boutons « Ouvrir » et « Modifier » selon les droits.
- **Panneau de détail (drill-down)** : panneau latéral avec titre, compteur, puces de filtres (`data-drill-chip`), mini-tableau, export et lien « Ouvrir la page complète ».
- **Visionneuse** : `data-lightbox` pour les photos.

### B.4.11 Notifications
- Cloche dans la topbar avec pastille de total ; panneau déroulant listant des **éléments actionnables** : icône, libellé avec compteur (« 2 document(s) d'engin expiré(s) »), niveau `bad` (rouge) ou `warn`. Chaque élément ouvre soit un panneau de détail, soit une page filtrée.
- Les mêmes éléments alimentent : les pastilles du menu, la page « Alertes » et l'e-mail récapitulatif quotidien (source unique côté serveur).
- Messages flash après action (`alert--success | --error | --info`) en haut du contenu.

### B.4.12 Fiche d'entité (page détail)
```
┌ HERO ─────────────────────────────────────────────────────────────┐
│ [photo]  CODE  (pastille d'état)        [Déclarer panne][Relevé][⋯]│
│          Marque Modèle · Nom                                       │
│          [📍 Site] [Catégorie] [PLAQUE] [🕒 depuis le …]           │
└───────────────────────────────────────────────────────────────────┘
VITALS  [⏱ Heures] [🛣 Km] [⛽ Carburant + jauge] [Contrat] [🔧 OT ouverts]
┌ colonne principale (2/3) ─────────┐ ┌ colonne latérale (1/3) ─────┐
│ Photos (galerie, dépôt de fichiers)│ │ Documents (état par type)   │
│ Détails (liste dl 2 colonnes)      │ │ Équipe affectée             │
│ Éléments liés (tableau compact)    │ │ Fluides / Relevés (timeline)│
│ Historique d'interventions         │ │ Préventif / Historique état │
└────────────────────────────────────┘ └─────────────────────────────┘
JOURNAL D'ACTIVITÉ (qui / quoi / quand / avant → après)
```
- Menu « ⋯ » (`data-menu`) pour les actions secondaires (changer l'état, modifier, archiver).
- `vital` : icône + libellé + valeur ; variantes `--alert` et icône `--good/--warn/--bad` ; jauge `meter`.
- Listes chronologiques `timeline` / `timeline--dense` : date + auteur en tête, contenu, note atténuée. Historique long replié dans `<details>`.

### B.4.13 Listes d'état par type (ex. documents à échéance)
Élément par type : en-tête (libellé + pastille d'état), méta (organisme · n° · « jusqu'au JJ/MM/AAAA »), note, lien du fichier 📎, outils (« Renouveler » / « Ajouter », ✏️, 🗑️), historique repliable. Bordure gauche colorée selon l'état. Les types sans document apparaissent quand même (« Non renseigné ») : l'absence est une information.

### B.4.14 Planning / calendrier
Barre (`planning-bar`) : navigation ‹ aujourd'hui ›, sélecteur segmenté Jour / Semaine / Mois, filtre de site, exports. Grille 7 colonnes (Lun→Dim), jour courant mis en évidence, week-end grisé, jours hors mois atténués. Événement (`ev ev--type`) : bordure + fond de la couleur du type, icône, code en gras ; `--overdue` (contour rouge), `--critical`. Au-delà de 3 événements/jour en vue mois : « +N » vers la vue jour. Légende avec compteurs par type. Export matriciel Excel/PDF : une ligne par entité, une colonne par jour, libellés courts colorés.

### B.4.15 Autres motifs réutilisables
- **Grille de tuiles** (`fleet-grid` / `fleet-tile`) : vue d'ensemble par groupe, filtre texte et puces d'état en direct, bordure d'état, vignette, jauge.
- **Cartes de site** (`site-card`) : pourcentage de disponibilité coloré (`--good/--warn/--bad`), compteurs, barre d'états segmentée (`status-bar`).
- **Barres horizontales** (`bars`) et **classement** (`top-list`) pour les rapports.
- **Fil de discussion** (`thread`) : bulles avec auteur/date, formulaire en bas.
- **Journal d'activité** : synthèse par utilisateur, filtres, détail avant/après en tableau de différences (`diff`, `log-action--add/--remove`).
- **Écran mural** (`wall`) : KPI géants, lignes paginées automatiquement, horloge, pause au survol, modale de courbe.

---

## B.5 Interactions — catalogue des attributs `data-*`

Toutes les interactions sont déclaratives (attributs HTML) et branchées par un script unique ; aucun JavaScript inline.

| Attribut | Effet |
|---|---|
| `data-table`, `data-source`, `data-row-link` | Tableau serveur, source JSON, URL de ligne avec jetons `{champ}` |
| `data-filters-for`, `data-filter` | Barre de filtres liée à un tableau ; rechargement au changement |
| `data-filters-count`, `data-filters-reset` | Compteur de filtres actifs, réinitialisation |
| `data-set-filter` + `data-value` | KPI qui positionne un filtre et fait défiler vers le tableau |
| `data-export`, `data-export-table`, `data-export-title` | Export Excel / PDF du tableau filtré |
| `data-open="#template"` | Ouvre un `<template>` en modale de saisie |
| `data-confirm="…"` | Confirmation avant soumission |
| `data-menu`, `data-menu-toggle` | Menu déroulant d'actions secondaires |
| `data-drill`, `data-drill-chip`, `data-drill-export`, `data-drill-close` | Panneau de détail depuis un KPI / graphique / notification |
| `data-notif-toggle`, `data-notif-panel`, `data-notif-badge`, `data-nav-badge` | Notifications et pastilles de menu |
| `data-equipment-picker` (+ `data-picker-*`) | Sélecteur d'entité avec recherche |
| `data-photo-upload`, `data-photo-drop`, `data-lightbox` | Envoi (redimensionné côté navigateur), glisser-déposer, visionneuse |
| `data-toggle-sidebar`, `data-sidebar-mini` | Tiroir mobile, sidebar réduite |
| `data-site-select`, `data-site-filtered` | Listes dépendantes du site choisi |
| `data-brand-select`, `data-model-select`, `data-quick-model` | Listes en cascade (marque → modèle) avec création rapide |
| `data-toggle-contract`, `data-contract-fields` | Affichage conditionnel de champs |
| `data-range` | Curseur avec valeur affichée en direct |
| `data-autogrow`, `data-autosubmit`, `data-print` | Zone de texte extensible, envoi au changement, impression |
| `data-toggle-password` | Afficher / masquer le mot de passe |
| `data-event`, `data-planning-export`, `data-report-export` | Détail d'événement, exports du planning et des rapports |

**Données vers le JS** : uniquement via des blocs `<script type="application/json" id="…">` (configuration, catalogue de traductions filtré par préfixes, données de page) lus au chargement.

---

## B.6 Flux types

1. **Consulter → agir** : KPI rouge → liste filtrée « à traiter » → clic sur la ligne → fiche → action (modale) → retour sur la fiche avec message flash et ancre sur la section (`#documents`).
2. **Créer** : bouton principal de la page → formulaire plein écran (sélecteur d'entité si besoin, valeurs pré-remplies par paramètres d'URL) → enregistrement → redirection vers la liste ou la fiche.
3. **Renouveler un élément à échéance** : bouton « Renouveler » sur la ligne → formulaire pré-rempli (entité + type, organisme repris) → nouvelle ligne, l'ancienne passe en historique.
4. **Terrain** : accueil en grandes tuiles d'action (64 px) → liste de cartes → détail → actions collantes en bas d'écran.
5. **Surveillance** : écran mural autonome rafraîchi périodiquement, sans interaction requise.

---

## B.7 Accessibilité

- Lien d'évitement « Aller au contenu » ; repères `aside`, `main`, `nav` avec `aria-label`.
- Focus visible partout (`box-shadow: var(--focus)`), navigation clavier sur les lignes cliquables.
- `aria-current="page"`, `aria-pressed` (boutons bascule), `aria-expanded` / `aria-haspopup` (menus), `role="menu"` / `menuitem`.
- Boutons-icônes : `title` + `aria-label`. Erreurs : `aria-invalid` + message texte.
- Couleur jamais seule porteuse d'information : toujours un libellé dans la pastille.
- Cibles tactiles ≥ 44 px en mobile ; champs ≥ 16 px.

---

## B.8 Règles techniques à reprendre

| Domaine | Règle |
|---|---|
| Rendu | Gabarits serveur ; toute sortie échappée (`e()`), HTML de cellule JS échappé (`escapeHtml`). |
| JS | Modules purs testables sous Node (`pm-core`, `pm-tables`, `pm-export`) + un script d'interface (`app.js`). jQuery, DataTables, SweetAlert2, Lucide, Chart.js ; ExcelJS / pdfmake chargés à la demande. Bibliothèques auto-hébergées, versions épinglées. |
| CSP | `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-ancestors 'none'` + `X-Frame-Options DENY`, `nosniff`. |
| Formulaires | Jeton CSRF (`_csrf` ou en-tête `X-CSRF-Token`) sur tout POST ; redirection après POST ; messages flash. |
| Tableaux | Endpoints `/…/data` au format DataTables (`draw`, `recordsTotal`, `recordsFiltered`, `data`), tri sur liste blanche, export `length=-1`. |
| Droits | Permissions par rôle vérifiées sur chaque route ; boutons masqués sans le droit (jamais seulement masqués : refus serveur 403). Périmètre de données (sites) appliqué dans les requêtes (404 hors périmètre). |
| Fichiers | Stockés hors racine web, servis par un contrôleur après contrôle d'accès ; type déterminé par le contenu (finfo), taille plafonnée, nom aléatoire. |
| i18n | Clés `zone.element` ; libellés d'énumérations `groupe.valeur` (ex. `status.breakdown`) ; seules les clés à préfixes autorisés sont envoyées au JS. Test automatique : toute clé utilisée existe. |
| Journal | Chaque modification métier écrit qui / quoi / quand / avant-après dans la même transaction. |

---

## B.9 Adapter à un autre projet — liste de contrôle

1. Remplacer `--accent` (+ `--accent-hover`, `--accent-soft`) par la couleur de marque ; **conserver la sémantique** des couleurs d'état.
2. Définir les états métier du nouveau projet et les associer aux 5 couleurs d'état (OK, en cours, attention, critique, neutre).
3. Reprendre les gabarits nécessaires (`app` presque toujours ; `field`, `screen`, `entry` selon les usages).
4. Construire le menu par sections avec permission et clés d'alerte par entrée.
5. Pour chaque liste : endpoint `/data`, définition de colonnes JS (`html` + `text`), filtres, KPI cliquables, exports.
6. Pour chaque entité : fiche avec hero, vitals, colonnes principale/latérale, journal d'activité.
7. Centraliser les alertes (une source → cloche, pastilles, page Alertes, e-mail).
8. Brancher les interactions par attributs `data-*` ; aucun script inline.
9. Écrire les traductions FR/EN dès le départ ; ajouter les tests (clés i18n, colonnes triables, échappement).

---

*PerfectMaint — édité par BELEJ-CONSULTING · www.belej-consulting.com*
