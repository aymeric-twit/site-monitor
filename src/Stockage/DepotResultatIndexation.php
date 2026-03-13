<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\ResultatIndexation;

/**
 * Depot pour les resultats d'audit d'indexation.
 */
final class DepotResultatIndexation
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @param array<string, mixed> $donnees
     */
    public function creer(array $donnees): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_resultats_indexation
            (audit_id, url, code_http, url_finale, meta_robots, x_robots_tag,
             canonical, canonical_auto_reference, robots_txt_autorise, robots_txt_regle,
             present_sitemap, statut_indexation, contradictions_json, severite_max, verifie_le)
            VALUES
            (:audit_id, :url, :code_http, :url_finale, :meta_robots, :x_robots_tag,
             :canonical, :canonical_auto_reference, :robots_txt_autorise, :robots_txt_regle,
             :present_sitemap, :statut_indexation, :contradictions_json, :severite_max, :verifie_le)
        ');
        $stmt->execute([
            'audit_id' => $donnees['audit_id'],
            'url' => $donnees['url'],
            'code_http' => $donnees['code_http'],
            'url_finale' => $donnees['url_finale'],
            'meta_robots' => $donnees['meta_robots'],
            'x_robots_tag' => $donnees['x_robots_tag'],
            'canonical' => $donnees['canonical'],
            'canonical_auto_reference' => $donnees['canonical_auto_reference'],
            'robots_txt_autorise' => $donnees['robots_txt_autorise'],
            'robots_txt_regle' => $donnees['robots_txt_regle'],
            'present_sitemap' => $donnees['present_sitemap'],
            'statut_indexation' => $donnees['statut_indexation'],
            'contradictions_json' => $donnees['contradictions_json'],
            'severite_max' => $donnees['severite_max'],
            'verifie_le' => $donnees['verifie_le'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return ResultatIndexation[]
     */
    public function listerParAudit(int $auditId, ?string $statutFiltre = null, ?string $severiteFiltre = null): array
    {
        $sql = 'SELECT * FROM sm_resultats_indexation WHERE audit_id = :audit_id';
        $params = ['audit_id' => $auditId];

        if ($statutFiltre !== null && $statutFiltre !== '') {
            $sql .= ' AND statut_indexation = :statut';
            $params['statut'] = $statutFiltre;
        }
        if ($severiteFiltre !== null && $severiteFiltre !== '') {
            $sql .= ' AND severite_max = :severite';
            $params['severite'] = $severiteFiltre;
        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn(array $ligne) => ResultatIndexation::depuisLigne($ligne),
            $stmt->fetchAll()
        );
    }

    /**
     * @return array<string, int>
     */
    public function compterParStatut(int $auditId): array
    {
        $stmt = $this->db->prepare('
            SELECT statut_indexation, COUNT(*) AS total
            FROM sm_resultats_indexation
            WHERE audit_id = :audit_id
            GROUP BY statut_indexation
        ');
        $stmt->execute(['audit_id' => $auditId]);

        $resultats = [];
        foreach ($stmt->fetchAll() as $ligne) {
            $resultats[$ligne['statut_indexation']] = (int) $ligne['total'];
        }
        return $resultats;
    }

    /**
     * @return array<string, int>
     */
    public function compterParContradiction(int $auditId): array
    {
        $resultats = $this->listerParAudit($auditId);
        $compteurs = [];

        foreach ($resultats as $r) {
            if ($r->contradictions === null) {
                continue;
            }
            foreach ($r->contradictions as $c) {
                $type = $c['type'] ?? 'inconnu';
                $compteurs[$type] = ($compteurs[$type] ?? 0) + 1;
            }
        }

        return $compteurs;
    }
}
