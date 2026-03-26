<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Planification;

/**
 * Depot pour l'entite Planification.
 */
final class DepotPlanification
{
    public function __construct(private readonly \PDO $db) {}

    public function creer(Planification $p): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_planifications
                (client_id, groupe_id, frequence_minutes, heure_debut, heure_fin,
                 jours_semaine, user_agent, timeout_secondes, delai_entre_requetes_ms,
                 actif, prochaine_execution)
            VALUES (:client_id, :groupe_id, :frequence_minutes, :heure_debut, :heure_fin,
                    :jours_semaine, :user_agent, :timeout_secondes, :delai_entre_requetes_ms,
                    :actif, :prochaine_execution)
        ');
        $stmt->execute([
            'client_id' => $p->clientId,
            'groupe_id' => $p->groupeId,
            'frequence_minutes' => $p->frequenceMinutes,
            'heure_debut' => $p->heureDebut,
            'heure_fin' => $p->heureFin,
            'jours_semaine' => $p->joursSemaine,
            'user_agent' => $p->userAgent,
            'timeout_secondes' => $p->timeoutSecondes,
            'delai_entre_requetes_ms' => $p->delaiEntreRequetesMs,
            'actif' => $p->actif ? 1 : 0,
            'prochaine_execution' => $p->prochaineExecution,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function modifier(Planification $p): void
    {
        $stmt = $this->db->prepare('
            UPDATE sm_planifications
            SET frequence_minutes = :frequence_minutes, heure_debut = :heure_debut,
                heure_fin = :heure_fin, jours_semaine = :jours_semaine,
                timeout_secondes = :timeout_secondes,
                delai_entre_requetes_ms = :delai_entre_requetes_ms,
                actif = :actif
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $p->id,
            'frequence_minutes' => $p->frequenceMinutes,
            'heure_debut' => $p->heureDebut,
            'heure_fin' => $p->heureFin,
            'jours_semaine' => $p->joursSemaine,
            'timeout_secondes' => $p->timeoutSecondes,
            'delai_entre_requetes_ms' => $p->delaiEntreRequetesMs,
            'actif' => $p->actif ? 1 : 0,
        ]);
    }

    public function supprimer(int $id): void
    {
        $this->db->prepare('DELETE FROM sm_planifications WHERE id = :id')->execute(['id' => $id]);
    }

    public function trouverParId(int $id): ?Planification
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_planifications WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        return $ligne ? Planification::depuisLigne($ligne) : null;
    }

    public function trouverParClient(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sm_planifications WHERE client_id = :client_id ORDER BY cree_le DESC');
        $stmt->execute(['client_id' => $clientId]);
        return array_map(fn(array $l) => Planification::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Trouve les planifications a executer maintenant.
     *
     * @return Planification[]
     */
    public function trouverDues(): array
    {
        $now = \SiteMonitor\Core\Connexion::estMysql() ? 'NOW()' : "datetime('now')";
        $stmt = $this->db->query("
            SELECT * FROM sm_planifications
            WHERE actif = 1 AND prochaine_execution <= {$now}
            ORDER BY prochaine_execution ASC
        ");
        return array_map(fn(array $l) => Planification::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Met a jour apres execution : derniere_execution = NOW, prochaine = NOW + frequence.
     */
    public function marquerExecutee(int $id, int $frequenceMinutes): void
    {
        if (\SiteMonitor\Core\Connexion::estMysql()) {
            $this->db->prepare("
                UPDATE sm_planifications
                SET derniere_execution = NOW(),
                    prochaine_execution = NOW() + INTERVAL :freq MINUTE
                WHERE id = :id
            ")->execute(['id' => $id, 'freq' => $frequenceMinutes]);
        } else {
            $this->db->prepare("
                UPDATE sm_planifications
                SET derniere_execution = datetime('now'),
                    prochaine_execution = datetime('now', '+' || :freq || ' minutes')
                WHERE id = :id
            ")->execute(['id' => $id, 'freq' => $frequenceMinutes]);
        }
    }
}
