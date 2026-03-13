CREATE TABLE IF NOT EXISTS sm_url_modele (
    url_id INT NOT NULL,
    modele_id INT NOT NULL,
    PRIMARY KEY (url_id, modele_id),
    FOREIGN KEY (url_id) REFERENCES sm_urls(id) ON DELETE CASCADE,
    FOREIGN KEY (modele_id) REFERENCES sm_modeles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
