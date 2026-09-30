-- Pièces détachées, stock par site, mouvements et consommation sur OT
CREATE TABLE parts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference   VARCHAR(40)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    unit        VARCHAR(10)  NOT NULL DEFAULT 'u',
    unit_cost   DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parts_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE part_stocks (
    part_id       INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    quantity      DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_quantity  DECIMAL(10,2) NOT NULL DEFAULT 0,
    location      VARCHAR(60)  NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (part_id, site_id),
    KEY idx_part_stocks_site (site_id),
    CONSTRAINT fk_ps_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT chk_ps_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id        INT UNSIGNED NOT NULL,
    site_id        INT UNSIGNED NOT NULL,
    work_order_id  INT UNSIGNED NULL,
    type           ENUM('in','out','adjust') NOT NULL,
    quantity       DECIMAL(10,2) NOT NULL,
    stock_after    DECIMAL(10,2) NOT NULL,
    comment        VARCHAR(255) NULL,
    user_id        INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sm_part_site (part_id, site_id, created_at),
    KEY idx_sm_work_order (work_order_id),
    CONSTRAINT fk_sm_part FOREIGN KEY (part_id) REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT fk_sm_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_sm_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_order_parts (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_id  INT UNSIGNED NOT NULL,
    part_id        INT UNSIGNED NOT NULL,
    quantity       DECIMAL(10,2) NOT NULL,
    unit_cost      DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wop_work_order (work_order_id),
    CONSTRAINT fk_wop_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_wop_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT fk_wop_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
