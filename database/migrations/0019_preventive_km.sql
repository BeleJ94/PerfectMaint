-- Échéances préventives au kilométrage (véhicules suivis au compteur kilométrique : bus, pick-up…),
-- en plus des jours et des heures moteur ; le premier seuil atteint déclenche l'échéance.
ALTER TABLE preventive_plans
    ADD COLUMN interval_km   INT UNSIGNED NULL AFTER interval_hours,
    ADD COLUMN last_done_km  INT UNSIGNED NULL AFTER last_done_hours,
    ADD COLUMN next_due_km   INT UNSIGNED NULL AFTER next_due_hours,
    DROP CONSTRAINT chk_pp_interval;

ALTER TABLE preventive_plans
    ADD CONSTRAINT chk_pp_interval
        CHECK (COALESCE(interval_days, 0) > 0 OR COALESCE(interval_hours, 0) > 0 OR COALESCE(interval_km, 0) > 0);
