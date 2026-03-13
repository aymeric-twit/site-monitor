CREATE TABLE IF NOT EXISTS sm_regles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modele_id INT NOT NULL,
    type_regle VARCHAR(50) NOT NULL,
    nom VARCHAR(255) NOT NULL,
    configuration_json LONGTEXT NOT NULL,
    severite VARCHAR(20) NOT NULL DEFAULT 'erreur',
    ordre_tri INT NOT NULL DEFAULT 0,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (modele_id) REFERENCES sm_modeles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sm_regles_modele ON sm_regles(modele_id);
CREATE INDEX idx_sm_regles_type ON sm_regles(type_regle);
