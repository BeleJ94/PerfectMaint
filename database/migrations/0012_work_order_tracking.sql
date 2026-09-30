-- OT enrichis : kilométrage, cause de panne, opérateur au moment de la panne, intervenants, remarques ;
-- compteurs relevés à chaque consommation de pièce (suivi d'usure)
ALTER TABLE work_orders
    ADD COLUMN odometer_km    INT UNSIGNED NULL AFTER hour_meter,
    ADD COLUMN failure_cause  ENUM('wear','misuse','maintenance_lapse','accident','component_failure','external','unknown') NULL AFTER resolution,
    ADD COLUMN operator_id    INT UNSIGNED NULL AFTER assigned_to,
    ADD COLUMN operator_name  VARCHAR(170) NULL AFTER operator_id,
    ADD KEY idx_wo_cause (failure_cause, status),
    ADD KEY idx_wo_operator (operator_id),
    ADD CONSTRAINT fk_wo_operator FOREIGN KEY (operator_id) REFERENCES personnel (id) ON DELETE SET NULL;

CREATE TABLE work_order_technicians (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_id  INT UNSIGNED NOT NULL,
    personnel_id   INT UNSIGNED NOT NULL,
    minutes        INT UNSIGNED NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wot_wo_person (work_order_id, personnel_id),
    KEY idx_wot_personnel (personnel_id),
    CONSTRAINT fk_wot_wo FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_wot_personnel FOREIGN KEY (personnel_id) REFERENCES personnel (id),
    CONSTRAINT fk_wot_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_order_comments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_id  INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NULL,
    body           TEXT         NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_woc_wo (work_order_id, created_at),
    CONSTRAINT fk_woc_wo FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_woc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_order_parts
    ADD COLUMN hour_meter   INT UNSIGNED NULL AFTER unit_cost,
    ADD COLUMN odometer_km  INT UNSIGNED NULL AFTER hour_meter;
