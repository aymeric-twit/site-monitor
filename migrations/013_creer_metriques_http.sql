CREATE TABLE IF NOT EXISTS sm_metriques_http (
    id INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    url_id INT NOT NULL,
    code_http INT NOT NULL,
    temps_total_ms FLOAT NOT NULL,
    ttfb_ms FLOAT NOT NULL,
    temps_dns_ms FLOAT NOT NULL,
    temps_connexion_ms FLOAT NOT NULL,
    temps_ssl_ms FLOAT DEFAULT 0,
    taille_octets INT NOT NULL DEFAULT 0,
    url_finale VARCHAR(2048) DEFAULT NULL,
    nombre_redirections INT NOT NULL DEFAULT 0,
    en_tetes_json LONGTEXT DEFAULT NULL,
    infos_ssl_json LONGTEXT DEFAULT NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES sm_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (url_id) REFERENCES sm_urls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_metriques_execution ON sm_metriques_http(execution_id);
CREATE INDEX idx_sm_metriques_url ON sm_metriques_http(url_id, cree_le);
