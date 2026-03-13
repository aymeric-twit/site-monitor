CREATE TABLE IF NOT EXISTS sm_planifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    groupe_id INT DEFAULT NULL,
    frequence_minutes INT NOT NULL DEFAULT 1440,
    heure_debut VARCHAR(5) DEFAULT NULL,
    heure_fin VARCHAR(5) DEFAULT NULL,
    jours_semaine VARCHAR(20) DEFAULT NULL,
    user_agent LONGTEXT DEFAULT NULL,
    headers_json LONGTEXT DEFAULT NULL,
    timeout_secondes INT NOT NULL DEFAULT 30,
    delai_entre_requetes_ms INT NOT NULL DEFAULT 1000,
    max_tentatives INT NOT NULL DEFAULT 3,
    delai_entre_tentatives_ms INT NOT NULL DEFAULT 5000,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    derniere_execution DATETIME DEFAULT NULL,
    prochaine_execution DATETIME DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES sm_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_planif_prochaine ON sm_planifications(prochaine_execution, actif);
