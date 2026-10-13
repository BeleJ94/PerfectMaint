-- =====================================================================================================
-- PerfectMaint — relevés d'index réalistes sur 18 mois (démonstration de la supervision des index)
-- À exécuter sur la base du serveur : phpMyAdmin > onglet SQL, ou  mysql -u UTILISATEUR -p BASE < ce_fichier
-- Nécessite MariaDB 10.2+ ou MySQL 8 (fonctions de fenêtrage) : la première instruction le vérifie.
--
-- Les relevés générés ressemblent à une vraie exploitation :
--   - chaque engin a son rythme propre, qui varie d'un jour et d'un mois à l'autre ;
--   - samedis réduits, dimanches le plus souvent à l'arrêt ;
--   - arrêts de 2 à 6 jours (panne, entretien) tous les deux à trois mois : l'index ne bouge plus ;
--   - quelques jours sans relevé (oubli de saisie), rattrapés au relevé suivant ;
--   - saisie en début de poste (05 h 30 – 07 h 30), attribuée à un technicien ou responsable du site ;
--   - kilométrage cohérent avec les heures pour les engins qui ont un compteur kilométrique.
-- L'historique aboutit exactement à l'index actuel de chaque engin : les index ne sont pas modifiés.
--
-- Ce que le script modifie :
--   - les relevés existants des engins concernés sont mis de côté (sim_backup_readings) et remplacés ;
--   - les échéances des plans horaires sont recalées pour montrer tous les cas (échue, à préparer,
--     dans les temps) ; les valeurs d'origine sont gardées dans sim_backup_plans ;
--   - la table « settings » (paramètres e-mail, migration 0015) est créée si elle manque.
-- « meters-simulation-purge.sql » retire tout et restaure l'état d'origine.
--
-- À réserver à une base de démonstration. Si l'instruction 2 échoue avec « Table 'sim_backup_readings'
-- already exists », une simulation est déjà en place : exécutez d'abord meters-simulation-purge.sql.
-- =====================================================================================================

-- ----------------------------------------------------------------------------------------------------
-- 0. Vérification du serveur, puis sauvegarde (échoue volontairement si une simulation existe déjà)
-- ----------------------------------------------------------------------------------------------------
SELECT SUM(1) OVER () AS fonctions_de_fenetrage_disponibles;

CREATE TABLE sim_backup_readings AS
    SELECT r.*
      FROM meter_readings r
      JOIN equipment e ON e.id = r.equipment_id
     WHERE e.is_archived = 0 AND e.hour_meter IS NOT NULL;

CREATE TABLE sim_backup_plans AS
    SELECT p.id, p.last_done_hours, p.next_due_hours
      FROM preventive_plans p
      JOIN equipment e ON e.id = p.equipment_id
     WHERE e.is_archived = 0 AND e.hour_meter IS NOT NULL AND p.is_active = 1 AND p.interval_hours IS NOT NULL;

CREATE TABLE sim_backup_equipment AS
    SELECT id, meter_updated_at FROM equipment WHERE is_archived = 0 AND hour_meter IS NOT NULL;

-- ----------------------------------------------------------------------------------------------------
-- 1. Schéma : table des paramètres (migration 0015), sans effet si elle existe déjà
-- ----------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    name        VARCHAR(80) NOT NULL,
    value       TEXT        NULL,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration  VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('0015_create_settings.sql');

-- ----------------------------------------------------------------------------------------------------
-- 2. Tables de travail
-- ----------------------------------------------------------------------------------------------------
DROP TABLE IF EXISTS sim_days;
DROP TABLE IF EXISTS sim_engines;
DROP TABLE IF EXISTS sim_daily;
DROP TABLE IF EXISTS sim_first_plan;

