<?php

declare(strict_types=1);

/**
 * Export CSV des resultats d'un audit d'indexation.
 *
 * Usage : GET download-indexation.php?audit_id=<id>
 */

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Stockage\DepotAuditIndexation;
use SiteMonitor\Stockage\DepotResultatIndexation;

try {
    $auditId = (int) ($_GET['audit_id'] ?? 0);
    if ($auditId <= 0) {
        http_response_code(400);
        echo 'audit_id requis';
        exit;
    }

    $db = Connexion::obtenir();
    (new Migrateur($db))->migrer();

    $depotAudit = new DepotAuditIndexation($db);
    $audit = $depotAudit->trouverParId($auditId);

    if ($audit === null) {
        http_response_code(404);
        echo 'Audit introuvable';
        exit;
    }

    $depotResultat = new DepotResultatIndexation($db);
    $resultats = $depotResultat->listerParAudit($auditId);

    // Envoyer le CSV
    $nomFichier = sprintf('audit-indexation-%s-%s.csv', $audit->domaine, date('Y-m-d'));
    $nomFichier = preg_replace('/[^a-zA-Z0-9._-]/', '_', $nomFichier);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('X-Content-Type-Options: nosniff');

    $sortie = fopen('php://output', 'w');

    // BOM UTF-8 pour Excel
    fwrite($sortie, "\xEF\xBB\xBF");

    // En-tetes
    fputcsv($sortie, [
        'URL',
        'Code HTTP',
        'URL Finale',
        'Meta Robots',
        'X-Robots-Tag',
        'Canonical',
        'Canonical Auto-ref',
        'Robots.txt Autorise',
        'Regle Robots.txt',
        'Present Sitemap',
        'Statut Indexation',
        'Contradictions',
        'Severite Max',
        'Verifie Le',
    ]);

    foreach ($resultats as $r) {
        $contradictionsTexte = '';
        if ($r->contradictions !== null) {
            $messages = array_map(fn(array $c) => $c['message'] ?? $c['type'] ?? '', $r->contradictions);
            $contradictionsTexte = implode(' | ', $messages);
        }

        fputcsv($sortie, [
            $r->url,
            $r->codeHttp,
            $r->urlFinale ?? '',
            $r->metaRobots ?? '',
            $r->xRobotsTag ?? '',
            $r->canonical ?? '',
            $r->canonicalAutoReference === true ? 'Oui' : ($r->canonicalAutoReference === false ? 'Non' : ''),
            $r->robotsTxtAutorise === true ? 'Oui' : ($r->robotsTxtAutorise === false ? 'Non' : ''),
            $r->robotsTxtRegle ?? '',
            $r->presentSitemap === true ? 'Oui' : ($r->presentSitemap === false ? 'Non' : ''),
            $r->statutIndexation,
            $contradictionsTexte,
            $r->severiteMax ?? '',
            $r->verifieLe ?? '',
        ]);
    }

    fclose($sortie);

} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Erreur : ' . $e->getMessage();
}
