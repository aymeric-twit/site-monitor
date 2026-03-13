<?php

declare(strict_types=1);

/**
 * Endpoint AJAX — Retourne la progression d'un job worker.
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

$cheminProgression = __DIR__ . '/data/jobs/' . $jobId . '/progress.json';

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

echo $contenu;
