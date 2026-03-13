<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\AuditIndexation;

/**
 * Depot pour les audits d'indexation.
 */
final class DepotAuditIndexation
{
    public function __construct(private readonly \PDO $db) {}

    public function creer(array $donnees): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_audits_indexation (client_id, utilisateur_id, domaine, urls_total, statut)
            VALUES (:client_id, :utilisateur_id, :domaine, :urls_total, :statut)
        ');
        $stmt->execute([
            'client_id' => $donnees['client_id'] ?? null,
            'utilisateur_id' => $donnees['utilisateur_id'] ?? null,
            'domaine' => $donnees['domaine'],
            'urls_total' => $donnees['urls_total'] ?? 0,
            'statut' => 'en_cours',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function trouverParId(int $id): ?AuditIndexation
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_audits_indexation WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? AuditIndexation::depuisLigne($ligne) : null;
    }

    /**
     * @return AuditIndexation[]
     */
    public function listerParUtilisateur(?int $utilisateurId, int $limite = 50): array
    {
        if ($utilisateurId !== null) {
            $stmt = $this->db->prepare('
                SELECT * FROM sm_audits_indexation
                WHERE utilisateur_id = :uid
                ORDER BY cree_le DESC LIMIT :limite
            ');
            $stmt->bindValue('uid', $utilisateurId, \PDO::PARAM_INT);
            $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare('
                SELECT * FROM sm_audits_indexation
                ORDER BY cree_le DESC LIMIT :limite
            ');
            $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return array_map(
            fn(array $ligne) => AuditIndexation::depuisLigne($ligne),
            $stmt->fetchAll()
        );
    }

    public function mettreAJourProgression(int $id, int $urlsTraitees, int $urlsTotal): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_audits_indexation
            SET urls_traitees = :traitees, urls_total = :total
            WHERE id = :id
        ');
        $stmt->execute([
            'traitees' => $urlsTraitees,
            'total' => $urlsTotal,
            'id' => $id,
        ]);
    }

    public function terminer(int $id, int $indexables, int $nonIndexables, int $contradictoires): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_audits_indexation
            SET statut = :statut,
                urls_indexables = :indexables,
                urls_non_indexables = :non_indexables,
                urls_contradictoires = :contradictoires,
                termine_le = :termine_le
            WHERE id = :id
        ');
        $stmt->execute([
            'statut' => 'termine',
            'indexables' => $indexables,
            'non_indexables' => $nonIndexables,
            'contradictoires' => $contradictoires,
            'termine_le' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function marquerErreur(int $id, string $message): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_audits_indexation
            SET statut = :statut, termine_le = :termine_le
            WHERE id = :id
        ');
        $stmt->execute([
            'statut' => 'erreur',
            'termine_le' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}
