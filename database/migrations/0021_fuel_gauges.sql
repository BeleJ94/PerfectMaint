-- Niveau de carburant lu sur l'indicateur du véhicule (et non plus estimé en %) :
-- types d'indicateur (aiguille à N tirets, barres à N segments, affichage en %), réglés sur le modèle
-- avec la capacité du réservoir, forçables engin par engin. L'encodeur saisit le tiret où se trouve
-- l'aiguille (demi-tiret accepté) ; le système en déduit les litres et le pourcentage.
CREATE TABLE fuel_gauges (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)  NOT NULL,
    kind        ENUM('marks','percent') NOT NULL DEFAULT 'marks',
    marks       TINYINT UNSIGNED NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fuel_gauges_name (name),
    CONSTRAINT chk_fg_marks CHECK ((kind = 'percent' AND marks IS NULL) OR (kind = 'marks' AND marks BETWEEN 1 AND 20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indicateurs courants : à compléter ou ajuster dans le référentiel après le relevé terrain.
INSERT INTO fuel_gauges (name, kind, marks) VALUES
    ('Aiguille 2 graduations (E · ½ · F)', 'marks', 2),
    ('Aiguille 4 graduations (E · ¼ · ½ · ¾ · F)', 'marks', 4),
    ('Aiguille 8 graduations', 'marks', 8),
    ('Barres 8 segments', 'marks', 8),
    ('Barres 10 segments', 'marks', 10),
    ('Affichage numérique (%)', 'percent', NULL);

ALTER TABLE equipment_models
    ADD COLUMN fuel_gauge_id    INT UNSIGNED      NULL AFTER category_id,
    ADD COLUMN tank_capacity_l  SMALLINT UNSIGNED NULL AFTER fuel_gauge_id,
    ADD CONSTRAINT fk_em_fuel_gauge FOREIGN KEY (fuel_gauge_id) REFERENCES fuel_gauges (id) ON DELETE SET NULL;

-- NULL = réglage du modèle ; renseigné = propre à l'engin (réservoir additionnel, indicateur remplacé…).
ALTER TABLE equipment
    ADD COLUMN fuel_gauge_id    INT UNSIGNED      NULL AFTER fuel_level,
    ADD COLUMN tank_capacity_l  SMALLINT UNSIGNED NULL AFTER fuel_gauge_id,
    ADD COLUMN fuel_liters      DECIMAL(7,1)      NULL AFTER tank_capacity_l,
    ADD CONSTRAINT fk_equipment_fuel_gauge FOREIGN KEY (fuel_gauge_id) REFERENCES fuel_gauges (id) ON DELETE SET NULL;

-- Détail de la lecture conservé : tiret saisi et nombre de graduations au moment du relevé
-- (l'historique reste juste si l'indicateur est reconfiguré ensuite), litres déduits.
ALTER TABLE meter_readings
    ADD COLUMN fuel_mark    DECIMAL(4,1)     NULL AFTER fuel_level,
    ADD COLUMN fuel_marks   TINYINT UNSIGNED NULL AFTER fuel_mark,
    ADD COLUMN fuel_liters  DECIMAL(7,1)     NULL AFTER fuel_marks;
