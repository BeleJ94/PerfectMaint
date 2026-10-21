-- =====================================================================================================
-- PerfectMaint — remise en cohérence des plans préventifs au compteur (octobre 2026)
--
-- À exécuter APRÈS les migrations 0019 (échéances au km) et 0020 (compteur de suivi par catégorie).
-- Compatible phpMyAdmin (onglet SQL, fichier en UTF-8) et ligne de commande :
--   mysql --default-character-set=utf8mb4 <base> < database/demo/fix-plans-compteur-2026-10.sql
--
-- Ce que fait le script :
--   0. Diagnostic : liste des plans concernés (avant correction).
--   1. Sauvegarde complète de preventive_plans dans sauvegarde_preventive_plans_202610.
--   2. Plans dans le mauvais compteur (ex. 005 : véhicule suivi au km, plan saisi en heures) :
--      a) si le seuil « heures » a l'ordre de grandeur du kilométrage de l'engin, ce sont des km saisis
--         dans les champs heures → valeurs déplacées telles quelles vers les champs km ;
--      b) sinon, le plan est recréé au compteur de l'engin à partir de son index actuel
--         (10 000 km pour un véhicule, 250 h pour un engin) → à ajuster ensuite dans /preventive.
--   3. Points de départ manquants : on enregistre celui que l'application utilisait déjà
--      (échéance − périodicité) et la date de création du plan comme date de dernière réalisation.
--      Les pourcentages ne changent pas, mais le point de départ devient visible et modifiable.
--   4. Contrôle final.
--
-- Le script peut être relancé : il ne retouche que les plans encore incohérents.
-- Retour arrière : voir la fin du fichier.
-- =====================================================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------------- 0. Diagnostic
SELECT e.code, c.name AS categorie, COALESCE(e.meter_basis, c.meter_basis, 'hours') AS compteur_engin,
       e.hour_meter AS heures_actuelles, e.odometer_km AS km_actuels,
       p.id AS plan_id, p.title, p.interval_hours, p.last_done_hours, p.next_due_hours,
       p.interval_km, p.last_done_km, p.next_due_km, p.last_done_at,
       CASE
           WHEN COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'km' AND p.interval_km IS NULL AND p.interval_hours IS NOT NULL THEN 'plan en heures sur un véhicule au km'
           WHEN COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'hours' AND p.interval_hours IS NULL AND p.interval_km IS NOT NULL THEN 'plan en km sur un engin aux heures'
           ELSE 'point de départ manquant'
       END AS probleme
  FROM preventive_plans p
  JOIN equipment e ON e.id = p.equipment_id
  LEFT JOIN equipment_categories c ON c.id = e.category_id
 WHERE (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'km' AND p.interval_km IS NULL AND p.interval_hours IS NOT NULL)
    OR (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'hours' AND p.interval_hours IS NULL AND p.interval_km IS NOT NULL)
    OR (p.interval_hours IS NOT NULL AND p.last_done_hours IS NULL)
    OR (p.interval_km IS NOT NULL AND p.last_done_km IS NULL)
    OR ((p.interval_hours IS NOT NULL OR p.interval_km IS NOT NULL) AND p.last_done_at IS NULL)
 ORDER BY e.code, p.id;

-- ---------------------------------------------------------------------------------- 1. Sauvegarde
CREATE TABLE IF NOT EXISTS sauvegarde_preventive_plans_202610 AS SELECT * FROM preventive_plans;

-- ---------------------------------------------------------------------------------- 2. Mauvais compteur
DROP TEMPORARY TABLE IF EXISTS fix_compteur;
CREATE TEMPORARY TABLE fix_compteur AS
SELECT p.id,
       COALESCE(e.meter_basis, c.meter_basis, 'hours') AS basis,
       e.hour_meter,
       e.odometer_km,
       -- km saisis dans les champs heures : seuil du même ordre que le kilométrage de l'engin
       CASE WHEN e.odometer_km IS NOT NULL AND e.odometer_km > 0
                 AND p.next_due_hours BETWEEN e.odometer_km * 0.5 AND e.odometer_km * 2 + 50000
            THEN 'deplace' ELSE 'recree' END AS mode
  FROM preventive_plans p
  JOIN equipment e ON e.id = p.equipment_id
  LEFT JOIN equipment_categories c ON c.id = e.category_id
 WHERE (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'km' AND p.interval_km IS NULL AND p.interval_hours IS NOT NULL)
    OR (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'hours' AND p.interval_hours IS NULL AND p.interval_km IS NOT NULL);

-- 2a. Véhicule au km, valeurs km saisies dans les champs heures : déplacement tel quel
UPDATE preventive_plans p JOIN fix_compteur f ON f.id = p.id
   SET p.interval_km = p.interval_hours,
       p.last_done_km = COALESCE(p.last_done_hours, GREATEST(CAST(p.next_due_hours AS SIGNED) - CAST(p.interval_hours AS SIGNED), 0)),
       p.next_due_km = p.next_due_hours
 WHERE f.basis = 'km' AND f.mode = 'deplace';

-- 2b. Véhicule au km, vraies valeurs horaires : plan recréé à 10 000 km depuis le kilométrage actuel
UPDATE preventive_plans p JOIN fix_compteur f ON f.id = p.id
   SET p.interval_km = 10000,
       p.last_done_km = COALESCE(f.odometer_km, 0),
       p.next_due_km = COALESCE(f.odometer_km, 0) + 10000,
       p.last_done_at = CURDATE()
 WHERE f.basis = 'km' AND f.mode = 'recree';

