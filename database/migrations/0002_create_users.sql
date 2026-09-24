-- Utilisateurs, rôles et rattachement aux sites
CREATE TABLE users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    first_name     VARCHAR(80)  NOT NULL,
    last_name      VARCHAR(80)  NOT NULL,
    role           ENUM('admin','manager','technician','viewer') NOT NULL DEFAULT 'viewer',
    locale         CHAR(2)      NOT NULL DEFAULT 'fr',
    all_sites      TINYINT(1)   NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    failed_logins  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME     NULL,
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sites (
    user_id  INT UNSIGNED NOT NULL,
    site_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, site_id),
    KEY idx_user_sites_site (site_id),
    CONSTRAINT fk_user_sites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
