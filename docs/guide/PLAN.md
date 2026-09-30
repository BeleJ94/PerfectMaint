# Guide utilisateur PerfectMaint — plan détaillé

Public : utilisateurs de PerfectMaint chez Perfect Logistics (techniciens, responsables maintenance, direction, administrateurs).
Chaque procédure suit le même modèle : **Objectif → Qui peut le faire → Étapes (avec capture) → Résultat attendu → Erreurs fréquentes**.

Les captures sont produites automatiquement par `npm run screenshots` (voir [README-captures.md](README-captures.md)).
Le nom de chaque image est indiqué entre crochets, par exemple `[01-login]`, et correspond au fichier `images/fr/01-login.png`.

---

## Partie 1 — Prise en main (tous les profils)

### 1.1 Présentation
- À quoi sert PerfectMaint : suivi du parc d'engins, des pannes, des interventions, du préventif et du stock, sur les sites CHEMAF, KAMAO, METALKOL (terrassement, ADT, bennes), KINGAKILA et SICOMINES (bus).
- Les quatre profils et ce que chacun peut faire (tableau des droits, tiré de `app/Domain/Role.php`).
- Notion de **périmètre** : chacun ne voit que les sites qui lui sont attribués.

### 1.2 Se connecter `[01-login]`
- Adresse de l'application, identifiant (e-mail), mot de passe.
- Verrouillage après 5 tentatives échouées (15 minutes).
- Se déconnecter.

### 1.3 Se repérer dans l'écran `[02-dashboard]`
- Menu latéral, sélecteur de **site de travail**, choix de langue (FR / EN), notifications.
- Le tableau de bord : indicateurs (disponibilité, OT ouverts, MTTR…), graphique hebdomadaire, état du parc.
- **Cliquer un indicateur** ouvre la fenêtre de détail filtrable `[03-dashboard-drill]`.

### 1.4 Travailler avec les listes `[04-list-filters]`
- Barre de filtres : choisir un filtre recharge la liste ; compteur « N filtre(s) actif(s) » ; bouton **Réinitialiser**.
- Recherche, tri par colonne, 15 lignes par page (modifiable), pagination.
- Cliquer une ligne ouvre la fiche.
- **Exporter** en Excel ou PDF : l'export reprend les filtres et le tri en cours.

---

## Partie 2 — Technicien (écrans terrain, mobile)

### 2.1 Ma file de travail `[10-field-queue]`
- Accès à `/terrain` sur téléphone ; OT de mes sites, assignés à moi ou non assignés.

### 2.2 Déclarer une panne `[11-field-declare]`
- Choisir l'engin, décrire la panne, cocher « engin immobilisé » si l'engin ne peut plus travailler.
- Effet : l'engin passe **En panne** ; le conducteur en poste est enregistré automatiquement.

### 2.3 Traiter un OT sur le terrain `[12-field-wo]`
- Démarrer (l'OT m'est attribué), mettre en **attente pièce**, reprendre, **terminer** en indiquant la cause de panne.
- Ajouter une pièce consommée (avec relevé de compteur), ajouter un intervenant et ses minutes, laisser une remarque.

### 2.4 Relevés, appoints et photos (depuis la fiche engin) `[22-equipment-reading]`
- Relevé d'heures / kilomètres (un compteur ne peut pas diminuer).
- Appoint ou vidange de lubrifiant (sortie de stock).
- Ajouter une photo (redimensionnée automatiquement).

---

## Partie 3 — Responsable maintenance

### 3.1 Le parc d'engins `[20-equipment-list]`
- Filtrer par site, statut, catégorie ; lire la colonne « OT ouverts ».

### 3.2 La fiche engin `[21-equipment-show]`
- Identité (châssis, plaque, contrat / location), compteurs, photos, équipage affecté, fluides, préventifs, historique des statuts.
- Changer le statut manuellement (cas « Immobilisé » = décision administrative) `[23-equipment-status]`.
- Affecter un conducteur par shift `[24-equipment-assign]`.
- Créer / modifier un engin : le **modèle** impose la marque et la catégorie `[25-equipment-form]`.

### 3.3 Les ordres de travail
- Liste et filtres (statut, type, priorité, « mes OT ») `[30-wo-list]`.
- Créer un OT : engin, priorité, échéance, immobilisant ou non `[31-wo-create]`.
- La fiche OT : actions, détails, intervenants, pièces, commentaires, journal `[32-wo-show]`.
- **Cycle de vie** : Ouvert → En cours → Attente pièce → Terminé / Annulé, et effet sur l'état de l'engin (tableau).
- Clôturer : cause de panne obligatoire `[33-wo-done]`. L'annulation est réservée aux responsables.

### 3.4 Maintenance préventive `[40-preventive-list]`
- Plans par engin : intervalle en jours et/ou en heures, prochaine échéance, plans en retard.
- Créer un plan `[41-preventive-form]` ; générer les OT à échéance (automatique chaque jour à 5 h, ou bouton « Générer »).

### 3.5 Pièces, pneus, lubrifiants
- Catalogue et filtres (type, pièces d'usure, sous le minimum) `[50-parts-list]`.
- Fiche pièce : stock par site, seuils, emplacement, entrées / ajustements, historique de remplacement `[51-parts-show]`.
- Compatibilité par modèle d'engin et durée de vie attendue.

### 3.6 Besoins achats `[52-procurement]`
- Consommation mensuelle, couverture en jours, niveau (rupture / bas / surveillance / OK), quantité à commander.
- Règle de calcul : consommation 90 jours ÷ 3 ; stock cible = max(2 × minimum, 2 mois).
- Export Excel / PDF pour le service achats.

### 3.7 Pilotage
- **Alertes coûts** : usure prématurée, surconsommation de lubrifiant, fins de contrat `[60-alerts]`.
- **Planning** jour / semaine / mois `[61-planning]`.
- **Fenêtre par site** : parc, disponibilité, interventions en cours, équipes `[62-site-overview]`.

### 3.8 Équipes et personnel
- Équipes par shift (jour / nuit / rotation), chef d'équipe, n° du dispatch `[70-teams]`.
- Personnel : conducteurs, mécaniciens, affectations `[71-personnel]`.

### 3.9 Référentiel marques / modèles `[72-catalog]`

---

## Partie 4 — Direction et consultation

### 4.1 Rapports de direction `[80-reports]`
- Disponibilité par site, pannes, immobilisation, MTTR, consommations, causes de panne, engins les plus coûteux, conducteurs.
- Chaque chiffre est cliquable et ouvre le détail `[81-reports-drill]`.
- Profil **Consultation** : accès en lecture seule `[82-viewer-dashboard]`.

---

## Partie 5 — Administration

- Sites : créer, activer / désactiver `[90-sites]`.
- Utilisateurs : rôle, sites attribués, compte actif, langue `[91-users]` `[92-user-form]`.
- Journal d'activité : qui a fait quoi, quand, avec les valeurs avant / après `[93-activity]`.

---

## Annexes
- A. Tableau des droits par profil.
- B. Cycle de vie d'un OT et état de l'engin.
- C. Glossaire : OT, MTTR, disponibilité, préventif, immobilisation, shift, dispatch.
- D. FAQ : « Je ne vois pas un engin » (périmètre de sites), « Je ne peux pas annuler un OT » (profil), « Le compteur est refusé » (compteur non décroissant), « Stock insuffisant » (pas de stock négatif).