-- Champs heures vidés une fois les km renseignés
UPDATE preventive_plans p JOIN fix_compteur f ON f.id = p.id
   SET p.interval_hours = NULL, p.last_done_hours = NULL, p.next_due_hours = NULL
 WHERE f.basis = 'km' AND p.interval_km IS NOT NULL;

-- 2c. Engin aux heures avec un plan uniquement en km : plan recréé à 250 h depuis l'horamètre actuel
UPDATE preventive_plans p JOIN fix_compteur f ON f.id = p.id
   SET p.interval_hours = 250,
       p.last_done_hours = COALESCE(f.hour_meter, 0),
       p.next_due_hours = COALESCE(f.hour_meter, 0) + 250,
       p.last_done_at = CURDATE()
 WHERE f.basis = 'hours';

UPDATE preventive_plans p JOIN fix_compteur f ON f.id = p.id
   SET p.interval_km = NULL, p.last_done_km = NULL, p.next_due_km = NULL
 WHERE f.basis = 'hours' AND p.interval_hours IS NOT NULL;

-- ---------------------------------------------------------------------------------- 3. Points de départ manquants
-- Point de départ effectivement utilisé jusqu'ici : échéance − périodicité.
UPDATE preventive_plans
   SET last_done_hours = GREATEST(CAST(next_due_hours AS SIGNED) - CAST(interval_hours AS SIGNED), 0)
 WHERE interval_hours IS NOT NULL AND next_due_hours IS NOT NULL AND last_done_hours IS NULL;

UPDATE preventive_plans
   SET last_done_km = GREATEST(CAST(next_due_km AS SIGNED) - CAST(interval_km AS SIGNED), 0)
 WHERE interval_km IS NOT NULL AND next_due_km IS NOT NULL AND last_done_km IS NULL;

UPDATE preventive_plans
   SET last_done_at = DATE(created_at)
 WHERE (interval_hours IS NOT NULL OR interval_km IS NOT NULL) AND last_done_at IS NULL;

DROP TEMPORARY TABLE IF EXISTS fix_compteur;

-- ---------------------------------------------------------------------------------- 4. Contrôle final
-- Doit renvoyer 0 ligne.
SELECT e.code, p.id AS plan_id, p.title
  FROM preventive_plans p
  JOIN equipment e ON e.id = p.equipment_id
  LEFT JOIN equipment_categories c ON c.id = e.category_id
 WHERE (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'km' AND p.interval_km IS NULL AND p.interval_hours IS NOT NULL)
    OR (COALESCE(e.meter_basis, c.meter_basis, 'hours') = 'hours' AND p.interval_hours IS NULL AND p.interval_km IS NOT NULL)
    OR (p.interval_hours IS NOT NULL AND p.last_done_hours IS NULL)
    OR (p.interval_km IS NOT NULL AND p.last_done_km IS NULL)
    OR ((p.interval_hours IS NOT NULL OR p.interval_km IS NOT NULL) AND p.last_done_at IS NULL);

-- Plans au compteur après correction : vérifier chaque point de départ (« dernière ») avec l'atelier.
SELECT e.code, COALESCE(e.meter_basis, c.meter_basis, 'hours') AS compteur_engin, p.title,
       CASE WHEN p.interval_km IS NOT NULL THEN CONCAT(p.last_done_km, ' km → ', p.next_due_km, ' km (actuel ', COALESCE(e.odometer_km, '—'), ' km)')
            ELSE CONCAT(p.last_done_hours, ' h → ', p.next_due_hours, ' h (actuel ', COALESCE(e.hour_meter, '—'), ' h)') END AS derniere_vers_echeance,
       p.last_done_at AS derniere_le
  FROM preventive_plans p
  JOIN equipment e ON e.id = p.equipment_id
  LEFT JOIN equipment_categories c ON c.id = e.category_id
 WHERE p.is_active = 1 AND (p.interval_hours IS NOT NULL OR p.interval_km IS NOT NULL)
 ORDER BY e.code;

-- =====================================================================================================
-- Ensuite, pour un pourcentage réel : renseigner la vraie dernière maintenance (exemple, à adapter) :
--
-- UPDATE preventive_plans p JOIN equipment e ON e.id = p.equipment_id
--    SET p.last_done_km = 0, p.next_due_km = 0 + p.interval_km, p.last_done_at = '2026-09-15'
--  WHERE e.code = '006' AND p.title = 'Service';
--
-- (ou plus simplement : ouvrir le plan dans /preventive, corriger « Kilométrage à la dernière réalisation »
--  et sa date, enregistrer — l'échéance est recalculée.)
--
-- Retour arrière (valeurs des compteurs remises comme avant le script) :
--   UPDATE preventive_plans p JOIN sauvegarde_preventive_plans_202610 s ON s.id = p.id
--      SET p.interval_hours = s.interval_hours, p.last_done_hours = s.last_done_hours, p.next_due_hours = s.next_due_hours,
--          p.interval_km = s.interval_km, p.last_done_km = s.last_done_km, p.next_due_km = s.next_due_km,
--          p.last_done_at = s.last_done_at;
-- Une fois les plans vérifiés : DROP TABLE sauvegarde_preventive_plans_202610;
-- =====================================================================================================
