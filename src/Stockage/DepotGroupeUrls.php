<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\GroupeUrls;

/**
 * Depot pour l'entite GroupeUrls.
 */
final class DepotGroupeUrls
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return GroupeUrls[]
     */
    public function trouverParClient(int $clientId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_groupes_urls WHERE client_id = :client_id ORDER BY ordre_tri ASC, nom ASC'
        );
        $stmt->execute(['client_id' => $clientId]);
        return array_map(
            fn(array $l): GroupeUrls => GroupeUrls::depuisLigne($l),
            $stmt->fetchAll()
        );
    }

    public function trouverParId(int $id): ?GroupeUrls
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_groupes_urls WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? GroupeUrls::depuisLigne($ligne) : null;
    }

    public function creer(GroupeUrls $groupe): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_groupes_urls (client_id, nom, description, ordre_tri, actif, planification_json)
            VALUES (:client_id, :nom, :description, :ordre_tri, :actif, :planification_json)
        ');
        $stmt->execute([
            'client_id' => $groupe->clientId,
            'nom' => $groupe->nom,
            'description' => $groupe->description,
            'ordre_tri' => $groupe->ordreTri,
            'actif' => $groupe->actif ? 1 : 0,
            'planification_json' => $groupe->planification
                ? json_encode($groupe->planification, JSON_UNESCAPED_UNICODE)
                : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(GroupeUrls $groupe): void
    {
        if ($groupe->id === null) {
            throw new \InvalidArgumentException('Le groupe doit avoir un ID pour etre modifie.');
        }

        $stmt = $this->db->prepare('
            UPDATE sm_groupes_urls
            SET nom = :nom, description = :description, ordre_tri = :ordre_tri,
                actif = :actif, planification_json = :planification_json
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $groupe->id,
            'nom' => $groupe->nom,
            'description' => $groupe->description,
            'ordre_tri' => $groupe->ordreTri,
            'actif' => $groupe->actif ? 1 : 0,
            'planification_json' => $groupe->planification
                ? json_encode($groupe->planification, JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_groupes_urls WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function compterParClient(int $clientId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sm_groupes_urls WHERE client_id = :client_id');
        $stmt->execute(['client_id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }
}