-- Jours 0 (aujourd'hui) à 547 (il y a 18 mois)
CREATE TABLE sim_days (d INT NOT NULL PRIMARY KEY, day DATE NOT NULL, dow INT NOT NULL);
INSERT INTO sim_days (d, day, dow)
SELECT n.d, CURDATE() - INTERVAL n.d DAY, DAYOFWEEK(CURDATE() - INTERVAL n.d DAY)   -- dow : 1 = dimanche, 7 = samedi
  FROM (SELECT u.n + t.n * 10 + h.n * 100 AS d
          FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
         CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
         CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) h) n
 WHERE n.d <= 547;

-- Profil de chaque engin
CREATE TABLE sim_engines (
    id          INT NOT NULL PRIMARY KEY,
    site_id     INT NOT NULL,
    rate        INT NOT NULL,            -- heures par jour en pleine activité
    km_per_hour DECIMAL(5, 1) NULL,      -- NULL : engin sans compteur kilométrique
    stale       INT NOT NULL,            -- jours écoulés depuis le dernier relevé
    stop_every  INT NOT NULL,            -- un arrêt tous les N jours environ
    stop_shift  INT NOT NULL,            -- décalage des arrêts, propre à l'engin
    final_h     INT NOT NULL,            -- index horaire actuel (inchangé)
    final_km    INT NULL
);

INSERT INTO sim_engines (id, site_id, rate, km_per_hour, stale, stop_every, stop_shift, final_h, final_km)
SELECT e.id, e.site_id,
       -- 8 à 20 h/jour, plafonné pour que 18 mois d'historique ne remontent pas sous zéro
       LEAST(8 + CAST(CRC32(CONCAT('r', e.id)) % 13 AS SIGNED), GREATEST(1, FLOOR(CAST(e.hour_meter AS SIGNED) * 0.95 / 548))),
       CASE WHEN e.odometer_km IS NULL THEN NULL
            ELSE LEAST(6 + CAST(CRC32(CONCAT('k', e.id)) % 13 AS SIGNED),
                       GREATEST(0.5, CAST(e.odometer_km AS SIGNED) / GREATEST(1, CAST(e.hour_meter AS SIGNED)))) END,
       -- environ un engin sur sept sans relevé depuis 2 à 5 jours, un sur onze depuis la veille
       CASE WHEN CRC32(CONCAT('s', e.id)) % 7 = 3 THEN 2 + CAST(CRC32(CONCAT('t', e.id)) % 4 AS SIGNED)
            WHEN CRC32(CONCAT('s', e.id)) % 11 = 5 THEN 1
            ELSE 0 END,
       55 + CAST(CRC32(CONCAT('p', e.id)) % 41 AS SIGNED),
       CAST(CRC32(CONCAT('o', e.id)) % 90 AS SIGNED),
       CAST(e.hour_meter AS SIGNED),
       CAST(e.odometer_km AS SIGNED)
  FROM equipment e
 WHERE e.is_archived = 0 AND e.hour_meter IS NOT NULL;

-- Utilisation de chaque jour : heures tournées (0 pendant un arrêt), km parcourus, relevé saisi ou non
CREATE TABLE sim_daily (
    id      INT NOT NULL,
    d       INT NOT NULL,
    stopped TINYINT NOT NULL,
    hours   INT NOT NULL,
    km      INT NOT NULL,
    PRIMARY KEY (id, d)
);

