CREATE TABLE IF NOT EXISTS sm_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url_id INT NOT NULL,
    execution_id INT NOT NULL,
    type_contenu VARCHAR(50) NOT NULL,
    hash_contenu VARCHAR(64) NOT NULL,
    contenu_compresse LONGBLOB DEFAULT NULL,
    taille_octets INT NOT NULL DEFAULT 0,
    est_baseline TINYINT(1) NOT NULL DEFAULT 0,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (url_id) REFERENCES sm_urls(id) ON DELETE CASCADE,
    FOREIGN KEY (execution_id) REFERENCES sm_executions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_snapshots_url ON sm_snapshots(url_id, cree_le);
CREATE INDEX idx_sm_snapshots_baseline ON sm_snapshots(url_id, est_baseline);
