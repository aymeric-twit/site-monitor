<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Url;

/**
 * Depot pour l'entite Url.
 */
final class DepotUrl
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return Url[]
     */
    public function trouverParGroupe(int $groupeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_urls WHERE groupe_id = :groupe_id ORDER BY libelle ASC, url ASC'
        );
        $stmt->execute(['groupe_id' => $groupeId]);
        return array_map(fn(array $l): Url => Url::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Retourne toutes les URLs actives d'un client (via les groupes).
     *
     * @return Url[]
     */
    public function trouverActivesParClient(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT u.* FROM sm_urls u
            JOIN sm_groupes_urls g ON g.id = u.groupe_id
            WHERE g.client_id = :client_id AND u.actif = 1 AND g.actif = 1
            ORDER BY g.ordre_tri ASC, u.url ASC
        ');
        $stmt->execute(['client_id' => $clientId]);
        return array_map(fn(array $l): Url => Url::depuisLigne($l), $stmt->fetchAll());
    }

    public function trouverParId(int $id): ?Url
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_urls WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Url::depuisLigne($ligne) : null;
    }

    public function creer(Url $url): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_urls (groupe_id, url, libelle, actif, notes)
            VALUES (:groupe_id, :url, :libelle, :actif, :notes)
        ');
        $stmt->execute([
            'groupe_id' => $url->groupeId,
            'url' => $url->url,
            'libelle' => $url->libelle,
            'actif' => $url->actif ? 1 : 0,
            'notes' => $url->notes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(Url $url): void
    {
        if ($url->id === null) {
            throw new \InvalidArgumentException('L\'URL doit avoir un ID pour etre modifiee.');
        }

        $stmt = $this->db->prepare('
            UPDATE sm_urls
            SET url = :url, libelle = :libelle, actif = :actif, notes = :notes
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $url->id,
            'url' => $url->url,
            'libelle' => $url->libelle,
            'actif' => $url->actif ? 1 : 0,
            'notes' => $url->notes,
        ]);
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_urls WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function mettreAJourStatut(int $id, string $statut): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_urls SET dernier_statut = :statut, derniere_verification = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute(['id' => $id, 'statut' => $statut]);
    }

    /**
     * Associe un modele a une URL (many-to-many).
     */
    public function associerModele(int $urlId, int $modeleId): void
    {
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO sm_url_modele (url_id, modele_id) VALUES (:url_id, :modele_id)
        ');
        // MySQL alternative
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO sm_url_modele (url_id, modele_id) VALUES (:url_id, :modele_id)
            ');
        }
        $stmt->execute(['url_id' => $urlId, 'modele_id' => $modeleId]);
    }

    /**
     * Dissocie un modele d'une URL.
     */
    public function dissocierModele(int $urlId, int $modeleId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM sm_url_modele WHERE url_id = :url_id AND modele_id = :modele_id'
        );
        $stmt->execute(['url_id' => $urlId, 'modele_id' => $modeleId]);
    }

    /**
     * Retourne les IDs de modeles associes a une URL.
     *
     * @return int[]
     */
    public function modelesAssocies(int $urlId): array
    {
        $stmt = $this->db->prepare('SELECT modele_id FROM sm_url_modele WHERE url_id = :url_id');
        $stmt->execute(['url_id' => $urlId]);
        return array_map(fn($r) => (int) $r['modele_id'], $stmt->fetchAll());
    }

    public function compterParGroupe(int $groupeId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sm_urls WHERE groupe_id = :groupe_id');
        $stmt->execute(['groupe_id' => $groupeId]);
        return (int) $stmt->fetchColumn();
    }

    public function compterParClient(int $clientId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM sm_urls u
            JOIN sm_groupes_urls g ON g.id = u.groupe_id
            WHERE g.client_id = :client_id
        ');
        $stmt->execute(['client_id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }
}
