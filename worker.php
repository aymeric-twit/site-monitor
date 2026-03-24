<?php

declare(strict_types=1);

/**
 * Worker CLI — Execute les verifications en arriere-plan.
 *
 * Usage :
 *   php worker.php --job=<jobId>
 *   php worker.php --config=config.json --run
 *   php worker.php --config=config.json --client=<slug>
 *   php worker.php --config=config.json --group=<id>
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Core\ProgressionJob;
use SiteMonitor\Core\StatutExecution;
use SiteMonitor\Entite\Execution;
use SiteMonitor\Moteur\ClientHttp;
use SiteMonitor\Moteur\MoteurVerification;
use SiteMonitor\Moteur\RegistreVerificateurs;
use SiteMonitor\Moteur\ExpeditionAlertes;
use SiteMonitor\Moteur\GenerateurAlertes;
use SiteMonitor\Stockage\DepotAlerte;
use SiteMonitor\Stockage\DepotClient;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotGroupeUrls;
use SiteMonitor\Stockage\DepotMetriqueHttp;
use SiteMonitor\Stockage\DepotRegle;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotSnapshot;
use SiteMonitor\Stockage\DepotUrl;

// --- Parsing des arguments CLI ---
$options = getopt('', [
    'job:',
    'config:',
    'run',
    'client:',
    'group:',
    'url:',
    'verbose',
    'quiet',
]);

$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

function logWorker(string $message, bool $verbose = false, bool $quiet = false): void
{
    if ($quiet) {
        return;
    }
    $horodatage = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$horodatage}] {$message}" . PHP_EOL);
}

// --- Mode Job (lance depuis l'interface web) ---
if (isset($options['job'])) {
    $jobId = $options['job'];

    if (!preg_match('#^[a-f0-9]{13,32}$#', $jobId)) {
        logWorker('Job ID invalide.', quiet: $quiet);
        exit(2);
    }

    $dossierJob = __DIR__ . '/data/jobs/' . $jobId;
    if (!is_dir($dossierJob)) {
        logWorker("Dossier job introuvable : {$dossierJob}", quiet: $quiet);
        exit(2);
    }

    $configJob = json_decode(file_get_contents($dossierJob . '/config.json'), true);
    if (!$configJob) {
        logWorker('Configuration job invalide.', quiet: $quiet);
        exit(2);
    }

    $progression = new ProgressionJob($dossierJob);
    $progression->avancer(0, 'Initialisation...');

    try {
        // Si la config contient les credentials DB (lance depuis la plateforme),
        // injecter la connexion MySQL avant d'appeler Connexion::obtenir()
        if (isset($configJob['db']) && $configJob['db']['type'] === 'mysql') {
            $dbConf = $configJob['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbConf['host'], $dbConf['port'], $dbConf['name']
            );
            $pdo = new \PDO($dsn, $dbConf['user'], $dbConf['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            Connexion::definir($pdo);
            logWorker('Connexion MySQL injectee depuis config job.', quiet: $quiet);
        }

        $db = Connexion::obtenir();

        // S'assurer que les tables existent
        try {
            $migrateur = new Migrateur($db);
            $migrateur->migrer();
        } catch (\Throwable $eMigration) {
            logWorker('Migration non-bloquante : ' . $eMigration->getMessage(), quiet: $quiet);
        }

        $progression->avancer(5, 'Connexion base de donnees OK');

        $depotUrl = new DepotUrl($db);
        $depotRegle = new DepotRegle($db);
        $depotExecution = new DepotExecution($db);
        $depotResultat = new DepotResultatRegle($db);
        $depotSnapshot = new DepotSnapshot($db);
        $depotMetrique = new DepotMetriqueHttp($db);
        $depotClient = new DepotClient($db);
        $depotAlerte = new DepotAlerte($db);

        // Creer l'execution
        $executionId = $depotExecution->creer(new Execution(
            id: null,
            clientId: $configJob['client_id'] ?? null,
            groupeId: $configJob['groupe_id'] ?? null,
            typeDeclencheur: 'manuel',
            statut: StatutExecution::EnCours,
            urlsTotal: 0,
            urlsTraitees: 0,
            reglesTotal: 0,
            succes: 0,
            echecs: 0,
            avertissements: 0,
            dureeMs: null,
            demarreeLe: date('Y-m-d H:i:s'),
            termineeLe: null,
            creeLe: null,
        ));

        // Charger les URLs a verifier
        $urls = [];
        if (isset($configJob['url_ids']) && is_array($configJob['url_ids'])) {
            foreach ($configJob['url_ids'] as $urlId) {
                $url = $depotUrl->trouverParId((int) $urlId);
                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        } elseif (isset($configJob['client_id'])) {
            $urls = $depotUrl->trouverActivesParClient((int) $configJob['client_id']);
        }

        $depotExecution->mettreAJourProgression($executionId, 0, 0, 0, 0, 0);

        // Mettre a jour le total
        $db->prepare('UPDATE sm_executions SET urls_total = :total WHERE id = :id')
            ->execute(['total' => count($urls), 'id' => $executionId]);

        $progression->avancer(10, sprintf('%d URLs a verifier', count($urls)));

        // Configurer le client HTTP
        $clientHttp = new ClientHttp();
        if (isset($configJob['user_agent'])) {
            $clientHttp->definirUserAgent($configJob['user_agent']);
        }
        if (isset($configJob['timeout'])) {
            $clientHttp->definirTimeout((int) $configJob['timeout']);
        }

        // Configurer et lancer le moteur
        $registre = RegistreVerificateurs::parDefaut();
        $moteur = new MoteurVerification(
            registre: $registre,
            clientHttp: $clientHttp,
            depotUrl: $depotUrl,
            depotRegle: $depotRegle,
            depotExecution: $depotExecution,
            depotResultat: $depotResultat,
            depotSnapshot: $depotSnapshot,
            depotMetrique: $depotMetrique,
        );

        $moteur->surProgression(function (int $traitees, int $total, string $urlCourante) use ($progression) {
            $pourcent = $total > 0 ? (int) (10 + ($traitees / $total) * 85) : 10;
            $progression->avancer($pourcent, "({$traitees}/{$total}) {$urlCourante}");
        });

        $delai = (int) ($configJob['delai_entre_requetes_ms'] ?? 1000);
        $moteur->executer($urls, $executionId, $delai);

        // Generer les alertes si des echecs sont detectes
        $generateurAlertes = new GenerateurAlertes(
            depotResultat: $depotResultat,
            depotExecution: $depotExecution,
            depotClient: $depotClient,
            depotAlerte: $depotAlerte,
            depotUrl: $depotUrl,
        );
        $generateurAlertes->generer($executionId);

        // Envoyer les alertes par email
        $expeditionAlertes = new ExpeditionAlertes(depotAlerte: $depotAlerte);
        $nbEnvoyees = $expeditionAlertes->expedierEnAttente();
        if ($nbEnvoyees > 0) {
            logWorker("{$nbEnvoyees} alerte(s) envoyee(s) par email.", quiet: $quiet);
        }

        // Recuperer les stats finales
        $execution = $depotExecution->trouverParId($executionId);
        $progression->terminer([
            'execution_id' => $executionId,
            'urls_traitees' => $execution?->urlsTraitees ?? count($urls),
            'succes' => $execution?->succes ?? 0,
            'echecs' => $execution?->echecs ?? 0,
            'avertissements' => $execution?->avertissements ?? 0,
            'duree_ms' => $execution?->dureeMs ?? 0,
        ]);

        logWorker("Execution #{$executionId} terminee.", quiet: $quiet);
        exit(0);

    } catch (\Throwable $e) {
        $progression->erreur($e->getMessage());
        logWorker('ERREUR : ' . $e->getMessage(), quiet: $quiet);
        exit(2);
    }
}

// --- Mode standalone (CLI directe) ---
logWorker('Mode standalone non encore implemente. Utiliser --job=<id>.', quiet: $quiet);
exit(1);
