<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Gestionnaire de connexion PDO dual : MySQL (plateforme) ou SQLite (standalone).
 *
 * En mode plateforme, utilise la connexion partagee.
 * En mode standalone, cree une connexion locale selon la configuration.
 */
final class Connexion
{
    private static ?\PDO $instance = null;
    private static string $pilote = 'sqlite';

    private function __construct() {}

    /**
     * Retourne l'instance PDO partagee.
     */
    public static function obtenir(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Mode plateforme : reutiliser la connexion existante
        if (defined('PLATFORM_EMBEDDED') || defined('PLATFORM_IFRAME')) {
            self::$instance = self::obtenirConnexionPlateforme();
            self::$pilote = self::$instance->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return self::$instance;
        }

        // Mode standalone : creer une connexion locale
        self::$instance = self::creerConnexionStandalone();
        return self::$instance;
    }

    /**
     * Retourne le pilote actif ('mysql' ou 'sqlite').
     */
    public static function pilote(): string
    {
        if (self::$instance === null) {
            self::obtenir();
        }
        return self::$pilote;
    }

    /**
     * Est-ce que la connexion utilise MySQL ?
     */
    public static function estMysql(): bool
    {
        return self::pilote() === 'mysql';
    }

    /**
     * Expression SQL pour "maintenant moins X unités de temps".
     * Compatible SQLite et MySQL/MariaDB.
     *
     * @param string $intervalle Ex: '-24 hours', '-7 days', '-30 days'
     */
    public static function ilYA(string $intervalle): string
    {
        if (self::estMysql()) {
            // Convertir '-24 hours' → 'INTERVAL 24 HOUR', '-7 days' → 'INTERVAL 7 DAY'
            if (preg_match('/^-(\d+)\s+(hour|day|month|minute|week)s?$/i', $intervalle, $m)) {
                return 'NOW() - INTERVAL ' . $m[1] . ' ' . strtoupper($m[2]);
            }
            return 'NOW()';
        }

        return "datetime('now', '{$intervalle}')";
    }

    /**
     * Injecte une connexion PDO (utile pour les tests).
     */
    public static function definir(\PDO $pdo): void
    {
        self::$instance = $pdo;
        self::$pilote = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Reinitialise la connexion (utile pour les tests).
     */
    public static function reinitialiser(): void
    {
        self::$instance = null;
        self::$pilote = 'sqlite';
    }

    /**
     * Recupere la connexion PDO de la plateforme SEO.
     */
    private static function obtenirConnexionPlateforme(): \PDO
    {
        // La plateforme expose sa connexion via une classe globale
        if (class_exists('\\Platform\\Database\\Connection')) {
            /** @var \PDO $pdo */
            $pdo = \Platform\Database\Connection::get();
            return $pdo;
        }

        // Fallback : variable globale $pdo
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            return $GLOBALS['pdo'];
        }

        throw new \RuntimeException(
            'Impossible de trouver la connexion PDO de la plateforme. '
            . 'Verifiez que le module est correctement integre.'
        );
    }

    /**
     * Cree une connexion standalone depuis les variables d'environnement ou .env.
     */
    private static function creerConnexionStandalone(): \PDO
    {
        // Charger .env si vlucas/phpdotenv est present
        $dotenvChemin = dirname(__DIR__, 2) . '/.env';
        if (file_exists($dotenvChemin) && class_exists('\\Dotenv\\Dotenv')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $type = self::env('SITE_MONITOR_DB_TYPE', 'sqlite');

        if ($type === 'mysql') {
            return self::creerConnexionMysql();
        }

        return self::creerConnexionSqlite();
    }

    private static function creerConnexionMysql(): \PDO
    {
        $hote = self::env('SITE_MONITOR_DB_HOST', '127.0.0.1');
        $nom = self::env('SITE_MONITOR_DB_NAME', 'site_monitor');
        $utilisateur = self::env('SITE_MONITOR_DB_USER', 'root');
        $motDePasse = self::env('SITE_MONITOR_DB_PASS', '');
        $port = self::env('SITE_MONITOR_DB_PORT', '3306');

        $dsn = "mysql:host={$hote};port={$port};dbname={$nom};charset=utf8mb4";
        $pdo = new \PDO($dsn, $utilisateur, $motDePasse, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pilote = 'mysql';
        return $pdo;
    }

    private static function creerConnexionSqlite(): \PDO
    {
        $chemin = self::env('SITE_MONITOR_DB_PATH', 'data/site-monitor.sqlite');

        // Chemin relatif : depuis la racine du module
        if (!str_starts_with($chemin, '/')) {
            $chemin = dirname(__DIR__, 2) . '/' . $chemin;
        }

        // Creer le repertoire parent si necessaire
        $repertoire = dirname($chemin);
        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0755, true);
        }

        $pdo = new \PDO("sqlite:{$chemin}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // Activer les cles etrangeres et le mode WAL pour SQLite
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        self::$pilote = 'sqlite';
        return $pdo;
    }

    private static function env(string $cle, string $defaut = ''): string
    {
        return $_ENV[$cle] ?? getenv($cle) ?: $defaut;
    }
}
