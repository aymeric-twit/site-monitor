CREATE TABLE IF NOT EXISTS sm_groupes_urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    ordre_tri INT NOT NULL DEFAULT 0,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    planification_json LONGTEXT DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES sm_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_groupes_client ON sm_groupes_urls(client_id);
CREATE INDEX idx_sm_groupes_actif ON sm_groupes_urls(actif);
