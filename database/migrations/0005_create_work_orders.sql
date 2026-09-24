-- Ordres de travail (OT) correctifs et préventifs
CREATE TABLE work_order_sequences (
    year         SMALLINT UNSIGNED NOT NULL,
    last_number  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_orders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(20)  NOT NULL,
    site_id             INT UNSIGNED NOT NULL,
    equipment_id        INT UNSIGNED NOT NULL,
    preventive_plan_id  INT UNSIGNED NULL,
    type                ENUM('corrective','preventive') NOT NULL DEFAULT 'corrective',
    priority            ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    status              ENUM('open','in_progress','waiting_part','done','cancelled') NOT NULL DEFAULT 'open',
    immobilizing        TINYINT(1)   NOT NULL DEFAULT 0,
    title               VARCHAR(150) NOT NULL,
    description         TEXT         NULL,
    resolution          TEXT         NULL,
    reported_by         INT UNSIGNED NULL,
    assigned_to         INT UNSIGNED NULL,
    reported_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date            DATE         NULL,
    started_at          DATETIME     NULL,
    completed_at        DATETIME     NULL,
    labor_minutes       INT UNSIGNED NULL,
    hour_meter          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_orders_reference (reference),
    KEY idx_wo_site_status (site_id, status),
    KEY idx_wo_equipment (equipment_id, status),
    KEY idx_wo_assigned (assigned_to, status),
    KEY idx_wo_plan (preventive_plan_id, status),
    CONSTRAINT fk_wo_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_wo_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_wo_plan FOREIGN KEY (preventive_plan_id) REFERENCES preventive_plans (id) ON DELETE SET NULL,
    CONSTRAINT fk_wo_reported_by FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_wo_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipment_status_history
    ADD CONSTRAINT fk_esh_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE SET NULL;
