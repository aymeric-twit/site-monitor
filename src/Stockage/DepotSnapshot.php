<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Snapshot;

/**
 * Depot pour l'entite Snapshot (captures de contenu).
 */
final class DepotSnapshot
{
    public function __construct(private readonly \PDO $db) {}

    public function creer(Snapshot $snapshot, ?string $contenuBrut = null): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_snapshots
                (url_id, execution_id, type_contenu, hash_contenu, contenu_compresse, taille_octets, est_baseline)
            VALUES (:url_id, :execution_id, :type_contenu, :hash_contenu, :contenu_compresse, :taille_octets, :est_baseline)
        ');

        $contenuCompresse = $contenuBrut !== null ? gzcompress($contenuBrut, 6) : null;

        $stmt->execute([
            'url_id' => $snapshot->urlId,
            'execution_id' => $snapshot->executionId,
            'type_contenu' => $snapshot->typeContenu,
            'hash_contenu' => $snapshot->hashContenu,
            'contenu_compresse' => $contenuCompresse,
            'taille_octets' => $snapshot->tailleOctets,
            'est_baseline' => 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Liste les snapshots pour une URL (sans le contenu compresse).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listerParUrl(int $urlId, int $limite = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT id, url_id, execution_id, type_contenu, hash_contenu,
                   taille_octets, est_baseline, cree_le
            FROM sm_snapshots
            WHERE url_id = :url_id
            ORDER BY cree_le DESC
            LIMIT :limite
        ');
        $stmt->bindValue('url_id', $urlId, \PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Retourne le dernier snapshot pour une URL et un type de contenu.
     */
    public function trouverDernier(int $urlId, string $typeContenu): ?Snapshot
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_snapshots
            WHERE url_id = :url_id AND type_contenu = :type_contenu
            ORDER BY cree_le DESC LIMIT 1
        ');
        $stmt->execute(['url_id' => $urlId, 'type_contenu' => $typeContenu]);
        $ligne = $stmt->fetch();
        return $ligne ? Snapshot::depuisLigne($ligne) : null;
    }

    /**
     * Retourne la baseline active pour une URL.
     */
    public function trouverBaseline(int $urlId, string $typeContenu): ?Snapshot
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_snapshots
            WHERE url_id = :url_id AND type_contenu = :type_contenu AND est_baseline = 1
            ORDER BY cree_le DESC LIMIT 1
        ');
        $stmt->execute(['url_id' => $urlId, 'type_contenu' => $typeContenu]);
        $ligne = $stmt->fetch();
        return $ligne ? Snapshot::depuisLigne($ligne) : null;
    }

    /**
     * Recupere le contenu decompresse d'un snapshot.
     */
    public function lireContenu(int $snapshotId): ?string
    {
        $stmt = $this->db->prepare('SELECT contenu_compresse FROM sm_snapshots WHERE id = :id');
        $stmt->execute(['id' => $snapshotId]);
        $contenu = $stmt->fetchColumn();

        if ($contenu === false || $contenu === null) {
            return null;
        }

        $decompresse = gzuncompress($contenu);
        return $decompresse === false ? null : $decompresse;
    }

    /**
     * Definit un snapshot comme baseline.
     */
    public function definirBaseline(int $snapshotId): void
    {
        // D'abord retirer l'ancien baseline pour cette URL + type
        $stmt = $this->db->prepare('
            UPDATE sm_snapshots SET est_baseline = 0
            WHERE url_id = (SELECT url_id FROM sm_snapshots WHERE id = :id)
              AND type_contenu = (SELECT type_contenu FROM sm_snapshots WHERE id = :id2)
              AND est_baseline = 1
        ');
        $stmt->execute(['id' => $snapshotId, 'id2' => $snapshotId]);

        // Puis definir le nouveau
        $stmt = $this->db->prepare('UPDATE sm_snapshots SET est_baseline = 1 WHERE id = :id');
        $stmt->execute(['id' => $snapshotId]);
    }
}
