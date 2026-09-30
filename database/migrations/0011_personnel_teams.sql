-- Personnel de terrain (sans compte de connexion), équipes par shift, affectation chauffeurs / opérateurs aux engins
CREATE TABLE teams (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(80)  NOT NULL,
    kind            ENUM('maintenance','operations') NOT NULL DEFAULT 'maintenance',
    shift           ENUM('day','night','rotation') NOT NULL DEFAULT 'day',
    leader_id       INT UNSIGNED NULL,
    dispatch_name   VARCHAR(120) NULL,
    dispatch_phone  VARCHAR(30)  NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_site_name (site_id, name),
    CONSTRAINT fk_teams_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    team_id         INT UNSIGNED NULL,
    employee_no     VARCHAR(30)  NULL,
    first_name      VARCHAR(80)  NOT NULL,
    last_name       VARCHAR(80)  NOT NULL,
    job             ENUM('driver','operator','mechanic','electrician','welder','tyre_fitter','lubricator','team_lead','dispatcher','supervisor','other') NOT NULL,
    phone           VARCHAR(30)  NULL,
    license_number  VARCHAR(40)  NULL,
    notes           VARCHAR(255) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_personnel_employee_no (employee_no),
    KEY idx_personnel_site_job (site_id, job, is_active),
    KEY idx_personnel_team (team_id),
    CONSTRAINT fk_personnel_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_personnel_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE teams
    ADD CONSTRAINT fk_teams_leader FOREIGN KEY (leader_id) REFERENCES personnel (id) ON DELETE SET NULL;

-- Qui conduit / opère quel engin, sur quel shift, et depuis quand (historisé)
CREATE TABLE equipment_assignments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id   INT UNSIGNED NOT NULL,
    personnel_id   INT UNSIGNED NOT NULL,
    shift          ENUM('day','night','rotation') NOT NULL DEFAULT 'day',
    started_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at       DATETIME     NULL,
    assigned_by    INT UNSIGNED NULL,
    note           VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_ea_equipment_open (equipment_id, ended_at),
    KEY idx_ea_personnel_open (personnel_id, ended_at),
    CONSTRAINT fk_ea_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_personnel FOREIGN KEY (personnel_id) REFERENCES personnel (id),
    CONSTRAINT fk_ea_user FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
