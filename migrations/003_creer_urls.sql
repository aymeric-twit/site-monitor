CREATE TABLE IF NOT EXISTS sm_urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    groupe_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    libelle VARCHAR(255) DEFAULT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    derniere_verification DATETIME DEFAULT NULL,
    dernier_statut VARCHAR(20) DEFAULT NULL,
    notes LONGTEXT DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (groupe_id) REFERENCES sm_groupes_urls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_urls_groupe ON sm_urls(groupe_id);
CREATE INDEX idx_sm_urls_actif ON sm_urls(actif);
