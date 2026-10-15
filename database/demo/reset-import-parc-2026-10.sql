-- =====================================================================================================
-- PerfectMaint — RÉINITIALISATION et import du parc réel Perfect Logistics (charrois octobre 2026)
--
-- Vide toutes les données d'exploitation (engins, OT, préventifs, pièces, stocks, personnel, équipes,
-- relevés d'index, photos, journal) et tous les utilisateurs SAUF les administrateurs.
-- Conserve : schéma, migrations, paramètres (SMTP…), catégories, marques et modèles du référentiel.
-- Importe : 5 sites et 108 engins (source : docs/import/Charroi-Perfect-Logistics-consolide.xlsx).
-- Codes engins : n° d'ordre du site (plaque à défaut), suffixe -B en cas de doublon.
-- Assurances : importées comme documents à échéance (migration 0017 requise : php bin/migrate.php AVANT ce script).
-- Index (heures / km) : repartent de zéro — aucun relevé ; le premier relevé saisi devient la référence.
--
-- IRRÉVERSIBLE : faire une sauvegarde AVANT exécution :
--   mysqldump --single-transaction --routines <base> > perfectmaint-avant-reset.sql
-- Exécution (encodage UTF-8 obligatoire, sinon les tirets « — » et flèches deviennent « ? ») :
--   php bin/migrate.php
--   mysql --default-character-set=utf8mb4 <base> < database/demo/reset-import-parc-2026-10.sql
-- Après exécution, vider aussi le dossier des photos : storage/uploads/equipment/
-- =====================================================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS sim_reading_ids, sim_backup_readings, sim_backup_plans, sim_backup_equipment,
                     sim_days, sim_engines, sim_daily, sim_first_plan;

TRUNCATE TABLE activity_log;
TRUNCATE TABLE fluid_logs;
TRUNCATE TABLE part_compatibility;
TRUNCATE TABLE work_order_comments;
TRUNCATE TABLE work_order_technicians;
TRUNCATE TABLE work_order_parts;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE part_stocks;
TRUNCATE TABLE parts;
TRUNCATE TABLE work_orders;
TRUNCATE TABLE work_order_sequences;
TRUNCATE TABLE preventive_plans;
TRUNCATE TABLE equipment_assignments;
TRUNCATE TABLE personnel;
TRUNCATE TABLE teams;
TRUNCATE TABLE meter_readings;
TRUNCATE TABLE equipment_documents;
TRUNCATE TABLE equipment_photos;
TRUNCATE TABLE equipment_status_history;
TRUNCATE TABLE equipment;
TRUNCATE TABLE user_sites;
TRUNCATE TABLE sites;

DELETE FROM users WHERE role <> 'admin';
UPDATE users SET all_sites = 1, failed_logins = 0, locked_until = NULL;

SET FOREIGN_KEY_CHECKS = 1;

START TRANSACTION;

INSERT INTO sites (code, name, activity) VALUES
    ('CHEMAF', 'CHEMAF', 'earthmoving'),
    ('MUMI', 'MUMI', 'earthmoving'),
    ('KAMOA', 'KAMOA', 'earthmoving'),
    ('KINGAKILA', 'KINGAKILA', 'transport'),
    ('SICOMINES', 'SICOMINES', 'transport');

-- Catégories absentes du référentiel
INSERT IGNORE INTO equipment_categories (code, name, family) VALUES
    ('CRIBLE', 'Crible', 'earthmoving'),
    ('CHARIOT', 'Chariot élévateur', 'lifting');
UPDATE equipment_categories SET is_active = 1
 WHERE code IN ('VL', 'CHARGEUSE', 'NIVELEUSE', 'COMPACTEUR', 'BUS', 'CITERNE', 'BENNE', 'ADT', 'PELLE_CHENILLE', 'CRIBLE', 'BULLDOZER', 'GROUPE', 'CHARIOT');

