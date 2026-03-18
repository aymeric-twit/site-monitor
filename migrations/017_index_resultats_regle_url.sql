-- Migration 017 : Index composite pour comparaison entre executions
CREATE INDEX IF NOT EXISTS idx_sm_resultats_exec_regle_url ON sm_resultats(execution_id, regle_id, url_id);
