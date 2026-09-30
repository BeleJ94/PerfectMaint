-- Fiche engin enrichie : identification, compteurs, carburant, contrat / affectation, photos, relevés
ALTER TABLE equipment
    ADD COLUMN chassis_number    VARCHAR(60)  NULL AFTER serial_number,
    ADD COLUMN plate_number      VARCHAR(30)  NULL AFTER chassis_number,
    ADD COLUMN odometer_km       INT UNSIGNED NULL AFTER hour_meter,
    ADD COLUMN fuel_level        TINYINT UNSIGNED NULL AFTER odometer_km,
    ADD COLUMN meter_updated_at  DATETIME     NULL AFTER fuel_level,
    ADD COLUMN ownership         ENUM('owned','po','contract','rental') NOT NULL DEFAULT 'owned' AFTER meter_updated_at,
    ADD COLUMN contract_ref      VARCHAR(60)  NULL AFTER ownership,
    ADD COLUMN contract_party    VARCHAR(120) NULL AFTER contract_ref,
    ADD COLUMN contract_start    DATE         NULL AFTER contract_party,
    ADD COLUMN contract_end      DATE         NULL AFTER contract_start,
    ADD COLUMN assignment        VARCHAR(150) NULL AFTER contract_end,
    ADD CONSTRAINT chk_equipment_fuel CHECK (fuel_level IS NULL OR fuel_level <= 100),
    ADD KEY idx_equipment_plate (plate_number),
    ADD KEY idx_equipment_contract_end (contract_end);

CREATE TABLE equipment_photos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id   INT UNSIGNED NOT NULL,
    file_name      VARCHAR(80)  NOT NULL,
    original_name  VARCHAR(190) NULL,
    mime           VARCHAR(40)  NOT NULL,
    size_bytes     INT UNSIGNED NOT NULL,
    width          SMALLINT UNSIGNED NULL,
    height         SMALLINT UNSIGNED NULL,
    caption        VARCHAR(150) NULL,
    is_primary     TINYINT(1)   NOT NULL DEFAULT 0,
    uploaded_by    INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_photos_file (file_name),
    KEY idx_equipment_photos_equipment (equipment_id, is_primary),
    CONSTRAINT fk_ep_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_ep_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relevés de compteurs (heures, km) et de carburant : base du suivi d'usure et de consommation
CREATE TABLE meter_readings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id  INT UNSIGNED NOT NULL,
    hour_meter    INT UNSIGNED NULL,
    odometer_km   INT UNSIGNED NULL,
    fuel_level    TINYINT UNSIGNED NULL,
    note          VARCHAR(255) NULL,
    recorded_by   INT UNSIGNED NULL,
    recorded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mr_equipment_date (equipment_id, recorded_at),
    CONSTRAINT fk_mr_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_mr_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_mr_fuel CHECK (fuel_level IS NULL OR fuel_level <= 100),
    CONSTRAINT chk_mr_any CHECK (hour_meter IS NOT NULL OR odometer_km IS NOT NULL OR fuel_level IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
