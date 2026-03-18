-- Migration 017 : Index composite pour comparaison entre executions + index type_alerte
CREATE INDEX idx_sm_resultats_exec_regle_url ON sm_resultats(execution_id, regle_id, url_id);
CREATE INDEX idx_sm_alertes_type ON sm_alertes(type_alerte);
