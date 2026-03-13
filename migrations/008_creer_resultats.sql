CREATE TABLE IF NOT EXISTS sm_resultats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    url_id INT NOT NULL,
    regle_id INT NOT NULL,
    succes TINYINT(1) NOT NULL,
    severite VARCHAR(20) NOT NULL,
    valeur_attendue LONGTEXT DEFAULT NULL,
    valeur_obtenue LONGTEXT DEFAULT NULL,
    message LONGTEXT DEFAULT NULL,
    duree_ms INT DEFAULT NULL,
    verifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES sm_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (url_id) REFERENCES sm_urls(id) ON DELETE CASCADE,
    FOREIGN KEY (regle_id) REFERENCES sm_regles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_resultats_execution ON sm_resultats(execution_id);
CREATE INDEX idx_sm_resultats_url ON sm_resultats(url_id);
CREATE INDEX idx_sm_resultats_succes ON sm_resultats(succes);