INSERT INTO sim_daily (id, d, stopped, hours, km)
SELECT x.id, x.d, x.stopped,
       CASE WHEN x.stopped = 1 THEN 0 ELSE ROUND(x.rate * x.week_factor * x.day_factor * x.month_factor) END,
       0
  FROM (SELECT s.id, s.rate, d.d,
               -- arrêt de 2 à 6 jours au début de chaque période de « stop_every » jours
               CASE WHEN (d.d + s.stop_shift) % s.stop_every
                         < 2 + CAST(CRC32(CONCAT(s.id, 'b', FLOOR((d.d + s.stop_shift) / s.stop_every))) % 5 AS SIGNED)
                    THEN 1 ELSE 0 END AS stopped,
               CASE d.dow
                    WHEN 1 THEN CASE WHEN CRC32(CONCAT(s.id, 'w', d.d)) % 10 < 7 THEN 0 ELSE 0.35 END   -- dimanche
                    WHEN 7 THEN 0.6                                                                      -- samedi
                    ELSE 1 END AS week_factor,
               0.60 + CAST(CRC32(CONCAT(s.id, 'j', d.d)) % 71 AS SIGNED) / 100 AS day_factor,           -- 0,60 à 1,30
               0.85 + CAST(CRC32(CONCAT(s.id, 'm', DATE_FORMAT(d.day, '%Y%m'))) % 31 AS SIGNED) / 100 AS month_factor
          FROM sim_engines s
         CROSS JOIN sim_days d) x;

UPDATE sim_daily y
  JOIN sim_engines s ON s.id = y.id
   SET y.km = ROUND(y.hours * s.km_per_hour * (0.80 + CAST(CRC32(CONCAT(y.id, 'q', y.d)) % 41 AS SIGNED) / 100))
 WHERE s.km_per_hour IS NOT NULL;

-- ----------------------------------------------------------------------------------------------------
-- 3. Remplacement des relevés
-- ----------------------------------------------------------------------------------------------------
SET @sim_first_id = (SELECT COALESCE(MAX(id), 0) FROM meter_readings);

DELETE r FROM meter_readings r JOIN sim_engines s ON s.id = r.equipment_id;

-- Le relevé du jour J (pris en début de poste) vaut l'index actuel moins tout ce qui a tourné depuis.
-- Pas de relevé : certains jours d'arrêt, certains dimanches chômés, et un oubli de saisie de temps en temps.
INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, note, recorded_by, recorded_at)
SELECT c.id,
       GREATEST(0, c.final_h - c.used_hours),
       CASE WHEN c.final_km IS NULL THEN NULL ELSE GREATEST(0, c.final_km - c.used_km) END,
       CASE WHEN c.d = c.stale OR CRC32(CONCAT(c.id, 'n', c.d)) % 9 = 0 THEN 'Prise de poste' ELSE NULL END,
       (SELECT u.id
          FROM users u
          LEFT JOIN user_sites us ON us.user_id = u.id AND us.site_id = c.site_id
         WHERE u.is_active = 1 AND u.role IN ('technician', 'manager') AND (u.all_sites = 1 OR us.site_id IS NOT NULL)
         ORDER BY (u.role = 'technician') DESC, CRC32(CONCAT(u.id, '-', c.id, '-', FLOOR(c.d / 7)))
         LIMIT 1),
       LEAST(NOW(), c.day + INTERVAL (330 + CAST(CRC32(CONCAT(c.id, 'h', c.d)) % 120 AS SIGNED)) MINUTE)
  FROM (SELECT s.id, s.site_id, s.stale, s.final_h, s.final_km, y.d, y.stopped, y.hours, d.day, d.dow,
               SUM(CASE WHEN y.d > s.stale THEN y.hours ELSE 0 END) OVER (PARTITION BY s.id ORDER BY y.d) AS used_hours,
               SUM(CASE WHEN y.d > s.stale THEN y.km ELSE 0 END) OVER (PARTITION BY s.id ORDER BY y.d) AS used_km
          FROM sim_engines s
          JOIN sim_daily y ON y.id = s.id AND y.d >= s.stale
          JOIN sim_days d ON d.d = y.d) c
 WHERE c.d = c.stale
    OR NOT (   (c.stopped = 1 AND CRC32(CONCAT(c.id, 'a', c.d)) % 10 < 8)
            OR (c.dow = 1 AND c.hours = 0 AND CRC32(CONCAT(c.id, 'd', c.d)) % 10 < 6)
            OR CRC32(CONCAT(c.id, 'f', c.d)) % 100 < 5)
 ORDER BY c.d DESC, c.id;

