<?php

declare(strict_types=1);

/**
 * Page de resultats d'une execution (route type: page = affichee dans le layout plateforme).
 */

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotResultatRegle;

$executionId = (int) ($_GET['id'] ?? 0);
$db = Connexion::obtenir();
(new Migrateur($db))->migrer();

$depotExecution = new DepotExecution($db);
$depotResultat = new DepotResultatRegle($db);

$execution = $executionId > 0 ? $depotExecution->trouverParId($executionId) : null;
$resumeUrls = $executionId > 0 ? $depotResultat->resumeParUrl($executionId) : [];

$baseUrl = defined('PLATFORM_EMBEDDED') ? '/m/site-monitor' : '.';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultats — Site Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-dark">
        <div class="container">
            <span class="navbar-brand">Site Monitor <span>Resultats</span></span>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($execution === null): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-exclamation-circle" style="font-size: 2.5rem; color: var(--text-muted);"></i>
                    <p class="mt-3 text-muted">Execution introuvable.</p>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/" class="btn btn-primary mt-2">
                        <i class="bi bi-arrow-left"></i> Retour au dashboard
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- En-tete de l'execution -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="mb-1">
                        <i class="bi bi-clipboard-check"></i>
                        Execution #<?= $execution->id ?>
                    </h5>
                    <small class="text-muted">
                        <?= htmlspecialchars($execution->demarreeLe ?? $execution->creeLe ?? '') ?>
                        — Duree : <?= $execution->dureeMs !== null ? number_format($execution->dureeMs / 1000, 1) . 's' : 'N/A' ?>
                    </small>
                </div>
                <div>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="bi bi-arrow-left"></i> Retour
                    </a>
                    <button onclick="exporterResultatsCsv()" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Exporter CSV
                    </button>
                </div>
            </div>

            <!-- KPIs de l'execution -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card kpi-card kpi-teal">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= $execution->urlsTraitees ?>/<?= $execution->urlsTotal ?></div>
                            <div class="kpi-label">URLs traitees</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card kpi-card kpi-green">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= $execution->succes ?></div>
                            <div class="kpi-label">Succes</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card kpi-card kpi-red">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= $execution->echecs ?></div>
                            <div class="kpi-label">Echecs</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card kpi-card kpi-gold">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= $execution->tauxReussite() ?>%</div>
                            <div class="kpi-label">Taux de reussite</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statut -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-list-check"></i> Resume par URL</strong>
                    <span class="badge bg-<?= $execution->statut->classeCss() ?>">
                        <?= htmlspecialchars($execution->statut->libelle()) ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($resumeUrls)): ?>
                        <div class="text-center py-4 text-muted">Aucun resultat.</div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Succes</th>
                                    <th class="text-center">Echecs</th>
                                    <th class="text-center">Taux</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resumeUrls as $r): ?>
                                    <?php
                                    $total = (int) $r['total'];
                                    $succes = (int) $r['succes'];
                                    $echecs = (int) $r['echecs'];
                                    $taux = $total > 0 ? round(($succes / $total) * 100) : 0;
                                    $classe = $echecs === 0 ? 'success' : ($taux >= 50 ? 'warning' : 'danger');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="text-truncate" style="max-width: 400px;" title="<?= htmlspecialchars($r['url']) ?>">
                                                <?= htmlspecialchars($r['libelle'] ?? $r['url']) ?>
                                            </div>
                                            <?php if (!empty($r['libelle'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($r['url']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= $total ?></td>
                                        <td class="text-center text-success fw-600"><?= $succes ?></td>
                                        <td class="text-center text-danger fw-600"><?= $echecs ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $classe ?>"><?= $taux ?>%</span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary"
                                                    onclick="voirDetailUrl(<?= $execution->id ?>, <?= (int) $r['url_id'] ?>)">
                                                <i class="bi bi-eye"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detail par URL (charge dynamiquement) -->
            <div id="detailUrl" class="card d-none">
                <div class="card-header">
                    <strong><i class="bi bi-search"></i> Detail des regles</strong>
                    <span id="detailUrlNom" class="ms-2 text-muted"></span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Regle</th>
                                <th>Resultat</th>
                                <th>Severite</th>
                                <th>Attendu</th>
                                <th>Obtenu</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody id="detailUrlCorps"></tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="translations.js"></script>
    <script>
        const baseUrl = '<?= $baseUrl ?>';
        const executionId = <?= $executionId ?>;

        function voirDetailUrl(execId, urlId) {
            fetch(baseUrl + '/api.php?entite=resultat&action=par_url&execution_id=' + execId + '&url_id=' + urlId)
                .then(r => r.json())
                .then(data => {
                    const resultats = data.donnees || [];
                    const corps = document.getElementById('detailUrlCorps');
                    corps.innerHTML = '';

                    resultats.forEach(r => {
                        const classeSeverite = {
                            'info': 'info',
                            'avertissement': 'warning',
                            'erreur': 'danger',
                            'critique': 'danger'
                        }[r.severite] || 'secondary';

                        const classeResultat = r.succes ? 'success' : 'danger';
                        const icone = r.succes ? 'bi-check-circle-fill' : 'bi-x-circle-fill';

                        corps.innerHTML += '<tr>' +
                            '<td>' + (r.regle_id || '') + '</td>' +
                            '<td><i class="bi ' + icone + ' text-' + classeResultat + '"></i> ' +
                                (r.succes ? 'OK' : 'ECHEC') + '</td>' +
                            '<td><span class="badge bg-' + classeSeverite + '">' + r.severite + '</span></td>' +
                            '<td class="text-truncate" style="max-width:200px">' + (r.valeur_attendue || '-') + '</td>' +
                            '<td class="text-truncate" style="max-width:200px">' + (r.valeur_obtenue || '-') + '</td>' +
                            '<td>' + (r.message || '') + '</td>' +
                            '</tr>';
                    });

                    document.getElementById('detailUrl').classList.remove('d-none');
                    document.getElementById('detailUrl').scrollIntoView({ behavior: 'smooth' });
                });
        }

        function exporterResultatsCsv() {
            fetch(baseUrl + '/api.php?entite=resultat&action=par_execution&execution_id=' + executionId)
                .then(r => r.json())
                .then(data => {
                    const resultats = data.donnees || [];
                    if (!resultats.length) return;

                    const colonnes = ['url_id', 'regle_id', 'succes', 'severite', 'valeur_attendue', 'valeur_obtenue', 'message'];
                    const lignes = [colonnes.join(';')];
                    resultats.forEach(r => {
                        lignes.push(colonnes.map(c => {
                            const val = (r[c] ?? '').toString().replace(/"/g, '""');
                            return '"' + val + '"';
                        }).join(';'));
                    });

                    const blob = new Blob(['\uFEFF' + lignes.join('\n')], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'resultats_execution_' + executionId + '.csv';
                    a.click();
                    URL.revokeObjectURL(url);
                });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
