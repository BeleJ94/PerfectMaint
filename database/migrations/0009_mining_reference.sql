-- Référentiel minier : type d'activité des sites, familles de catégories, marques et modèles d'engins
ALTER TABLE sites
    ADD COLUMN activity ENUM('office','earthmoving','transport') NOT NULL DEFAULT 'earthmoving' AFTER name;

ALTER TABLE equipment_categories
    ADD COLUMN family ENUM('earthmoving','haulage','transport','lifting','light','other') NOT NULL DEFAULT 'other' AFTER name,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER family;

-- Catégories de manutention d'entrepôt conservées pour l'historique, masquées des listes
UPDATE equipment_categories SET is_active = 0;

INSERT INTO equipment_categories (code, name, family) VALUES
    ('PELLE_CHENILLE', 'Pelle hydraulique sur chenilles', 'earthmoving'),
    ('BULLDOZER', 'Bulldozer', 'earthmoving'),
    ('CHARGEUSE', 'Chargeuse', 'earthmoving'),
    ('NIVELEUSE', 'Niveleuse', 'earthmoving'),
    ('COMPACTEUR', 'Compacteur', 'earthmoving'),
    ('ADT', 'Tombereau articulé (ADT)', 'haulage'),
    ('BENNE', 'Camion benne', 'haulage'),
    ('CITERNE', 'Camion citerne', 'haulage'),
    ('BUS', 'Bus', 'transport'),
    ('MINIBUS', 'Minibus', 'transport'),
    ('TELESCOPIQUE', 'Chariot télescopique', 'lifting'),
    ('VL', 'Véhicule léger', 'light'),
    ('GROUPE', 'Groupe électrogène', 'other');

CREATE TABLE equipment_brands (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(60)  NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment_models (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    brand_id     INT UNSIGNED NOT NULL,
    category_id  INT UNSIGNED NULL,
    name         VARCHAR(80)  NOT NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_models_brand_name (brand_id, name),
    KEY idx_equipment_models_category (category_id),
    CONSTRAINT fk_em_brand FOREIGN KEY (brand_id) REFERENCES equipment_brands (id),
    CONSTRAINT fk_em_category FOREIGN KEY (category_id) REFERENCES equipment_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipment
    ADD COLUMN brand_id INT UNSIGNED NULL AFTER category_id,
    ADD COLUMN model_id INT UNSIGNED NULL AFTER brand_id,
    ADD KEY idx_equipment_brand (brand_id),
    ADD KEY idx_equipment_model (model_id),
    ADD CONSTRAINT fk_equipment_brand FOREIGN KEY (brand_id) REFERENCES equipment_brands (id),
    ADD CONSTRAINT fk_equipment_model FOREIGN KEY (model_id) REFERENCES equipment_models (id);

INSERT INTO equipment_brands (name) VALUES
    ('Komatsu'), ('Caterpillar'), ('Volvo'), ('HOWO'), ('XCMG'), ('Bell'), ('Tata'),
    ('Yutong'), ('CHL'), ('Maxima'), ('Manitou'), ('JCB'), ('Toyota');

INSERT INTO equipment_models (brand_id, category_id, name)
SELECT b.id, c.id, m.model
  FROM (
      SELECT 'Komatsu' AS brand, 'PELLE_CHENILLE' AS cat, 'PC200-8' AS model
      UNION ALL SELECT 'Komatsu', 'PELLE_CHENILLE', 'PC350LC-8'
      UNION ALL SELECT 'Komatsu', 'BULLDOZER', 'D65EX-16'
      UNION ALL SELECT 'Komatsu', 'CHARGEUSE', 'WA470-6'
      UNION ALL SELECT 'Komatsu', 'ADT', 'HM400-3'
      UNION ALL SELECT 'Caterpillar', 'PELLE_CHENILLE', '320D'
      UNION ALL SELECT 'Caterpillar', 'PELLE_CHENILLE', '336D2'
      UNION ALL SELECT 'Caterpillar', 'BULLDOZER', 'D8R'
      UNION ALL SELECT 'Caterpillar', 'CHARGEUSE', '950H'
      UNION ALL SELECT 'Caterpillar', 'NIVELEUSE', '140K'
      UNION ALL SELECT 'Caterpillar', 'ADT', '740B'
      UNION ALL SELECT 'Caterpillar', 'COMPACTEUR', 'CS74'
      UNION ALL SELECT 'Volvo', 'PELLE_CHENILLE', 'EC380DL'
      UNION ALL SELECT 'Volvo', 'ADT', 'A40G'
      UNION ALL SELECT 'Volvo', 'CHARGEUSE', 'L120H'
      UNION ALL SELECT 'Volvo', 'BENNE', 'FMX 440 6x4'
      UNION ALL SELECT 'HOWO', 'BENNE', 'ZZ3257 371'
      UNION ALL SELECT 'HOWO', 'CITERNE', 'ZZ1257 Citerne'
      UNION ALL SELECT 'XCMG', 'PELLE_CHENILLE', 'XE370CA'
      UNION ALL SELECT 'XCMG', 'CHARGEUSE', 'LW500FN'
      UNION ALL SELECT 'XCMG', 'NIVELEUSE', 'GR215'
      UNION ALL SELECT 'XCMG', 'COMPACTEUR', 'XS223J'
      UNION ALL SELECT 'Bell', 'ADT', 'B40E'
      UNION ALL SELECT 'Bell', 'ADT', 'B30E'
      UNION ALL SELECT 'Tata', 'BUS', 'LPO 1618'
      UNION ALL SELECT 'Tata', 'BENNE', 'Prima 4038.K'
      UNION ALL SELECT 'Yutong', 'BUS', 'ZK6122H9'
      UNION ALL SELECT 'Yutong', 'MINIBUS', 'ZK6729D'
      UNION ALL SELECT 'CHL', 'BENNE', 'H7 6x4'
      UNION ALL SELECT 'Manitou', 'TELESCOPIQUE', 'MT 1840'
      UNION ALL SELECT 'Manitou', 'TELESCOPIQUE', 'MT 732'
      UNION ALL SELECT 'JCB', 'TELESCOPIQUE', '540-170'
      UNION ALL SELECT 'JCB', 'CHARGEUSE', '3CX'
      UNION ALL SELECT 'Toyota', 'VL', 'Land Cruiser 79'
      UNION ALL SELECT 'Toyota', 'VL', 'Hilux'
      UNION ALL SELECT 'Toyota', 'MINIBUS', 'Coaster'
  ) m
  JOIN equipment_brands b ON b.name = m.brand
  JOIN equipment_categories c ON c.code = m.cat;

-- Sites d'exploitation Perfect Logistics (ignorés s'ils existent déjà)
INSERT IGNORE INTO sites (code, name, activity) VALUES
    ('BUREAU', 'Bureau', 'office'),
    ('CHEMAF', 'CHEMAF', 'earthmoving'),
    ('KAMAO', 'KAMAO', 'earthmoving'),
    ('METALKOL', 'METALKOL', 'earthmoving'),
    ('KINGAKILA', 'KINGAKILA', 'transport'),
    ('SICOMINES', 'SICOMINES', 'transport');
