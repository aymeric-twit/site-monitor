<?php

declare(strict_types=1);

/**
 * Endpoint AJAX — Retourne la progression d'un job worker.
 *
 * Lit progress.json et enrichit avec error.log si le worker a plante.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');

$jobId = $_GET['job'] ?? '';

if (!preg_match('#^[a-f0-9]{13,32}$#', $jobId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Job ID invalide']);
    exit;
}

$dossierJob = __DIR__ . '/data/jobs/' . $jobId;
$cheminProgression = $dossierJob . '/progress.json';

if (!file_exists($cheminProgression)) {
    http_response_code(404);
    echo json_encode(['error' => 'Job introuvable']);
    exit;
}

$contenu = file_get_contents($cheminProgression);
if ($contenu === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible de lire la progression']);
    exit;
}

$data = json_decode($contenu, true) ?? [];

// Si le worker semble bloque (status=starting/running mais pas mis a jour depuis +30s),
// verifier le fichier error.log pour des erreurs fatales PHP
if (in_array($data['status'] ?? '', ['starting', 'running'], true)) {
    $elapsed = $data['elapsed_sec'] ?? 0;
    $cheminErrorLog = $dossierJob . '/error.log';

    if (file_exists($cheminErrorLog)) {
        $errorLog = trim(file_get_contents($cheminErrorLog));
        if ($errorLog !== '') {
            // Le worker a ecrit des erreurs — les inclure dans la reponse
            $data['worker_log'] = mb_substr($errorLog, -2000);

            // Si le process semble mort (error.log non vide + pas de progression depuis 15s)
            $mtime = filemtime($cheminProgression);
            if ($mtime !== false && (time() - $mtime) > 15) {
                $data['status'] = 'error';
                $data['step'] = 'Worker crash : ' . mb_substr($errorLog, -500);
            }
        }
    }
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
