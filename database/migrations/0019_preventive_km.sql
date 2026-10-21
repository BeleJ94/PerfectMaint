-- Échéances préventives au kilométrage (véhicules suivis au compteur kilométrique : bus, pick-up…),
-- en plus des jours et des heures moteur ; le premier seuil atteint déclenche l'échéance.
ALTER TABLE preventive_plans
    ADD COLUMN interval_km   INT UNSIGNED NULL AFTER interval_hours,
    ADD COLUMN last_done_km  INT UNSIGNED NULL AFTER last_done_hours,
    ADD COLUMN next_due_km   INT UNSIGNED NULL AFTER next_due_hours;

-- La contrainte d'origine n'existe pas sur toutes les bases (selon la version du serveur) :
-- suppression seulement si elle est présente (compatible MariaDB, MySQL et phpMyAdmin).
SET @pm_drop := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE preventive_plans DROP CONSTRAINT chk_pp_interval', 'DO 0')
                   FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'preventive_plans'
                    AND CONSTRAINT_NAME = 'chk_pp_interval');
PREPARE pm_stmt FROM @pm_drop;
EXECUTE pm_stmt;
DEALLOCATE PREPARE pm_stmt;

ALTER TABLE preventive_plans
    ADD CONSTRAINT chk_pp_interval
        CHECK (COALESCE(interval_days, 0) > 0 OR COALESCE(interval_hours, 0) > 0 OR COALESCE(interval_km, 0) > 0);
