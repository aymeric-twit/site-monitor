<?php

declare(strict_types=1);

/**
 * Endpoint AJAX — Installe ou met a jour le schema de base de donnees.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'migrer';

    $db = Connexion::obtenir();
    $migrateur = new Migrateur($db);

    if ($action === 'statut') {
        echo json_encode([
            'donnees' => $migrateur->statut(),
            'message' => 'Statut des migrations',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $migrees = $migrateur->migrer();

    if (empty($migrees)) {
        echo json_encode([
            'donnees' => [],
            'message' => 'Base de donnees deja a jour.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'donnees' => $migrees,
            'message' => sprintf('%d migration(s) executee(s).', count($migrees)),
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
