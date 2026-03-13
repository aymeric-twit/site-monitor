-- Ajout de l'isolation multi-utilisateur sur les clients
ALTER TABLE sm_clients ADD COLUMN utilisateur_id INT DEFAULT NULL;
CREATE INDEX idx_sm_clients_utilisateur ON sm_clients(utilisateur_id);
