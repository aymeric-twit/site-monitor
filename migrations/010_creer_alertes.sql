CREATE TABLE IF NOT EXISTS sm_alertes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    client_id INT NOT NULL,
    severite VARCHAR(20) NOT NULL,
    sujet VARCHAR(500) NOT NULL,
    corps_texte LONGTEXT NOT NULL,
    destinataires LONGTEXT NOT NULL,
    envoyee TINYINT(1) NOT NULL DEFAULT 0,
    envoyee_le DATETIME DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES sm_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES sm_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_alertes_client ON sm_alertes(client_id);
CREATE INDEX idx_sm_alertes_severite ON sm_alertes(severite);
CREATE INDEX idx_sm_alertes_envoyee ON sm_alertes(envoyee);
