<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Regle;

/**
 * Depot pour l'entite Regle.
 */
final class DepotRegle
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return Regle[]
     */
    public function trouverParModele(int $modeleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_regles WHERE modele_id = :modele_id ORDER BY ordre_tri ASC'
        );
        $stmt->execute(['modele_id' => $modeleId]);
        return array_map(fn(array $l): Regle => Regle::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Retourne toutes les regles actives pour une URL (via ses modeles associes).
     *
     * @return Regle[]
     */
    public function trouverActivesParUrl(int $urlId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.* FROM sm_regles r
            JOIN sm_url_modele um ON um.modele_id = r.modele_id
            WHERE um.url_id = :url_id AND r.actif = 1
            ORDER BY r.ordre_tri ASC
        ');
        $stmt->execute(['url_id' => $urlId]);
        return array_map(fn(array $l): Regle => Regle::depuisLigne($l), $stmt->fetchAll());
    }

    public function trouverParId(int $id): ?Regle
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_regles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Regle::depuisLigne($ligne) : null;
    }

    public function creer(Regle $regle): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_regles (modele_id, type_regle, nom, configuration_json, severite, ordre_tri, actif)
            VALUES (:modele_id, :type_regle, :nom, :configuration_json, :severite, :ordre_tri, :actif)
        ');
        $stmt->execute([
            'modele_id' => $regle->modeleId,
            'type_regle' => $regle->typeRegle->value,
            'nom' => $regle->nom,
            'configuration_json' => json_encode($regle->configuration, JSON_UNESCAPED_UNICODE),
            'severite' => $regle->severite->value,
            'ordre_tri' => $regle->ordreTri,
            'actif' => $regle->actif ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(Regle $regle): void
    {
        if ($regle->id === null) {
            throw new \InvalidArgumentException('La regle doit avoir un ID pour etre modifiee.');
        }

        $stmt = $this->db->prepare('
            UPDATE sm_regles
            SET type_regle = :type_regle, nom = :nom, configuration_json = :configuration_json,
                severite = :severite, ordre_tri = :ordre_tri, actif = :actif
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $regle->id,
            'type_regle' => $regle->typeRegle->value,
            'nom' => $regle->nom,
            'configuration_json' => json_encode($regle->configuration, JSON_UNESCAPED_UNICODE),
            'severite' => $regle->severite->value,
            'ordre_tri' => $regle->ordreTri,
            'actif' => $regle->actif ? 1 : 0,
        ]);
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_regles WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function compterParModele(int $modeleId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sm_regles WHERE modele_id = :modele_id');
        $stmt->execute(['modele_id' => $modeleId]);
        return (int) $stmt->fetchColumn();
    }
}
