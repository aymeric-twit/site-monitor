<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Client;

/**
 * Depot (repository) pour l'entite Client.
 */
final class DepotClient
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return Client[]
     */
    public function trouverTous(bool $actifsUniquement = false, ?int $utilisateurId = null): array
    {
        $conditions = [];
        $params = [];

        if ($actifsUniquement) {
            $conditions[] = 'actif = 1';
        }
        if ($utilisateurId !== null) {
            $conditions[] = 'utilisateur_id = :uid';
            $params['uid'] = $utilisateurId;
        }

        $sql = 'SELECT * FROM sm_clients';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY nom ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $ligne): Client => Client::depuisLigne($ligne),
            $stmt->fetchAll()
        );
    }

    public function trouverParId(int $id): ?Client
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_clients WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Client::depuisLigne($ligne) : null;
    }

    public function trouverParSlug(string $slug): ?Client
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_clients WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $ligne = $stmt->fetch();
        return $ligne ? Client::depuisLigne($ligne) : null;
    }

    public function creer(Client $client): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_clients (nom, slug, domaine, email_contact, actif, configuration_json, utilisateur_id)
            VALUES (:nom, :slug, :domaine, :email_contact, :actif, :configuration_json, :utilisateur_id)
        ');
        $stmt->execute([
            'nom' => $client->nom,
            'slug' => $client->slug,
            'domaine' => $client->domaine,
            'email_contact' => $client->emailContact,
            'actif' => $client->actif ? 1 : 0,
            'configuration_json' => $client->configuration
                ? json_encode($client->configuration, JSON_UNESCAPED_UNICODE)
                : null,
            'utilisateur_id' => $client->utilisateurId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(Client $client): void
    {
        if ($client->id === null) {
            throw new \InvalidArgumentException('Le client doit avoir un ID pour etre modifie.');
        }

        $stmt = $this->db->prepare('
            UPDATE sm_clients
            SET nom = :nom, slug = :slug, domaine = :domaine,
                email_contact = :email_contact, actif = :actif,
                configuration_json = :configuration_json
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $client->id,
            'nom' => $client->nom,
            'slug' => $client->slug,
            'domaine' => $client->domaine,
            'email_contact' => $client->emailContact,
            'actif' => $client->actif ? 1 : 0,
            'configuration_json' => $client->configuration
                ? json_encode($client->configuration, JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_clients WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function compter(?int $utilisateurId = null): int
    {
        if ($utilisateurId !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM sm_clients WHERE utilisateur_id = :uid');
            $stmt->execute(['uid' => $utilisateurId]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->db->query('SELECT COUNT(*) FROM sm_clients')->fetchColumn();
    }

    public function compterActifs(?int $utilisateurId = null): int
    {
        if ($utilisateurId !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM sm_clients WHERE actif = 1 AND utilisateur_id = :uid');
            $stmt->execute(['uid' => $utilisateurId]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->db->query('SELECT COUNT(*) FROM sm_clients WHERE actif = 1')->fetchColumn();
    }

    /**
     * Retourne les KPIs par client pour le dashboard.
     *
     * @return array<int, array{client_id: int, nom: string, nb_groupes: int, nb_urls: int}>
     */
    public function statistiques(?int $utilisateurId = null): array
    {
        $sql = '
            SELECT c.id AS client_id, c.nom, c.domaine, c.actif,
                   COUNT(DISTINCT g.id) AS nb_groupes,
                   COUNT(DISTINCT u.id) AS nb_urls
            FROM sm_clients c
            LEFT JOIN sm_groupes_urls g ON g.client_id = c.id
            LEFT JOIN sm_urls u ON u.groupe_id = g.id
        ';
        $params = [];
        if ($utilisateurId !== null) {
            $sql .= ' WHERE c.utilisateur_id = :uid';
            $params['uid'] = $utilisateurId;
        }
        $sql .= ' GROUP BY c.id, c.nom, c.domaine, c.actif ORDER BY c.nom ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Stats avancees par client : score, derniere execution, alertes, TTFB.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statistiquesAvancees(?int $utilisateurId = null): array
    {
        $where = '';
        $params = [];
        if ($utilisateurId !== null) {
            $where = ' WHERE c.utilisateur_id = :uid';
            $params['uid'] = $utilisateurId;
        }

        $sql = "
            SELECT c.id AS client_id, c.nom, c.domaine, c.actif,
                   COUNT(DISTINCT g.id) AS nb_groupes,
                   COUNT(DISTINCT u.id) AS nb_urls,
                   (SELECT e.cree_le FROM sm_executions e
                    WHERE e.client_id = c.id ORDER BY e.cree_le DESC LIMIT 1
                   ) AS derniere_execution,
                   (SELECT CASE WHEN (e2.succes + e2.echecs + e2.avertissements) > 0
                       THEN ROUND(e2.succes * 100.0 / (e2.succes + e2.echecs + e2.avertissements), 1)
                       ELSE 0 END
                    FROM sm_executions e2
                    WHERE e2.client_id = c.id AND e2.statut = 'termine'
                    ORDER BY e2.cree_le DESC LIMIT 1
                   ) AS taux_reussite_dernier,
                   (SELECT COUNT(*) FROM sm_alertes a
                    WHERE a.client_id = c.id AND a.envoyee = 0
                   ) AS alertes_non_lues,
                   (SELECT ROUND(AVG(m.ttfb_ms))
                    FROM sm_metriques_http m
                    JOIN sm_executions e3 ON e3.id = m.execution_id
                    WHERE e3.client_id = c.id
                      AND m.cree_le >= datetime('now', '-7 days')
                   ) AS ttfb_moyen
            FROM sm_clients c
            LEFT JOIN sm_groupes_urls g ON g.client_id = c.id
            LEFT JOIN sm_urls u ON u.groupe_id = g.id
            {$where}
            GROUP BY c.id, c.nom, c.domaine, c.actif
            ORDER BY c.nom ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
