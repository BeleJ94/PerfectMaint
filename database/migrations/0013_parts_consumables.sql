-- Pièces, pneus, lubrifiants : typologie, fabricant, durée de vie attendue, compatibilité engins,
-- appoints / vidanges de fluides par engin (suivi de consommation par site)
ALTER TABLE parts
    ADD COLUMN part_type            ENUM('part','tyre','lubricant','filter','consumable','fuel') NOT NULL DEFAULT 'part' AFTER name,
    ADD COLUMN manufacturer         VARCHAR(80)  NULL AFTER part_type,
    ADD COLUMN manufacturer_ref     VARCHAR(60)  NULL AFTER manufacturer,
    ADD COLUMN is_wear_part         TINYINT(1)   NOT NULL DEFAULT 0 AFTER manufacturer_ref,
    ADD COLUMN expected_life_hours  INT UNSIGNED NULL AFTER is_wear_part,
    ADD COLUMN expected_life_km     INT UNSIGNED NULL AFTER expected_life_hours,
    ADD KEY idx_parts_type (part_type, is_active);

-- Compatibilité : une pièce se monte sur des modèles d'engins (marque + modèle)
CREATE TABLE part_compatibility (
    part_id   INT UNSIGNED NOT NULL,
    model_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (part_id, model_id),
    KEY idx_pc_model (model_id),
    CONSTRAINT fk_pc_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_model FOREIGN KEY (model_id) REFERENCES equipment_models (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appoints et vidanges (huiles, graisses, liquides, carburant) : quantité + compteurs de l'engin
CREATE TABLE fluid_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id  INT UNSIGNED  NOT NULL,
    site_id       INT UNSIGNED  NOT NULL,
    part_id       INT UNSIGNED  NOT NULL,
    kind          ENUM('top_up','change') NOT NULL DEFAULT 'top_up',
    quantity      DECIMAL(10,2) NOT NULL,
    hour_meter    INT UNSIGNED  NULL,
    odometer_km   INT UNSIGNED  NULL,
    note          VARCHAR(255)  NULL,
    logged_by     INT UNSIGNED  NULL,
    logged_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fl_equipment (equipment_id, part_id, logged_at),
    KEY idx_fl_site_date (site_id, logged_at),
    CONSTRAINT fk_fl_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_fl_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_fl_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT fk_fl_user FOREIGN KEY (logged_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_fl_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_movements
    ADD COLUMN equipment_id INT UNSIGNED NULL AFTER work_order_id,
    ADD KEY idx_sm_equipment (equipment_id),
    ADD CONSTRAINT fk_sm_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE SET NULL;
