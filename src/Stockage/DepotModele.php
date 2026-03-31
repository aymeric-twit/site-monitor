<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Modele;

/**
 * Depot pour l'entite Modele (template de verification).
 */
final class DepotModele
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return Modele[]
     */
    public function trouverTous(): array
    {
        $stmt = $this->db->query('SELECT * FROM sm_modeles ORDER BY est_global DESC, nom ASC');
        return array_map(fn(array $l): Modele => Modele::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Retourne les modeles accessibles par un client (globaux + specifiques au client).
     *
     * @return Modele[]
     */
    public function trouverParClient(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_modeles
            WHERE est_global = 1 OR client_id = :client_id
            ORDER BY est_global DESC, nom ASC
        ');
        $stmt->execute(['client_id' => $clientId]);
        return array_map(fn(array $l): Modele => Modele::depuisLigne($l), $stmt->fetchAll());
    }

    public function trouverParId(int $id): ?Modele
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_modeles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Modele::depuisLigne($ligne) : null;
    }

    public function creer(Modele $modele): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_modeles (client_id, nom, description, est_global)
            VALUES (:client_id, :nom, :description, :est_global)
        ');
        $stmt->execute([
            'client_id' => $modele->clientId,
            'nom' => $modele->nom,
            'description' => $modele->description,
            'est_global' => $modele->estGlobal ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(Modele $modele): void
    {
        if ($modele->id === null) {
            throw new \InvalidArgumentException('Le modele doit avoir un ID pour etre modifie.');
        }

        $stmt = $this->db->prepare('
            UPDATE sm_modeles
            SET nom = :nom, description = :description, est_global = :est_global,
                client_id = :client_id
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $modele->id,
            'nom' => $modele->nom,
            'description' => $modele->description,
            'est_global' => $modele->estGlobal ? 1 : 0,
            'client_id' => $modele->clientId,
        ]);
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_modeles WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Retourne les modeles avec le nombre de regles et d'URLs associees.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statistiques(): array
    {
        $sql = '
            SELECT m.*,
                   COUNT(DISTINCT r.id) AS nb_regles,
                   COUNT(DISTINCT um.url_id) AS nb_urls
            FROM sm_modeles m
            LEFT JOIN sm_regles r ON r.modele_id = m.id
            LEFT JOIN sm_url_modele um ON um.modele_id = m.id
            GROUP BY m.id
            ORDER BY m.est_global DESC, m.nom ASC
        ';
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Retourne les modeles accessibles par un client avec stats (nb regles, nb URLs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function statistiquesParClient(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT m.*,
                   COUNT(DISTINCT r.id) AS nb_regles,
                   COUNT(DISTINCT um.url_id) AS nb_urls
            FROM sm_modeles m
            LEFT JOIN sm_regles r ON r.modele_id = m.id
            LEFT JOIN sm_url_modele um ON um.modele_id = m.id
            WHERE m.est_global = 1 OR m.client_id = :client_id
            GROUP BY m.id
            ORDER BY m.est_global DESC, m.nom ASC
        ');
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll();
    }
}
