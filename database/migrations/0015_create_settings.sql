-- Paramètres de l'application (clé / valeur) : envoi d'e-mails, alarmes par e-mail
CREATE TABLE settings (
    name        VARCHAR(80) NOT NULL,
    value       TEXT        NULL,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
