<?php

declare(strict_types=1);

/**
 * Endpoint AJAX — Export CSV des resultats d'une execution.
 */

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotUrl;
use SiteMonitor\Stockage\DepotRegle;

$executionId = (int) ($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'csv';

if ($executionId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'id requis']);
    exit;
}

try {
    $db = Connexion::obtenir();
    (new Migrateur($db))->migrer();

    $depotResultat = new DepotResultatRegle($db);
    $depotUrl = new DepotUrl($db);
    $depotRegle = new DepotRegle($db);

    $resultats = $depotResultat->trouverParExecution($executionId);

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="resultats_' . $executionId . '.json"');
        echo json_encode(
            array_map(fn($r) => $r->versTableau(), $resultats),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }

    // CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resultats_' . $executionId . '.csv"');

    $sortie = fopen('php://output', 'w');
    // BOM UTF-8
    fwrite($sortie, "\xEF\xBB\xBF");

    // En-tete
    fputcsv($sortie, [
        'URL ID', 'URL', 'Regle ID', 'Regle', 'Succes', 'Severite',
        'Valeur attendue', 'Valeur obtenue', 'Message', 'Duree (ms)', 'Date'
    ], ';');

    // Cache des noms
    $cacheUrls = [];
    $cacheRegles = [];

    foreach ($resultats as $r) {
        // Resoudre le nom de l'URL
        if (!isset($cacheUrls[$r->urlId])) {
            $url = $depotUrl->trouverParId($r->urlId);
            $cacheUrls[$r->urlId] = $url?->url ?? '';
        }
        // Resoudre le nom de la regle
        if (!isset($cacheRegles[$r->regleId])) {
            $regle = $depotRegle->trouverParId($r->regleId);
            $cacheRegles[$r->regleId] = $regle?->nom ?? '';
        }

        fputcsv($sortie, [
            $r->urlId,
            $cacheUrls[$r->urlId],
            $r->regleId,
            $cacheRegles[$r->regleId],
            $r->succes ? 'OK' : 'ECHEC',
            $r->severite->value,
            $r->valeurAttendue ?? '',
            $r->valeurObtenue ?? '',
            $r->message ?? '',
            $r->dureeMs ?? '',
            $r->verifieLe ?? '',
        ], ';');
    }

    fclose($sortie);

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
