-- Migration 016 : Ajout du type d'alerte pour distinguer echec/regression/recuperation
ALTER TABLE sm_alertes ADD COLUMN type_alerte VARCHAR(30) NOT NULL DEFAULT 'echec';
