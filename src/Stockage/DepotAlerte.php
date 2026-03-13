<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\Alerte;

/**
 * Depot pour l'entite Alerte.
 */
final class DepotAlerte
{
    public function __construct(private readonly \PDO $db) {}

    public function creer(Alerte $alerte): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_alertes
                (execution_id, client_id, severite, sujet, corps_texte, destinataires, envoyee)
            VALUES (:execution_id, :client_id, :severite, :sujet, :corps_texte, :destinataires, :envoyee)
        ');
        $stmt->execute([
            'execution_id' => $alerte->executionId,
            'client_id' => $alerte->clientId,
            'severite' => $alerte->severite->value,
            'sujet' => $alerte->sujet,
            'corps_texte' => $alerte->corpsTexte,
            'destinataires' => $alerte->destinataires,
            'envoyee' => $alerte->envoyee ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return Alerte[]
     */
    public function trouverParExecution(int $executionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_alertes WHERE execution_id = :execution_id ORDER BY cree_le DESC'
        );
        $stmt->execute(['execution_id' => $executionId]);
        return array_map(fn(array $l): Alerte => Alerte::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * @return Alerte[]
     */
    public function trouverParClient(int $clientId, int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_alertes WHERE client_id = :client_id ORDER BY cree_le DESC LIMIT :limite'
        );
        $stmt->bindValue('client_id', $clientId, \PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $l): Alerte => Alerte::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * @return Alerte[]
     */
    public function trouverRecentes(int $limite = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sm_alertes ORDER BY cree_le DESC LIMIT :limite'
        );
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $l): Alerte => Alerte::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * @return Alerte[]
     */
    public function trouverNonEnvoyees(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM sm_alertes WHERE envoyee = 0 ORDER BY cree_le ASC'
        );
        return array_map(fn(array $l): Alerte => Alerte::depuisLigne($l), $stmt->fetchAll());
    }

    public function marquerEnvoyee(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sm_alertes SET envoyee = 1, envoyee_le = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function compterNonLues(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM sm_alertes WHERE envoyee = 0');
        return (int) $stmt->fetchColumn();
    }
}
