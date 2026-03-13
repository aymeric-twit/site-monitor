CREATE TABLE IF NOT EXISTS sm_audits_indexation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INT DEFAULT NULL,
    utilisateur_id INT DEFAULT NULL,
    domaine VARCHAR(255) NOT NULL,
    urls_total INT NOT NULL DEFAULT 0,
    urls_traitees INT NOT NULL DEFAULT 0,
    urls_indexables INT NOT NULL DEFAULT 0,
    urls_non_indexables INT NOT NULL DEFAULT 0,
    urls_contradictoires INT NOT NULL DEFAULT 0,
    statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    termine_le DATETIME DEFAULT NULL,
    FOREIGN KEY (client_id) REFERENCES sm_clients(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sm_resultats_indexation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audit_id INT NOT NULL,
    url TEXT NOT NULL,
    code_http INT DEFAULT NULL,
    url_finale TEXT DEFAULT NULL,
    meta_robots TEXT DEFAULT NULL,
    x_robots_tag TEXT DEFAULT NULL,
    canonical TEXT DEFAULT NULL,
    canonical_auto_reference TINYINT(1) DEFAULT NULL,
    robots_txt_autorise TINYINT(1) DEFAULT NULL,
    robots_txt_regle TEXT DEFAULT NULL,
    present_sitemap TINYINT(1) DEFAULT NULL,
    statut_indexation VARCHAR(20) NOT NULL DEFAULT 'inconnu',
    contradictions_json TEXT DEFAULT NULL,
    severite_max VARCHAR(20) DEFAULT NULL,
    verifie_le DATETIME DEFAULT NULL,
    FOREIGN KEY (audit_id) REFERENCES sm_audits_indexation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sm_ri_audit ON sm_resultats_indexation(audit_id);
CREATE INDEX IF NOT EXISTS idx_sm_ri_statut ON sm_resultats_indexation(statut_indexation);
