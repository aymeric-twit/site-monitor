<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

/**
 * Service reutilisable pour lancer une verification en arriere-plan.
 *
 * Utilise par api.php (declenchement web) et cron.php (declenchement planifie).
 */
final class LanceurVerification
{
    public function __construct(
        private readonly \PDO $db,
        private readonly string $racineModule,
    ) {}

    /**
     * Lance un worker en arriere-plan et retourne le job_id.
     *
     * @param array{client_id: int, groupe_id?: ?int, user_agent?: ?string, timeout?: int, delai_ms?: int, type_declencheur?: string} $options
     */
    public function lancer(array $options): string
    {
        $clientId = (int) ($options['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new \InvalidArgumentException('client_id requis');
        }

        $jobId = bin2hex(random_bytes(8));
        $dossierJob = $this->racineModule . '/data/jobs/' . $jobId;
        if (!is_dir($dossierJob)) {
            mkdir($dossierJob, 0755, true);
        }

        $configJob = [
            'client_id' => $clientId,
            'groupe_id' => $options['groupe_id'] ?? null,
            'user_agent' => $options['user_agent'] ?? null,
            'timeout' => (int) ($options['timeout'] ?? 30),
            'delai_entre_requetes_ms' => (int) ($options['delai_ms'] ?? 1000),
            'type_declencheur' => $options['type_declencheur'] ?? 'manuel',
        ];

        // Lire le crawler_mode depuis module.json
        $moduleJson = $this->racineModule . '/module.json';
        if (file_exists($moduleJson)) {
            $moduleConfig = json_decode(file_get_contents($moduleJson), true);
            if (!empty($moduleConfig['crawler_mode'])) {
                $configJob['crawler_mode'] = $moduleConfig['crawler_mode'];
            }
        }

        // Propager les infos DB si MySQL
        $pilote = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($pilote === 'mysql') {
            $configJob['db'] = [
                'type' => 'mysql',
                'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
                'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
                'name' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '',
                'user' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
                'pass' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
            ];
        }

        file_put_contents(
            $dossierJob . '/config.json',
            json_encode($configJob, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        file_put_contents(
            $dossierJob . '/progress.json',
            json_encode(['status' => 'starting', 'percent' => 0, 'step' => 'Demarrage...'])
        );

        // Resoudre PHP CLI
        $phpBin = self::resoudrePhpCli();
        $workerPath = $this->racineModule . '/worker.php';
        $errorLog = $dossierJob . '/error.log';
        $cmd = sprintf(
            '%s %s --job=%s > %s 2>&1 &',
            $phpBin,
            escapeshellarg($workerPath),
            $jobId,
            escapeshellarg($errorLog)
        );
        exec($cmd);

        return $jobId;
    }

    /**
     * Resout le chemin vers le binaire PHP CLI.
     */
    public static function resoudrePhpCli(): string
    {
        $binary = PHP_BINARY;
        if (!str_contains($binary, 'fpm') && !str_contains($binary, 'cgi')) {
            return $binary;
        }

        if (preg_match('/php-fpm(\d+\.\d+)/', $binary, $m)) {
            $candidates = [
                '/usr/bin/php' . $m[1],
                '/usr/local/bin/php' . $m[1],
                '/usr/bin/php',
            ];
        } else {
            $candidates = ['/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1', '/usr/bin/php'];
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim(shell_exec('which php 2>/dev/null') ?? '');
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return $binary;
    }
}
