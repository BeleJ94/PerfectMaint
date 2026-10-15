-- Documents administratifs des engins à échéance : assurance, contrôle technique, vignette, stationnement…
-- Un type = une ligne de document_types (ajout de nouveaux types sans changement de schéma).
-- Périmètre de sites : celui de l'engin (jointure), le document suit l'engin s'il change de site.
-- Chaque renouvellement crée une nouvelle ligne : la plus récente par type est le document en vigueur.
CREATE TABLE document_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    alert_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO document_types (code, name, alert_days, sort_order) VALUES
    ('insurance', 'Assurance', 30, 10),
    ('inspection', 'Contrôle technique', 30, 20),
    ('vignette', 'Vignette', 30, 30),
    ('parking', 'Stationnement', 30, 40);

CREATE TABLE equipment_documents (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id      INT UNSIGNED NOT NULL,
    document_type_id  INT UNSIGNED NOT NULL,
    issuer            VARCHAR(120) NULL,
    reference         VARCHAR(80)  NULL,
    issued_at         DATE         NULL,
    expires_at        DATE         NULL,
    note              VARCHAR(255) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ed_equipment_type (equipment_id, document_type_id, expires_at),
    KEY idx_ed_expires (expires_at),
    CONSTRAINT fk_ed_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_ed_type FOREIGN KEY (document_type_id) REFERENCES document_types (id),
    CONSTRAINT fk_ed_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
