-- Migration 018 : Convertir les tables en ROW_FORMAT=DYNAMIC pour supporter
-- les index sur colonnes utf8mb4 (corrige l'erreur 1709 sur MySQL InnoDB)
ALTER TABLE sm_clients ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_groupes_urls ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_urls ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_modeles ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_regles ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_associations_url_modele ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_executions ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_resultats ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_snapshots ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_alertes ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_planifications ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_metriques_http ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_audits_indexation ROW_FORMAT=DYNAMIC;
ALTER TABLE sm_resultats_indexation ROW_FORMAT=DYNAMIC;