-- Marques absentes du référentiel
INSERT IGNORE INTO equipment_brands (name) VALUES
    ('Toyota'),
    ('Komatsu'),
    ('Caterpillar'),
    ('Volvo'),
    ('HOWO'),
    ('XCMG'),
    ('Bell'),
    ('Tata'),
    ('Yutong'),
    ('CHL'),
    ('Mahindra'),
    ('FAW'),
    ('Shantui'),
    ('Gen-Light'),
    ('Bomag'),
    ('Striker'),
    ('Warrior'),
    ('Finlay'),
    ('Terex');

CREATE TEMPORARY TABLE import_equipment (
    site VARCHAR(20), cat VARCHAR(30), brand VARCHAR(60), code VARCHAR(30), name VARCHAR(150),
    brand_label VARCHAR(80), model VARCHAR(80), serial_number VARCHAR(80), chassis_number VARCHAR(60),
    plate_number VARCHAR(30), status VARCHAR(20), notes TEXT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO import_equipment VALUES
    ('CHEMAF', 'VL', 'Toyota', '076', 'HILUX TOYOTA 076', 'Toyota', NULL, NULL, 'AHTKK8CD900684789', '8128AB14', 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'VL', 'Mahindra', '052', 'MAHINDRA 052', 'Mahindra', NULL, NULL, 'MA1RD4VP2038654', '6941AB14', 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : Châssis tronqué (15 car.)
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'CHARGEUSE', 'XCMG', '092', 'LOADER XCMG 092', 'XCMG', NULL, NULL, 'XUG0050GJPCB00056', NULL, 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'NIVELEUSE', 'Bell', '032', 'GRADER BELL 032', 'Bell', NULL, NULL, '1DW770GXKBC639971', '6902AA14', 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'COMPACTEUR', 'Bomag', 'C04', 'ROLLER BOMAG C04', 'Bomag', NULL, NULL, '961582991150', NULL, 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'BUS', 'Tata', '203', 'BUS TATA 203', 'Tata', NULL, NULL, 'MAT462271P5L00126', '6510AB14', 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = SICOMINES 203, plaque = SICOMINES n°10
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'CITERNE', 'HOWO', '017', 'WATER BOWSER HOWO 017', 'HOWO', NULL, NULL, 'LZZ5BLSF9GN192244', '4397AM05', 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'CITERNE', 'HOWO', '016', 'WATER BOWSER HOWO 016', 'HOWO', NULL, NULL, 'LZZ5BLSF1FN079600', '3567AK05', 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('CHEMAF', 'CITERNE', 'HOWO', '105', 'FUEL BOWSER HOWO 105', 'HOWO', NULL, NULL, 'LZZ5BLSF2RN279972', '6465AA24', 'available', 'Statut non renseigné dans le fichier d''origine
Source : CHAROITS PERFECT LOGISTICS CHEMAF.pdf'),
    ('MUMI', 'VL', 'Toyota', '112', 'HILUX TOYOTA 112', 'Toyota', NULL, NULL, 'AHTKK8CD600685527', '7918AB14', 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'VL', 'Mahindra', '050', 'PICK UP MAHINDRA 050', 'Mahindra', NULL, NULL, 'MA1RD4VK2P2C32957', '7113AB14', 'breakdown', 'À vérifier : DOUBLON : repris par erreur en SICOMINES n°18
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'VL', 'Toyota', '106', 'LAND CRUISER TOYOTA 106', 'Toyota', NULL, NULL, 'JTERB71J40075329', '7239AN05', 'available', 'À vérifier : Châssis tronqué (16 car.)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'VL', 'Toyota', '037', 'LAND CRUISER TOYOTA 037', 'Toyota', NULL, NULL, 'JTERB71J000062222', '4400AA14', 'available', 'À vérifier : Châssis corrigé (O → 0)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'VL', 'Toyota', '107', 'HILUX TOYOTA 107', 'Toyota', NULL, NULL, 'AHTDK9CD600975538', '5905AB14', 'available', 'À vérifier : Pas d''assurance
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'VL', 'Toyota', '085', 'HILUX TOYOTA 085', 'Toyota', NULL, NULL, 'AHTDK9CD400975554', '5904AB14', 'available', 'Assurance sans échéance : assureur ? — police 26-005-0000019095
À vérifier : Police sans échéance
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'FAW', '078', 'CAMION FAW 078', 'FAW', NULL, NULL, 'LFNFVXMU3MADA2748', '3072AB04', 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '090', 'CAMION HOWO 090', 'HOWO', NULL, NULL, 'LZZ5EXSE3JN389827', '2468AB14', 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '068', 'CAMION HOWO 068', 'HOWO', NULL, NULL, 'LZZ5ELSD8MN887509', '0153AC14', 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '057', 'CAMION HOWO 057', 'HOWO', NULL, NULL, 'LZZ5ELSD9MN772790', '26667AB', 'available', 'À vérifier : Plaque mal formée (2667AB14 ?)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '058', 'CAMION HOWO 058', 'HOWO', NULL, NULL, 'LZZ5ELSD2MN772792', '2669AB14', 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '018', 'CAMION HOWO 018', 'HOWO', NULL, NULL, 'LZZ5BLNF2HW232395', '2414AM05', 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : Hors numérotation, statut absent
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BENNE', 'HOWO', '024', 'CAMION HOWO 024', 'HOWO', NULL, NULL, 'LZZ5BLNF5JD407689', '8816AA14', 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : Hors numérotation, statut absent
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Komatsu', '021', 'ADT KOMATSU 021', 'Komatsu', NULL, '9670', 'KMTHM013CRD009670', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Komatsu', '022', 'ADT KOMATSU 022', 'Komatsu', NULL, '9669', 'KMTHM013PRD009669', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Komatsu', '025', 'ADT KOMATSU 025', 'Komatsu', NULL, '9504', 'KMTHM013EPD009504', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Komatsu', '06', 'ADT KOMATSU 06', 'Komatsu', NULL, '9673', 'KMTHM013KRD009673', NULL, 'available', 'À vérifier : N° proche de KAMOA 006
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Komatsu', '03', 'ADT KOMATSU 03', 'Komatsu', NULL, '9165', 'KMTHM013KND009165', NULL, 'breakdown', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'ADT', 'Caterpillar', '05', 'ADT CAT 05', 'Caterpillar', NULL, NULL, 'CAT00745A3F601060', NULL, 'available', 'À vérifier : N° en double (générateur 05)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '027', 'ROCK BREAKER KOMATSU 027', 'Komatsu', NULL, '2397', 'KMTPC247TLC082397', 'IT2397', 'breakdown', 'À vérifier : Châssis corrigé (O → 0)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '038', 'EXCAVATOR KOMATSU 038', 'Komatsu', NULL, '1617', 'KMTPC247CJ081617', 'IT1617', 'breakdown', 'À vérifier : Châssis corrigé (O → 0)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '081', 'EXCAVATOR KOMATSU 081', 'Komatsu', NULL, NULL, 'KMTPC284ELC100121', NULL, 'breakdown', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '094', 'EXCAVATOR KOMATSU 094', 'Komatsu', NULL, 'IT3565', 'KMTPC247ENC083565', NULL, 'breakdown', 'À vérifier : Châssis corrigé (O → 0)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Volvo', '084', 'EXCAVATOR VOLVO 084', 'Volvo', NULL, NULL, 'VCEC250DK00280513', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '097', 'EXCAVATOR KOMATSU 097', 'Komatsu', NULL, NULL, 'KMTPC284CLC00122', NULL, 'available', 'À vérifier : Châssis tronqué (16 car.)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Komatsu', '109', 'EXCAVATOR KOMATSU 109', 'Komatsu', NULL, NULL, 'P4BI0014', NULL, 'available', 'À vérifier : Châssis incomplet
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Caterpillar', '900', 'EXCAVATOR CAT 900', 'Caterpillar', NULL, NULL, 'CAT0390FKGAY20180', NULL, 'immobilized', 'Statut d''origine : Stand-by
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'PELLE_CHENILLE', 'Caterpillar', '113', 'EXCAVATOR CAT 113', 'Caterpillar', NULL, NULL, 'CAT00395JSGD40070', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CRIBLE', 'Striker', '07', 'SCREEN STRIKER 07', 'Striker', NULL, '308818', NULL, NULL, 'breakdown', 'À vérifier : N° en double (compacteur 07)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CRIBLE', 'Warrior', '08', 'SCREEN WARRIOR 08', 'Warrior', NULL, NULL, NULL, NULL, 'breakdown', 'À vérifier : Aucun identifiant
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CRIBLE', 'Finlay', '09', 'SCREEN FINLAY 09', 'Finlay', NULL, NULL, NULL, NULL, 'immobilized', 'Statut d''origine : Stand-by
À vérifier : Aucun identifiant
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CRIBLE', 'Terex', '010', 'SCREEN TEREX 010', 'Terex', NULL, NULL, NULL, NULL, 'immobilized', 'Statut d''origine : Stand-by
À vérifier : Aucun identifiant
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARGEUSE', 'XCMG', '099', 'LOADER ZL50GN 099', 'XCMG', 'ZL50GN', NULL, 'XUG0050GHMCB24196', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BULLDOZER', 'Komatsu', '036', 'DOZER KOMATSU 036', 'Komatsu', NULL, 'D155A-6', 'KMT0D105PJC087532', NULL, 'available', 'À vérifier : Assurance expirée (ou coquille 2027) ; même police que 933
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BULLDOZER', 'Caterpillar', '933', 'DOZER CAT 933', 'Caterpillar', NULL, '48W48431', 'CATCS583ADAJ00396', NULL, 'available', 'À vérifier : Assurance expirée (ou coquille 2027) ; châssis double WDM03287/…
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BULLDOZER', 'Shantui', '907', 'DOZER SHANTUI 907', 'Shantui', NULL, NULL, 'CHSD32AALN1007950', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'GROUPE', 'Gen-Light', '02', 'GENERATOR GEN-LIGHT 02', 'Gen-Light', NULL, '150230325', NULL, NULL, 'available', 'À vérifier : N° en double (compacteur 02)
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'GROUPE', 'Gen-Light', '05-B', 'GENERATOR GEN-LIGHT 05', 'Gen-Light', NULL, NULL, NULL, NULL, 'breakdown', 'À vérifier : N° en double (ADT 05) ; aucun identifiant
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'COMPACTEUR', 'Bomag', '07-B', 'COMPACTOR BOMAG 07', 'Bomag', NULL, NULL, '101582432887', NULL, 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : N° en double (crible 07) ; statut absent
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'COMPACTEUR', 'Bomag', '02-B', 'COMPACTOR BOMAG 02', 'Bomag', NULL, NULL, '961582991067', NULL, 'available', 'Statut non renseigné dans le fichier d''origine
À vérifier : N° en double (générateur 02) ; statut absent
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '400', 'FORKLIFT CHL 400', 'CHL', NULL, NULL, '010302S5309', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '401', 'FORKLIFT CHL 401', 'CHL', NULL, NULL, '010302S5308', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '402', 'FORKLIFT CHL 402', 'CHL', NULL, NULL, '010302R9353', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '403', 'FORKLIFT CHL 403', 'CHL', NULL, NULL, '010302R9352', NULL, 'available', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '404', 'FORKLIFT CHL 404', 'CHL', NULL, NULL, '010302R9355', NULL, 'immobilized', 'Statut d''origine : Stand-by
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '405', 'FORKLIFT CHL 405', 'CHL', NULL, NULL, '010302E9356', NULL, 'breakdown', 'Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '406', 'FORKLIFT CHL 406', 'CHL', NULL, NULL, '010302T8967', NULL, 'immobilized', 'Statut d''origine : Stand-by
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'CHARIOT', 'CHL', '407', 'FORKLIFT CHL 407', 'CHL', NULL, NULL, '010302R9357', NULL, 'immobilized', 'Statut d''origine : Stand-by
À vérifier : N° de police tronqué
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BUS', 'Tata', '222', 'BUS MARCOPOLO TATA 222', 'Tata', NULL, NULL, 'MAT494051R8L0095', 'IT0095', 'available', 'À vérifier : Châssis tronqué ; plaque = n° interne
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BUS', 'Tata', '216', 'BUS MARCOPOLO TATA 216', 'Tata', NULL, NULL, 'MAT494051P8L00500', '9096AB14', 'available', 'À vérifier : ASSURANCE EXPIRÉE
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('MUMI', 'BUS', 'Tata', '217', 'BUS MARCOPOLO TATA 217', 'Tata', NULL, NULL, 'MAT494051P7L00710', '9445AB14', 'available', 'À vérifier : ASSURANCE EXPIRÉE
Source : CHARROI ... MUMI SEPTEMBRE 2026 (PL-084)'),
    ('KAMOA', 'VL', 'Toyota', '030', 'LAND CRUISER TOYOTA 030', 'Toyota', NULL, NULL, 'JTEEB71J90F019123', '5982AB04', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '103', 'LAND CRUISER TOYOTA 103', 'Toyota', NULL, NULL, 'JTENB712NB0335', '2003AT05', 'available', 'À vérifier : Châssis tronqué (14 car.)
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '006', 'LAND CRUISER TOYOTA 006', 'Toyota', NULL, NULL, 'JTERB71J400064121', '3717AN05', 'breakdown', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '005', 'LAND CRUISER TOYOTA 005', 'Toyota', NULL, NULL, 'JTFBB71JXE4201558', '1761AJ05', 'available', 'À vérifier : N° proche de MUMI 05
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '048', 'HILUX TOYOTA 048', 'Toyota', NULL, NULL, 'ANTDK9CD200977125', '7060AB14', 'available', 'À vérifier : Châssis : ANT… probablement AHT…
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '077', 'HILUX TOYOTA 077', 'Toyota', NULL, NULL, 'AHTKK8CD100685631', '7727AB14', 'available', 'À vérifier : Pas d''assurance
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '101', 'HILUX TOYOTA 101', 'Toyota', NULL, NULL, 'AHTDK9CDX00977308', '7062AB14', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'VL', 'Toyota', '102', 'HILUX TOYOTA 102', 'Toyota', NULL, NULL, 'AHTDK9CD200977397', '9801AB14', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BUS', 'Tata', '1', 'BUS TATA 1', 'Tata', NULL, NULL, 'MAT462271HDL00600', '1361AN05', 'available', 'À vérifier : DOUBLON : = SICOMINES n°17
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BUS', 'Tata', '201', 'BUS TATA 201', 'Tata', NULL, NULL, '462271EO1097', '2940AF19', 'available', 'À vérifier : DOUBLON : = SICOMINES n°11 ; châssis incomplet
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '043', 'HOWO SINOTRUCK 043', 'HOWO', NULL, NULL, 'LZZ5EXSC0JN436133', '3290AC22', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '045', 'HOWO SINOTRUCK 045', 'HOWO', NULL, NULL, 'LZZ5EXSE7JN389815', '3762AZ01', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '066', 'HOWO SINOTRUCK 066', 'HOWO', NULL, NULL, 'LZZ5EXSE2MB073857', '3063AB14', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '067', 'HOWO SINOTRUCK 067', 'HOWO', NULL, NULL, 'LZZ5EXSEXMB073863', '3064AB14', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '087', 'HOWO SINOTRUCK 087', 'HOWO', NULL, NULL, 'LZZ5EXSC3KN50972', '7086BJ01', 'available', 'À vérifier : Châssis tronqué (16 car.)
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '088', 'HOWO SINOTRUCK 088', 'HOWO', NULL, NULL, 'LZZ5EXSF2KN509787', '4282BK01', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '089', 'HOWO SINOTRUCK 089', 'HOWO', NULL, NULL, 'LZZ5EXSF6KN509789', '4279BK01', 'available', 'Source : Charroi Kamoa octobre 2026.pdf'),
    ('KAMOA', 'BENNE', 'HOWO', '3236AC14', 'HOWO SINOTRUCK', 'HOWO', NULL, NULL, 'LEZAD1EC3RF127194', '3236AC14', 'available', 'À vérifier : Sans n° d''ordre
Source : Charroi Kamoa octobre 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '208', 'BUS TATA 1318 208', 'Tata', '1318', NULL, 'MAT462271P5L00896', '7055AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = SICOMINES 206 ; n° 208 en double
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '208-B', 'BUS TATA 1318 208', 'Tata', '1318', NULL, 'MAT462271P5L00899', '7415AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : N° 208 en double
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Yutong', '214', 'BUS YUTONG 214', 'Yutong', NULL, NULL, 'LZYTATE22N1007059', '6109AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Yutong', '215', 'BUS YUTONG 215', 'Yutong', NULL, NULL, 'LZYTATE22N1007057', '6107AB15', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Plaque AB15 inhabituelle
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '218', 'BUS TATA 1318 218', 'Tata', '1318', NULL, NULL, '0895AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis manquant
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '219', 'BUS TATA 1318 219', 'Tata', '1318', NULL, 'MAT462271S5L00864', '0880AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = SICOMINES n°12
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '220', 'BUS TATA 1318 220', 'Tata', '1318', NULL, 'MAT462271S5L00728', '0877AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '221', 'BUS TATA 1318 221', 'Tata', '1318', NULL, NULL, '0729AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis manquant
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '223', 'BUS TATA 1318 223', 'Tata', '1318', NULL, NULL, '1143AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis manquant
Source : CHARROI KINGAKILA 2026.pdf'),
    ('KINGAKILA', 'BUS', 'Tata', '0981AC14', 'BUS TATA 1318', 'Tata', '1318', NULL, NULL, '0981AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis et n° d''ordre manquants
Source : CHARROI KINGAKILA 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '5', 'BUS TATA 1318 5', 'Tata', '1318', NULL, 'MAT462271HDL00860', '1366AN06', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '202', 'BUS TATA 1318 202', 'Tata', '1318', NULL, 'MAT468271P5OO236', '6535AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis erroné
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '203-B', 'BUS TATA 1318 203', 'Tata', '1318', NULL, 'MAT462271P5L00126', '7043AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = CHEMAF 203
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '206', 'BUS TATA 1318 206', 'Tata', '1318', NULL, 'MAT462271P5L00896', '7284AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = KINGAKILA 208
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '207', 'BUS TATA 1318 207', 'Tata', '1318', NULL, 'MAT462271P5L00778', '7283AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '209', 'BUS TATA 1318 209', 'Tata', '1318', NULL, 'MAT462271P5L00897', '7414AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '210', 'BUS TATA 1318 210', 'Tata', '1318', NULL, 'MAT462271P5L00688', '7330AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '7331AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT462271P5L00670', '7331AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Sans n° d''ordre
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '6957AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, NULL, '6957AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Sans n° d''ordre ni châssis
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '6510AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, NULL, '6510AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : plaque = CHEMAF 203
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '2940AF19', 'BUS TATA 1318', 'Tata', '1318', NULL, NULL, '2940AF19', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : plaque = KAMOA 201
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '0878AC14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT462271S5L00864', '0878AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : châssis = KINGAKILA 219
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '0879AC14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271P5L02343', '0879AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '0256AC14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271P5L02066', '0256AC14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '3972AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271P5L00167', '3972AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '0165BA01', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271K0L00324', '0165BA01', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '1361AN05', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271HDL00600', '1361AN05', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : DOUBLON : = KAMOA bus 1
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '1070AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271L0L00878', '1070AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '1069AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271L0L00889', '1069AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf'),
    ('SICOMINES', 'BUS', 'Tata', '1068AB14', 'BUS TATA 1318', 'Tata', '1318', NULL, 'MAT42271L0L00871', '1068AB14', 'available', 'Places : 66
Statut non renseigné dans le fichier d''origine
À vérifier : Châssis : MAT42271 → MAT462271 ?
Source : charroi sicomines 2026.pdf');

INSERT INTO equipment (site_id, category_id, brand_id, code, name, brand, model, serial_number, chassis_number,
                       plate_number, status, notes)
SELECT s.id, c.id, b.id, i.code, i.name, i.brand_label, i.model, i.serial_number, i.chassis_number,
       i.plate_number, i.status, i.notes
  FROM import_equipment i
  JOIN sites s ON s.code = i.site
  LEFT JOIN equipment_categories c ON c.code = i.cat
  LEFT JOIN equipment_brands b ON b.name = i.brand;

INSERT INTO equipment_status_history (equipment_id, from_status, to_status, reason)
SELECT id, NULL, status, 'Import du parc (charrois octobre 2026)' FROM equipment;

DROP TEMPORARY TABLE import_equipment;

-- Assurances : documents à échéance (fiche engin > Documents)
CREATE TEMPORARY TABLE import_documents (
    code VARCHAR(30), issuer VARCHAR(120), reference VARCHAR(80), issued_at DATE, expires_at DATE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO import_documents VALUES
    ('076', NULL, NULL, NULL, '2027-02-06'),
    ('052', NULL, NULL, NULL, '2027-02-06'),
    ('203', NULL, NULL, NULL, '2027-02-06'),
    ('017', NULL, NULL, NULL, '2027-02-06'),
    ('016', NULL, NULL, NULL, '2027-02-06'),
    ('105', NULL, NULL, NULL, '2027-06-14'),
    ('112', 'Mayfair', '26-005-0000019078', NULL, '2027-02-06'),
    ('050', 'Mayfair', '26-005-0000028486', NULL, '2027-08-31'),
    ('106', 'Mayfair', '26-005-0000019109', NULL, '2027-08-06'),
    ('037', 'Mayfair', '26-005-0000028487', NULL, '2027-08-31'),
    ('078', NULL, '26-005-0000019127', NULL, '2027-02-06'),
    ('090', NULL, '26-005-0000019126', NULL, '2027-02-06'),
    ('068', NULL, '26-005-0000019073', NULL, '2027-02-06'),
    ('057', NULL, '26-005-0000019128', NULL, '2027-02-06'),
    ('058', NULL, '26-005-0000019129', NULL, '2027-02-06'),
    ('027', 'Mayfair', '26-005-0000019058', NULL, '2027-02-06'),
    ('038', 'Mayfair', '26-005-0000019103', NULL, '2027-02-06'),
    ('081', 'Mayfair', '26-005-0000019079', NULL, '2027-02-06'),
    ('094', 'Mayfair', '26-005-0000019084', NULL, '2027-02-06'),
    ('084', 'Mayfair', '26-005-0000019077', NULL, '2027-02-06'),
    ('036', 'Mayfair', '26-005-0000019098', NULL, '2026-02-06'),
    ('933', 'Mayfair', '26-005-0000019098', NULL, '2026-02-06'),
    ('402', 'Mayfair', '26-005-0000019070', NULL, '2027-02-06'),
    ('404', 'Mayfair', '26-005-0000019074', NULL, '2027-02-06'),
    ('407', 'Mayfair', '26-005-000001969', NULL, '2027-02-06'),
    ('216', 'SONAS', 'N0600125024870', NULL, '2026-10-14'),
    ('217', 'SONAS', 'N0600125024870', NULL, '2026-10-14'),
    ('030', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('103', NULL, NULL, '2026-07-21', '2027-07-20'),
    ('006', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('005', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('048', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('101', NULL, NULL, '2026-09-01', '2027-08-31'),
    ('102', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('1', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('201', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('043', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('045', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('066', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('067', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('087', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('088', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('089', NULL, NULL, '2026-02-07', '2027-02-06'),
    ('3236AC14', NULL, NULL, '2026-09-01', '2027-02-27');

INSERT INTO equipment_documents (equipment_id, document_type_id, issuer, reference, issued_at, expires_at, note)
SELECT e.id, t.id, i.issuer, i.reference, i.issued_at, i.expires_at, 'Import charrois octobre 2026'
  FROM import_documents i
  JOIN equipment e ON e.code = i.code
  JOIN document_types t ON t.code = 'insurance';

DROP TEMPORARY TABLE import_documents;

COMMIT;

-- Contrôle
SELECT s.code AS site, COUNT(e.id) AS engins,
       SUM(e.status = 'available') AS disponibles, SUM(e.status = 'breakdown') AS en_panne,
       SUM(e.status = 'immobilized') AS stand_by
  FROM sites s LEFT JOIN equipment e ON e.site_id = s.id
 GROUP BY s.code ORDER BY s.code;
SELECT COUNT(*) AS assurances_importees FROM equipment_documents;
SELECT email, role FROM users;
