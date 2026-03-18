<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Executeur de migrations SQL avec support dual MySQL / SQLite.
 *
 * Lit les fichiers .sql du dossier migrations/ et les execute en ordre.
 * Maintient une table sm_migrations pour ne pas rejouer les migrations deja appliquees.
 */
final class Migrateur
{
    private \PDO $db;
    private string $dossierMigrations;

    public function __construct(\PDO $db, ?string $dossierMigrations = null)
    {
        $this->db = $db;
        $this->dossierMigrations = $dossierMigrations ?? dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * Execute toutes les migrations en attente.
     *
     * @return string[] Liste des fichiers migres
     */
    public function migrer(): array
    {
        $this->creerTableMigrations();
        $dejaExecutees = $this->migrationsExecutees();
        $fichiers = $this->fichiersMigrations();
        $migrees = [];

        foreach ($fichiers as $fichier) {
            $nom = basename($fichier);
            if (in_array($nom, $dejaExecutees, true)) {
                continue;
            }

            $sql = file_get_contents($fichier);
            if ($sql === false) {
                throw new \RuntimeException("Impossible de lire la migration : {$fichier}");
            }

            $sql = $this->adapterDialecte($sql);
            $this->executerSql($sql);
            $this->enregistrerMigration($nom);
            $migrees[] = $nom;
        }

        return $migrees;
    }

    /**
     * Retourne les migrations deja executees.
     *
     * @return string[]
     */
    public function migrationsExecutees(): array
    {
        $stmt = $this->db->query('SELECT nom FROM sm_migrations ORDER BY executee_le ASC');
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Retourne le statut de toutes les migrations.
     *
     * @return array<int, array{nom: string, executee: bool, date: ?string}>
     */
    public function statut(): array
    {
        $this->creerTableMigrations();
        $executees = [];
        $stmt = $this->db->query('SELECT nom, executee_le FROM sm_migrations');
        foreach ($stmt->fetchAll() as $ligne) {
            $executees[$ligne['nom']] = $ligne['executee_le'];
        }

        $resultat = [];
        foreach ($this->fichiersMigrations() as $fichier) {
            $nom = basename($fichier);
            $resultat[] = [
                'nom' => $nom,
                'executee' => isset($executees[$nom]),
                'date' => $executees[$nom] ?? null,
            ];
        }
        return $resultat;
    }

    /**
     * Cree la table de suivi des migrations si elle n'existe pas.
     */
    private function creerTableMigrations(): void
    {
        $estMysql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($estMysql) {
            $this->db->exec('
                CREATE TABLE IF NOT EXISTS sm_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nom VARCHAR(255) NOT NULL UNIQUE,
                    executee_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');
        } else {
            $this->db->exec('
                CREATE TABLE IF NOT EXISTS sm_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nom TEXT NOT NULL UNIQUE,
                    executee_le TEXT NOT NULL DEFAULT (datetime(\'now\'))
                )
            ');
        }
    }

    /**
     * Adapte le SQL MySQL vers SQLite si necessaire.
     */
    private function adapterDialecte(string $sql): string
    {
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return $sql;
        }

        // MySQL → SQLite adaptations
        // Supprimer ON UPDATE CURRENT_TIMESTAMP AVANT de toucher aux types
        $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $sql);

        // Supprimer ENGINE et CHARSET clauses
        $sql = preg_replace('/\)\s*ENGINE\s*=\s*InnoDB[^;]*/i', ')', $sql);
        $sql = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*utf8mb4\b/i', '', $sql);
        $sql = preg_replace('/\bCOLLATE\s*=\s*\w+/i', '', $sql);

        // Types de donnees
        $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\b/i', 'INTEGER', $sql);
        $sql = preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTO_INCREMENT\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $sql);
        $sql = preg_replace('/\bTINYINT\(\d+\)\b/i', 'INTEGER', $sql);
        $sql = preg_replace('/\bVARCHAR\(\d+\)\b/i', 'TEXT', $sql);
        $sql = preg_replace('/\bDATETIME\b/i', 'TEXT', $sql);
        $sql = preg_replace('/\bLONGBLOB\b/i', 'BLOB', $sql);
        $sql = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $sql);

        // Valeurs par defaut temporelles
        $sql = preg_replace('/\bDEFAULT\s+CURRENT_TIMESTAMP\b/i', "DEFAULT (datetime('now'))", $sql);

        // INSERT OR IGNORE pour MySQL IGNORE
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);

        return $sql;
    }

    /**
     * Execute un bloc SQL potentiellement multi-instructions.
     */
    private function executerSql(string $sql): void
    {
        // Decoupe par point-virgule en prenant en compte les lignes vides
        $instructions = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s): bool => $s !== ''
        );

        foreach ($instructions as $instruction) {
            try {
                $this->db->exec($instruction);
            } catch (\PDOException $e) {
                // Tolerer les erreurs "duplicate column" et "index already exists"
                // qui surviennent lors de la reprise apres un crash partiel
                $msg = strtolower($e->getMessage());
                if (
                    str_contains($msg, 'duplicate column')
                    || str_contains($msg, 'column already exists')
                    || str_contains($msg, 'already exists')
                    || str_contains($msg, 'duplicate key name')
                ) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Enregistre une migration comme executee.
     */
    private function enregistrerMigration(string $nom): void
    {
        $stmt = $this->db->prepare('INSERT INTO sm_migrations (nom) VALUES (:nom)');
        $stmt->execute(['nom' => $nom]);
    }

    /**
     * Retourne la liste triee des fichiers de migration.
     *
     * @return string[]
     */
    private function fichiersMigrations(): array
    {
        if (!is_dir($this->dossierMigrations)) {
            return [];
        }

        $fichiers = glob($this->dossierMigrations . '/*.sql');
        sort($fichiers);
        return $fichiers;
    }
}
