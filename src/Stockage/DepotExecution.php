<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Core\StatutExecution;
use SiteMonitor\Entite\Execution;

/**
 * Depot pour l'entite Execution.
 */
final class DepotExecution
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return Execution[]
     */
    public function trouverRecentes(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_executions ORDER BY cree_le DESC LIMIT :limite'
        );
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $l): Execution => Execution::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * @return Execution[]
     */
    public function trouverParClient(int $clientId, int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_executions WHERE client_id = :client_id ORDER BY cree_le DESC LIMIT :limite'
        );
        $stmt->bindValue('client_id', $clientId, \PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $l): Execution => Execution::depuisLigne($l), $stmt->fetchAll());
    }

    public function trouverParId(int $id): ?Execution
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_executions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Execution::depuisLigne($ligne) : null;
    }

    /**
     * Trouve la derniere execution terminee du meme client, anterieure a l'ID donne.
     */
    public function trouverPrecedente(int $executionId): ?Execution
    {
        $stmt = $this->db->prepare('
            SELECT e2.* FROM sm_executions e2
            INNER JOIN sm_executions e1 ON e1.id = :id AND e2.client_id = e1.client_id
            WHERE e2.id < :id2 AND e2.statut = :statut
            ORDER BY e2.id DESC LIMIT 1
        ');
        $stmt->execute([
            'id' => $executionId,
            'id2' => $executionId,
            'statut' => StatutExecution::Termine->value,
        ]);
        $ligne = $stmt->fetch();
        return $ligne ? Execution::depuisLigne($ligne) : null;
    }

    public function creer(Execution $execution): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_executions
                (client_id, groupe_id, type_declencheur, statut, urls_total, demarree_le)
            VALUES (:client_id, :groupe_id, :type_declencheur, :statut, :urls_total, :demarree_le)
        ');
        $stmt->execute([
            'client_id' => $execution->clientId,
            'groupe_id' => $execution->groupeId,
            'type_declencheur' => $execution->typeDeclencheur,
            'statut' => $execution->statut->value,
            'urls_total' => $execution->urlsTotal,
            'demarree_le' => $execution->demarreeLe,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function mettreAJourProgression(
        int $id,
        int $urlsTraitees,
        int $reglesTotal,
        int $succes,
        int $echecs,
        int $avertissements,
    ): void {
        $stmt = $this->db->prepare('
            UPDATE sm_executions
            SET urls_traitees = :urls_traitees, regles_total = :regles_total,
                succes = :succes, echecs = :echecs, avertissements = :avertissements
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $id,
            'urls_traitees' => $urlsTraitees,
            'regles_total' => $reglesTotal,
            'succes' => $succes,
            'echecs' => $echecs,
            'avertissements' => $avertissements,
        ]);
    }

    public function terminer(int $id, StatutExecution $statut, int $dureeMs): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_executions
            SET statut = :statut, duree_ms = :duree_ms, terminee_le = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $id,
            'statut' => $statut->value,
            'duree_ms' => $dureeMs,
        ]);
    }

    /**
     * Retourne les stats globales pour le dashboard.
     *
     * @return array{total: int, en_cours: int, dernieres_24h: int, taux_reussite: float}
     */
    public function statistiquesGlobales(): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM sm_executions')->fetchColumn();

        $enCours = (int) $this->db->query(
            "SELECT COUNT(*) FROM sm_executions WHERE statut = 'en_cours'"
        )->fetchColumn();

        // Dernieres 24h
        $stmt24h = $this->db->query("
            SELECT COUNT(*) FROM sm_executions
            WHERE cree_le >= datetime('now', '-24 hours')
        ");
        $dernieres24h = (int) $stmt24h->fetchColumn();

        // Taux de reussite moyen des executions terminees
        $stmtTaux = $this->db->query("
            SELECT AVG(CASE WHEN (succes + echecs + avertissements) > 0
                THEN (succes * 100.0 / (succes + echecs + avertissements))
                ELSE 0 END) AS taux
            FROM sm_executions WHERE statut = 'termine'
        ");
        $taux = (float) ($stmtTaux->fetchColumn() ?: 0);

        return [
            'total' => $total,
            'en_cours' => $enCours,
            'dernieres_24h' => $dernieres24h,
            'taux_reussite' => round($taux, 1),
        ];
    }

    /**
     * Tendances sur 30 jours : executions par jour, taux moyen, duree moyenne.
     *
     * @return array<int, array{jour: string, nb_executions: int, taux_moyen: float, duree_moyenne: float}>
     */
    public function tendances30Jours(?int $clientId = null): array
    {
        $sql = "
            SELECT DATE(cree_le) AS jour,
                   COUNT(*) AS nb_executions,
                   AVG(CASE WHEN (succes + echecs + avertissements) > 0
                       THEN succes * 100.0 / (succes + echecs + avertissements) ELSE NULL END) AS taux_moyen,
                   AVG(duree_ms) AS duree_moyenne
            FROM sm_executions
            WHERE cree_le >= datetime('now', '-30 days')
        ";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        $sql .= ' GROUP BY DATE(cree_le) ORDER BY jour ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Historique des N derniers taux de reussite pour un client (sparkline).
     *
     * @return array<int, float>
     */
    public function historiqueTauxParClient(int $clientId, int $limite = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT CASE WHEN (succes + echecs + avertissements) > 0
                THEN ROUND(succes * 100.0 / (succes + echecs + avertissements), 1) ELSE 0 END AS taux
            FROM sm_executions
            WHERE client_id = :client_id AND statut = 'termine'
            ORDER BY cree_le DESC LIMIT :limite
        ");
        $stmt->bindValue('client_id', $clientId, \PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        $resultats = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        // Inverser pour avoir l'ordre chronologique (ancien → recent)
        return array_map('floatval', array_reverse($resultats));
    }

    public function supprimer(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sm_executions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Purge les executions plus anciennes que X jours.
     */
    public function purger(int $joursRetention = 90): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM sm_executions
            WHERE cree_le < datetime('now', :jours || ' days')
        ");
        $stmt->execute(['jours' => -$joursRetention]);
        return $stmt->rowCount();
    }
}
