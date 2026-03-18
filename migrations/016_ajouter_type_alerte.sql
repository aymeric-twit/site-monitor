-- Migration 016 : Ajout du type d'alerte
-- Pas d'index pour eviter l'erreur 1709 sur MySQL avec ROW_FORMAT=COMPACT
ALTER TABLE sm_alertes ADD COLUMN type_alerte VARCHAR(30) NOT NULL DEFAULT 'echec';
