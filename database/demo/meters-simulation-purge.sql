-- =====================================================================================================
-- PerfectMaint — retrait des relevés d'index de démonstration (voir meters-simulation.sql)
-- Supprime les relevés générés, remet les relevés d'origine et restaure les échéances des plans.
-- Les relevés saisis à la main après la simulation sont conservés ; les index des engins ne sont pas touchés.
--
-- Si la première instruction échoue avec « Table 'sim_reading_ids' doesn't exist », aucune simulation
-- n'est en place : il n'y a rien à retirer.
-- =====================================================================================================

DELETE r FROM meter_readings r JOIN sim_reading_ids i ON i.id = r.id;

INSERT INTO meter_readings SELECT * FROM sim_backup_readings;

UPDATE preventive_plans p
  JOIN sim_backup_plans b ON b.id = p.id
   SET p.last_done_hours = b.last_done_hours,
       p.next_due_hours = b.next_due_hours;

-- Date du dernier relevé : celle d'origine…
UPDATE equipment e
  JOIN sim_backup_equipment b ON b.id = e.id
   SET e.meter_updated_at = b.meter_updated_at;

-- … sauf pour les engins qui ont reçu un vrai relevé après la simulation.
UPDATE equipment e
  JOIN (SELECT r.equipment_id, MAX(r.recorded_at) AS last_at
          FROM meter_readings r
         WHERE r.id > (SELECT MAX(id) FROM sim_reading_ids)
         GROUP BY r.equipment_id) n ON n.equipment_id = e.id
   SET e.meter_updated_at = n.last_at
 WHERE e.meter_updated_at IS NULL OR n.last_at > e.meter_updated_at;

DROP TABLE sim_reading_ids;
DROP TABLE sim_backup_readings;
DROP TABLE sim_backup_plans;
DROP TABLE sim_backup_equipment;
DROP TABLE IF EXISTS sim_days;
DROP TABLE IF EXISTS sim_engines;
DROP TABLE IF EXISTS sim_daily;
DROP TABLE IF EXISTS sim_first_plan;

SELECT COUNT(*) AS releves_restants FROM meter_readings;
