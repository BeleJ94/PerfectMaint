-- Journal d'activité : qui, quoi, quand, avant/après (append-only)
CREATE TABLE activity_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    user_name    VARCHAR(170) NULL,
    site_id      INT UNSIGNED NULL,
    entity_type  VARCHAR(40)  NOT NULL,
    entity_id    INT UNSIGNED NULL,
    action       VARCHAR(40)  NOT NULL,
    summary      VARCHAR(255) NULL,
    before_data  LONGTEXT     NULL CHECK (before_data IS NULL OR JSON_VALID(before_data)),
    after_data   LONGTEXT     NULL CHECK (after_data IS NULL OR JSON_VALID(after_data)),
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_al_entity (entity_type, entity_id),
    KEY idx_al_site_date (site_id, created_at),
    KEY idx_al_user_date (user_id, created_at),
    CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_al_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
