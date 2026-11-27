-- Rôle « responsable dispatch » : équipes par shift, conducteurs et affectations aux engins,
-- signalement des pannes et saisie des index (ni exécution de la maintenance, ni stock, ni rapports)
ALTER TABLE users
    MODIFY role ENUM('admin','manager','technician','viewer','encoder','dispatcher') NOT NULL DEFAULT 'viewer';
