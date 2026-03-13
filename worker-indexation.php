<?php

declare(strict_types=1);

/**
 * Worker CLI — Execute les audits d'indexation en arriere-plan.
 *
 * Usage :
 *   php worker-indexation.php --job=<jobId>
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Core\ProgressionJob;
use SiteMonitor\Indexation\AnalyseurSignaux;
use SiteMonitor\Indexation\DetecteurContradictions;
use SiteMonitor\Indexation\MoteurIndexation;
use SiteMonitor\Moteur\ClientHttp;
use SiteMonitor\Stockage\DepotAuditIndexation;
use SiteMonitor\Stockage\DepotResultatIndexation;

// --- Parsing des arguments CLI ---
$options = getopt('', ['job:', 'verbose', 'quiet']);
$quiet = isset($options['quiet']);

function logIndexation(string $message, bool $quiet = false): void
{
    if ($quiet) {
        return;
    }
    $horodatage = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$horodatage}] [indexation] {$message}" . PHP_EOL);
}

if (!isset($options['job'])) {
    logIndexation('Usage : php worker-indexation.php --job=<jobId>', $quiet);
    exit(1);
}

$jobId = $options['job'];

if (!preg_match('#^[a-f0-9]{13,32}$#', $jobId)) {
    logIndexation('Job ID invalide.', $quiet);
    exit(2);
}

$dossierJob = __DIR__ . '/data/jobs/' . $jobId;
if (!is_dir($dossierJob)) {
    logIndexation("Dossier job introuvable : {$dossierJob}", $quiet);
    exit(2);
}

$configJob = json_decode(file_get_contents($dossierJob . '/config.json'), true);
if (!$configJob) {
    logIndexation('Configuration job invalide.', $quiet);
    exit(2);
}

$progression = new ProgressionJob($dossierJob);
$progression->avancer(0, 'Initialisation...');

try {
    $db = Connexion::obtenir();
    (new Migrateur($db))->migrer();

    $progression->avancer(5, 'Connexion base de donnees OK');

    $depotAudit = new DepotAuditIndexation($db);
    $depotResultat = new DepotResultatIndexation($db);

    $auditId = (int) $configJob['audit_id'];
    $urls = $configJob['urls'] ?? [];
    $domaine = $configJob['domaine'] ?? '';
    $delaiMs = (int) ($configJob['delai_ms'] ?? 500);

    if ($urls === [] || $domaine === '') {
        throw new \RuntimeException('URLs ou domaine manquants dans la configuration du job.');
    }

    $progression->avancer(10, sprintf('%d URLs a auditer', count($urls)));

    // Configurer le client HTTP
    $clientHttp = new ClientHttp();
    if (isset($configJob['user_agent'])) {
        $clientHttp->definirUserAgent($configJob['user_agent']);
    }
    $clientHttp->definirTimeout((int) ($configJob['timeout'] ?? 30));

    // Instancier le moteur
    $moteur = new MoteurIndexation(
        clientHttp: $clientHttp,
        depotAudit: $depotAudit,
        depotResultat: $depotResultat,
        analyseurSignaux: new AnalyseurSignaux(),
        detecteurContradictions: new DetecteurContradictions(),
    );

    // Lancer l'audit avec callback de progression
    $moteur->auditer(
        auditId: $auditId,
        urls: $urls,
        domaine: $domaine,
        surProgression: function (int $traitees, int $total, string $etape) use ($progression) {
            $pourcent = $total > 0 ? (int) (10 + ($traitees / $total) * 85) : 10;
            $progression->avancer($pourcent, "({$traitees}/{$total}) {$etape}");
        },
        delaiMs: $delaiMs,
    );

    // Recuperer les stats finales
    $audit = $depotAudit->trouverParId($auditId);
    $progression->terminer([
        'audit_id' => $auditId,
        'urls_traitees' => $audit?->urlsTraitees ?? count($urls),
        'indexables' => $audit?->urlsIndexables ?? 0,
        'non_indexables' => $audit?->urlsNonIndexables ?? 0,
        'contradictoires' => $audit?->urlsContradictoires ?? 0,
    ]);

    logIndexation("Audit #{$auditId} termine.", $quiet);
    exit(0);

} catch (\Throwable $e) {
    $progression->erreur($e->getMessage());
    logIndexation('ERREUR : ' . $e->getMessage(), $quiet);

    // Marquer l'audit en erreur si possible
    if (isset($depotAudit, $auditId)) {
        try {
            $depotAudit->marquerErreur($auditId, $e->getMessage());
        } catch (\Throwable) {
            // Ignorer les erreurs de mise a jour
        }
    }

    exit(2);
}
