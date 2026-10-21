-- Compteur de suivi (heures moteur ou kilométrage) : défini par catégorie, forçable engin par engin.
-- Il pilote la saisie des index, la supervision (/meters, écran mural) et les périodicités préventives proposées.
ALTER TABLE equipment_categories
    ADD COLUMN meter_basis ENUM('hours','km') NOT NULL DEFAULT 'hours' AFTER family;

-- Engins de chantier (dont ADT) aux heures ; véhicules routiers, bus et camions au kilométrage.
UPDATE equipment_categories SET meter_basis = 'km'
 WHERE family IN ('transport', 'light') OR code IN ('BENNE', 'CITERNE');

-- NULL = compteur de la catégorie ; renseigné = choix propre à l'engin (ex. benne affectée en carrière).
ALTER TABLE equipment
    ADD COLUMN meter_basis ENUM('hours','km') NULL AFTER odometer_km;
