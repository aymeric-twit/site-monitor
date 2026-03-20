<?php

declare(strict_types=1);

namespace SiteMonitor\Stockage;

use SiteMonitor\Entite\MetriqueHttp;

/**
 * Depot pour l'entite MetriqueHttp.
 */
final class DepotMetriqueHttp
{
    public function __construct(private readonly \PDO $db) {}

    public function creer(MetriqueHttp $metrique): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sm_metriques_http
                (execution_id, url_id, code_http, temps_total_ms, ttfb_ms, temps_dns_ms,
                 temps_connexion_ms, temps_ssl_ms, taille_octets, url_finale,
                 nombre_redirections, en_tetes_json, infos_ssl_json)
            VALUES (:execution_id, :url_id, :code_http, :temps_total_ms, :ttfb_ms, :temps_dns_ms,
                    :temps_connexion_ms, :temps_ssl_ms, :taille_octets, :url_finale,
                    :nombre_redirections, :en_tetes_json, :infos_ssl_json)
        ');
        $stmt->execute([
            'execution_id' => $metrique->executionId,
            'url_id' => $metrique->urlId,
            'code_http' => $metrique->codeHttp,
            'temps_total_ms' => $metrique->tempsTotalMs,
            'ttfb_ms' => $metrique->ttfbMs,
            'temps_dns_ms' => $metrique->tempsDnsMs,
            'temps_connexion_ms' => $metrique->tempsConnexionMs,
            'temps_ssl_ms' => $metrique->tempsSslMs,
            'taille_octets' => $metrique->tailleOctets,
            'url_finale' => $metrique->urlFinale,
            'nombre_redirections' => $metrique->nombreRedirections,
            'en_tetes_json' => $metrique->enTetesJson,
            'infos_ssl_json' => $metrique->infosSslJson,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Serie temporelle des metriques pour une URL (pour graphiques).
     *
     * @return MetriqueHttp[]
     */
    public function trouverParUrl(int $urlId, int $limite = 100): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_metriques_http
            WHERE url_id = :url_id
            ORDER BY cree_le DESC
            LIMIT :limite
        ');
        $stmt->bindValue('url_id', $urlId, \PDO::PARAM_INT);
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $l): MetriqueHttp => MetriqueHttp::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Toutes les metriques d'une execution.
     *
     * @return MetriqueHttp[]
     */
    public function trouverParExecution(int $executionId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM sm_metriques_http
            WHERE execution_id = :execution_id
            ORDER BY url_id ASC
        ');
        $stmt->execute(['execution_id' => $executionId]);
        return array_map(fn(array $l): MetriqueHttp => MetriqueHttp::depuisLigne($l), $stmt->fetchAll());
    }

    /**
     * Moyennes TTFB et temps total par jour (30 jours), optionnel par client.
     *
     * @return array<int, array{jour: string, ttfb_moyen: float, temps_total_moyen: float}>
     */
    public function moyennesParJour(?int $clientId = null): array
    {
        if ($clientId !== null) {
            $sql = "
                SELECT DATE(m.cree_le) AS jour,
                       ROUND(AVG(m.ttfb_ms)) AS ttfb_moyen,
                       ROUND(AVG(m.temps_total_ms)) AS temps_total_moyen
                FROM sm_metriques_http m
                JOIN sm_executions e ON e.id = m.execution_id
                WHERE e.client_id = :client_id
                  AND m.cree_le >= " . \SiteMonitor\Core\Connexion::ilYA('-30 days') . "
                GROUP BY DATE(m.cree_le)
                ORDER BY jour ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['client_id' => $clientId]);
        } else {
            $sql = "
                SELECT DATE(m.cree_le) AS jour,
                       ROUND(AVG(m.ttfb_ms)) AS ttfb_moyen,
                       ROUND(AVG(m.temps_total_ms)) AS temps_total_moyen
                FROM sm_metriques_http m
                WHERE m.cree_le >= " . \SiteMonitor\Core\Connexion::ilYA('-30 days') . "
                GROUP BY DATE(m.cree_le)
                ORDER BY jour ASC
            ";
            $stmt = $this->db->query($sql);
        }
        return $stmt->fetchAll();
    }
}
