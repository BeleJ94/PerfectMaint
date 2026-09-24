-- Parc d'engins (chariots, transpalettes, nacelles…) et historique des états
CREATE TABLE equipment_categories (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code  VARCHAR(30)  NOT NULL,
    name  VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id            INT UNSIGNED NOT NULL,
    category_id        INT UNSIGNED NULL,
    code               VARCHAR(30)  NOT NULL,
    name               VARCHAR(150) NOT NULL,
    brand              VARCHAR(80)  NULL,
    model              VARCHAR(80)  NULL,
    serial_number      VARCHAR(80)  NULL,
    commissioned_at    DATE         NULL,
    hour_meter         INT UNSIGNED NULL,
    status             ENUM('available','breakdown','maintenance','waiting_part','immobilized') NOT NULL DEFAULT 'available',
    status_changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes              TEXT         NULL,
    is_archived        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_code (code),
    KEY idx_equipment_site_status (site_id, status),
    KEY idx_equipment_category (category_id),
    CONSTRAINT fk_equipment_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_equipment_category FOREIGN KEY (category_id) REFERENCES equipment_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment_status_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id  INT UNSIGNED NOT NULL,
    from_status   ENUM('available','breakdown','maintenance','waiting_part','immobilized') NULL,
    to_status     ENUM('available','breakdown','maintenance','waiting_part','immobilized') NOT NULL,
    reason        VARCHAR(255) NULL,
    work_order_id INT UNSIGNED NULL,
    changed_by    INT UNSIGNED NULL,
    changed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_esh_equipment (equipment_id, changed_at),
    CONSTRAINT fk_esh_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_esh_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
