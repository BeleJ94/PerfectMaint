-- Fichier joint (optionnel) à un document d'engin : scan PDF ou photo de l'attestation, de la vignette…
-- Stocké hors de la racine web (storage/uploads/documents), servi après contrôle du périmètre.
ALTER TABLE equipment_documents
    ADD COLUMN file_name      VARCHAR(80)  NULL AFTER note,
    ADD COLUMN original_name  VARCHAR(190) NULL AFTER file_name,
    ADD COLUMN mime           VARCHAR(60)  NULL AFTER original_name,
    ADD COLUMN size_bytes     INT UNSIGNED NULL AFTER mime,
    ADD UNIQUE KEY uq_equipment_documents_file (file_name);
