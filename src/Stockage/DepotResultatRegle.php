<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\ResultatRegle;

/**
 * Depot pour l'entite ResultatRegle.
 */
final class DepotResultatRegle
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return ResultatRegle[]
     */
    public function trouverParExecution(int $executionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_resultats WHERE execution_id = :execution_id ORDER BY verifie_le ASC'
        );
        $stmt->execute(['execution_id' => $executionId]);
        return array_map(fn(array $l): ResultatRegle => ResultatRegle::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * @return ResultatRegle[]
     */
    public function trouverParUrl(int $executionId, int $urlId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_resultats
            WHERE execution_id = :execution_id AND url_id = :url_id
            ORDER BY verifie_le ASC
        ');
        $stmt->execute(['execution_id' => $executionId, 'url_id' => $urlId]);
        return array_map(fn(array $l): ResultatRegle => ResultatRegle::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Retourne les echecs uniquement.
     *
     * @return ResultatRegle[]
     */
    public function trouverEchecsParExecution(int $executionId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_resultats
            WHERE execution_id = :execution_id AND succes = 0
            ORDER BY severite DESC, verifie_le ASC
        ');
        $stmt->execute(['execution_id' => $executionId]);
        return array_map(fn(array $l): ResultatRegle => ResultatRegle::depuisLigne($l), $stmt->fetchAll());
    }

    public function creer(ResultatRegle $resultat): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_resultats
                (execution_id, url_id, regle_id, succes, severite, valeur_attendue, valeur_obtenue, message, duree_ms, details_json)
            VALUES (:execution_id, :url_id, :regle_id, :succes, :severite, :valeur_attendue, :valeur_obtenue, :message, :duree_ms, :details_json)
        ');
        $stmt->execute([
            'execution_id' => $resultat->executionId,
            'url_id' => $resultat->urlId,
            'regle_id' => $resultat->regleId,
            'succes' => $resultat->succes ? 1 : 0,
            'severite' => $resultat->severite->value,
            'valeur_attendue' => $resultat->valeurAttendue,
            'valeur_obtenue' => $resultat->valeurObtenue,
            'message' => $resultat->message,
            'duree_ms' => $resultat->dureeMs,
            'details_json' => $resultat->detailsJson,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insertion en lot pour meilleures performances.
     *
     * @param ResultatRegle[] $resultats
     */
    public function creerEnLot(array $resultats): void
    {
        if (empty($resultats)) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO sm_resultats
                (execution_id, url_id, regle_id, succes, severite, valeur_attendue, valeur_obtenue, message, duree_ms, details_json)
            VALUES (:execution_id, :url_id, :regle_id, :succes, :severite, :valeur_attendue, :valeur_obtenue, :message, :duree_ms, :details_json)
        ');

        $this->db->beginTransaction();
        try {
            foreach ($resultats as $r) {
                $stmt->execute([
                    'execution_id' => $r->executionId,
                    'url_id' => $r->urlId,
                    'regle_id' => $r->regleId,
                    'succes' => $r->succes ? 1 : 0,
                    'severite' => $r->severite->value,
                    'valeur_attendue' => $r->valeurAttendue,
                    'valeur_obtenue' => $r->valeurObtenue,
                    'message' => $r->message,
                    'duree_ms' => $r->dureeMs,
                    'details_json' => $r->detailsJson,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * URLs avec le plus d'echecs sur les 7 derniers jours.
     *
     * @return array<int, array<string, mixed>>
     */
    public function urlsARisque(int $limite = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id AS url_id, u.url, u.libelle,
                   c.id AS client_id, c.nom AS client_nom,
                   COUNT(CASE WHEN r.succes = 0 THEN 1 END) AS nb_echecs,
                   MAX(r.severite) AS severite_max,
                   GROUP_CONCAT(DISTINCT CASE WHEN r.succes = 0 THEN r.message END) AS messages
            FROM sm_resultats r
            JOIN sm_urls u ON u.id = r.url_id
            JOIN sm_groupes_urls g ON g.id = u.groupe_id
            JOIN sm_clients c ON c.id = g.client_id
            JOIN sm_executions e ON e.id = r.execution_id
            WHERE e.cree_le >= datetime('now', '-7 days')
            GROUP BY u.id, u.url, u.libelle, c.id, c.nom
            HAVING nb_echecs > 0
            ORDER BY nb_echecs DESC
            LIMIT :limite
        ");
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Changements de contenu recents (regles changement_contenu en echec).
     *
     * @return array<int, array<string, mixed>>
     */
    public function changementsRecents(int $limite = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT r.url_id, u.url, u.libelle,
                   c.nom AS client_nom, c.id AS client_id,
                   r.message, r.valeur_obtenue, r.details_json, r.severite,
                   e.cree_le AS date_execution
            FROM sm_resultats r
            JOIN sm_regles reg ON reg.id = r.regle_id
            JOIN sm_urls u ON u.id = r.url_id
            JOIN sm_groupes_urls g ON g.id = u.groupe_id
            JOIN sm_clients c ON c.id = g.client_id
            JOIN sm_executions e ON e.id = r.execution_id
            WHERE reg.type_regle = 'changement_contenu' AND r.succes = 0
            ORDER BY e.cree_le DESC
            LIMIT :limite
        ");
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Resume par URL pour une execution donnee.
     *
     * @return array<int, array{url_id: int, url: string, total: int, succes: int, echecs: int}>
     */
    public function resumeParUrl(int $executionId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.url_id, u.url, u.libelle,
                   COUNT(*) AS total,
                   SUM(CASE WHEN r.succes = 1 THEN 1 ELSE 0 END) AS succes,
                   SUM(CASE WHEN r.succes = 0 THEN 1 ELSE 0 END) AS echecs
            FROM sm_resultats r
            JOIN sm_urls u ON u.id = r.url_id
            WHERE r.execution_id = :execution_id
            GROUP BY r.url_id, u.url, u.libelle
            ORDER BY echecs DESC, u.url ASC
        ');
        $stmt->execute(['execution_id' => $executionId]);
        return $stmt->fetchAll();
    }
}
