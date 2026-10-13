-- Rôle « encodeur » : agent chargé uniquement de la saisie quotidienne des index
ALTER TABLE users
    MODIFY role ENUM('admin','manager','technician','viewer','encoder') NOT NULL DEFAULT 'viewer';
