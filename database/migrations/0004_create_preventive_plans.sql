-- Plans de maintenance préventive (périodicité en jours et/ou heures moteur)
CREATE TABLE preventive_plans (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id          INT UNSIGNED NOT NULL,
    equipment_id     INT UNSIGNED NOT NULL,
    title            VARCHAR(150) NOT NULL,
    instructions     TEXT         NULL,
    interval_days    SMALLINT UNSIGNED NULL,
    interval_hours   INT UNSIGNED NULL,
    last_done_at     DATE         NULL,
    last_done_hours  INT UNSIGNED NULL,
    next_due_date    DATE         NULL,
    next_due_hours   INT UNSIGNED NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pp_site_due (site_id, is_active, next_due_date),
    KEY idx_pp_equipment (equipment_id),
    CONSTRAINT fk_pp_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_pp_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT chk_pp_interval CHECK (COALESCE(interval_days, 0) > 0 OR COALESCE(interval_hours, 0) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