-- Identifiants des relevés générés (pour pouvoir les retirer sans toucher aux relevés saisis ensuite)
CREATE TABLE sim_reading_ids AS SELECT id FROM meter_readings WHERE id > @sim_first_id;

UPDATE equipment e
  JOIN sim_engines s ON s.id = e.id
   SET e.meter_updated_at = (SELECT MAX(r.recorded_at) FROM meter_readings r WHERE r.equipment_id = e.id);

-- ----------------------------------------------------------------------------------------------------
-- 4. Échéances des plans horaires : le plan le plus court porte le scénario, les suivants tombent plus tard
-- ----------------------------------------------------------------------------------------------------
CREATE TABLE sim_first_plan (
    equipment_id INT NOT NULL PRIMARY KEY,
    plan_id      INT NOT NULL,
    interval_h   INT NOT NULL,
    due          INT NOT NULL DEFAULT 0
);

INSERT INTO sim_first_plan (equipment_id, plan_id, interval_h)
SELECT p.equipment_id, MIN(p.id), m.interval_h
  FROM preventive_plans p
  JOIN (SELECT equipment_id, MIN(interval_hours) AS interval_h
          FROM preventive_plans WHERE is_active = 1 AND interval_hours IS NOT NULL GROUP BY equipment_id) m
    ON m.equipment_id = p.equipment_id AND m.interval_h = p.interval_hours
  JOIN sim_engines s ON s.id = p.equipment_id
 WHERE p.is_active = 1
 GROUP BY p.equipment_id, m.interval_h;

-- Un engin sur dix échu, deux sur dix à préparer, les autres dans les temps.
UPDATE sim_first_plan f
  JOIN sim_engines s ON s.id = f.equipment_id
   SET f.due = s.final_h + LEAST(f.interval_h - 1,
       CASE CAST(CRC32(CONCAT('e', s.id)) % 10 AS SIGNED)
            WHEN 0 THEN -(5 + CAST(CRC32(CONCAT('g', s.id)) % 56 AS SIGNED))                           -- échue
            WHEN 1 THEN s.rate * (2 + CAST(CRC32(CONCAT('g', s.id)) % 4 AS SIGNED))                    -- à préparer : sous 7 jours
            WHEN 2 THEN ROUND(f.interval_h * (4 + CAST(CRC32(CONCAT('g', s.id)) % 6 AS SIGNED)) / 100)  -- à préparer : 90 % consommés
            ELSE GREATEST(s.rate * 12, ROUND(f.interval_h * (30 + CAST(CRC32(CONCAT('g', s.id)) % 56 AS SIGNED)) / 100))
       END);

UPDATE preventive_plans p
  JOIN sim_first_plan f ON f.equipment_id = p.equipment_id
   SET p.next_due_hours = f.due + CAST(p.interval_hours AS SIGNED) - f.interval_h,
       p.last_done_hours = GREATEST(0, f.due - f.interval_h)
 WHERE p.is_active = 1 AND p.interval_hours IS NOT NULL;

-- ----------------------------------------------------------------------------------------------------
-- 5. Nettoyage des tables de travail et bilan
-- ----------------------------------------------------------------------------------------------------
DROP TABLE sim_days;
DROP TABLE sim_engines;
DROP TABLE sim_daily;
DROP TABLE sim_first_plan;

SELECT (SELECT COUNT(*) FROM sim_reading_ids) AS releves_generes,
       (SELECT COUNT(DISTINCT r.equipment_id) FROM meter_readings r JOIN sim_reading_ids i ON i.id = r.id) AS engins,
       (SELECT MIN(r.recorded_at) FROM meter_readings r JOIN sim_reading_ids i ON i.id = r.id) AS premier_releve,
       (SELECT MAX(r.recorded_at) FROM meter_readings r JOIN sim_reading_ids i ON i.id = r.id) AS dernier_releve;
