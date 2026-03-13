CREATE TABLE IF NOT EXISTS sm_executions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    groupe_id INT DEFAULT NULL,
    type_declencheur VARCHAR(20) NOT NULL DEFAULT 'manuel',
    statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
    urls_total INT NOT NULL DEFAULT 0,
    urls_traitees INT NOT NULL DEFAULT 0,
    regles_total INT NOT NULL DEFAULT 0,
    succes INT NOT NULL DEFAULT 0,
    echecs INT NOT NULL DEFAULT 0,
    avertissements INT NOT NULL DEFAULT 0,
    duree_ms INT DEFAULT NULL,
    demarree_le DATETIME DEFAULT NULL,
    terminee_le DATETIME DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_executions_client ON sm_executions(client_id);
CREATE INDEX idx_sm_executions_statut ON sm_executions(statut);
CREATE INDEX idx_sm_executions_date ON sm_executions(cree_le);
