<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitor — Monitoring SEO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- Navbar (supprimee automatiquement en mode embedded) -->
<nav class="navbar mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">
            <i class="bi bi-shield-check me-2"></i>Site Monitor
            <span class="d-block d-sm-inline ms-sm-2" data-i18n="nav.sousTitre">Monitoring SEO</span>
        </span>
        <?php if (!defined('PLATFORM_EMBEDDED')): ?>
        <div class="btn-group btn-group-sm" id="langSelector">
            <button type="button" class="btn btn-outline-light" data-lang="fr">FR</button>
            <button type="button" class="btn btn-outline-light" data-lang="en">EN</button>
        </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container pb-5">

    <!-- ================================================================== -->
    <!-- Barre de progression (masquee par defaut)                          -->
    <!-- ================================================================== -->
    <div id="progressSection" class="card mb-4" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold"><i class="bi bi-arrow-repeat me-2"></i><span data-i18n="progres.titre">Verification en cours</span></h6>
            <button type="button" class="btn btn-outline-danger btn-sm" id="btnAnnulerVerification" data-i18n="progres.annuler">Annuler</button>
        </div>
        <div class="card-body">
            <div class="progress mb-2">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;">0%</div>
            </div>
            <p class="mb-0 small text-muted" id="progressStatus" data-i18n="progres.enAttente">En attente...</p>
            <div id="progressLogs" class="mt-3 p-2 bg-dark text-light rounded font-monospace small" style="max-height:200px; overflow-y:auto; display:none; font-size:0.75rem; line-height:1.4;"></div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- Onglets principaux                                                 -->
    <!-- ================================================================== -->
    <ul class="nav nav-tabs mb-4" id="tabsPrincipaux" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-dashboard" data-bs-toggle="tab" data-bs-target="#pane-dashboard" type="button" role="tab" aria-controls="pane-dashboard" aria-selected="true">
                <i class="bi bi-speedometer2 me-1"></i><span data-i18n="onglet.dashboard">Tableau de bord</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-modeles" data-bs-toggle="tab" data-bs-target="#pane-modeles" type="button" role="tab" aria-controls="pane-modeles" aria-selected="false">
                <i class="bi bi-file-earmark-ruled me-1"></i><span data-i18n="onglet.modeles">Modeles</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-executions" data-bs-toggle="tab" data-bs-target="#pane-executions" type="button" role="tab" aria-controls="pane-executions" aria-selected="false">
                <i class="bi bi-clock-history me-1"></i><span data-i18n="onglet.executions">Executions</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-alertes" data-bs-toggle="tab" data-bs-target="#pane-alertes" type="button" role="tab" aria-controls="pane-alertes" aria-selected="false">
                <i class="bi bi-bell me-1"></i><span data-i18n="alerte.titre">Alertes</span>
                <span class="badge bg-danger badge-alertes" id="badgeAlertes" style="display:none;">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-indexation" data-bs-toggle="tab" data-bs-target="#pane-indexation" type="button" role="tab" aria-controls="pane-indexation" aria-selected="false">
                <i class="bi bi-search me-1"></i><span data-i18n="onglet.indexation">Audit d'indexation</span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="tabContent">

        <!-- ============================================================== -->
        <!-- ONGLET : Tableau de bord                                       -->
        <!-- ============================================================== -->
        <div class="tab-pane fade show active" id="pane-dashboard" role="tabpanel" aria-labelledby="tab-dashboard">

            <!-- 1a. KPIs compacts (3) -->
            <div class="kpi-row kpi-row-compact mb-3" id="kpiRow">
                <div class="kpi-card kpi-dark">
                    <div class="kpi-value" id="kpiClientsActifs">0</div>
                    <div class="kpi-label" data-i18n="kpi.clientsActifs">Clients actifs</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value" id="kpiUrlsSurveillees">0</div>
                    <div class="kpi-label" data-i18n="kpi.urlsSurveillees">URLs surveillees</div>
                </div>
                <div class="kpi-card kpi-green">
                    <div class="kpi-value" id="kpiTauxReussite">--</div>
                    <div class="kpi-label" data-i18n="kpi.tauxReussite">Taux de reussite</div>
                </div>
            </div>

            <!-- 1b. Boutons actions + Help -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary btn-sm" id="btnAjouterClient" data-bs-toggle="modal" data-bs-target="#modalClient">
                            <i class="bi bi-plus-lg me-1"></i><span data-i18n="dashboard.ajouterClient">Ajouter un client</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLancerVerification" data-bs-toggle="modal" data-bs-target="#modalLancerVerification">
                            <i class="bi bi-play-fill me-1"></i><span data-i18n="dashboard.lancerVerification">Lancer une verification</span>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnSetupRapide" data-bs-toggle="modal" data-bs-target="#modalSetupRapide">
                            <i class="bi bi-lightning me-1"></i><span data-i18n="setup.titre">Setup rapide</span>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4" id="helpPanel">
                    <div id="platformCreditsSlot"></div>
                    <div class="config-help-panel">
                        <a class="help-title mb-2 d-flex align-items-center text-decoration-none" data-bs-toggle="collapse" href="#helpPanelBody" role="button" aria-expanded="false" aria-controls="helpPanelBody">
                            <i class="bi bi-info-circle me-1"></i> Comment ca marche
                            <i class="bi bi-chevron-down ms-auto small"></i>
                        </a>
                        <div class="collapse" id="helpPanelBody">
                            <ul>
                                <li><strong>Client</strong> : ajoutez un client, ses URLs et un modele de regles.</li>
                                <li><strong>Verification</strong> : lancez une execution pour detecter les regressions.</li>
                                <li><strong>Feed</strong> : voyez en un coup d'oeil ce qui a change.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. FEED "Quoi de neuf ?" (hero section) -->
            <div class="card mb-4" id="cardChangementsFeed">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-arrow-left-right me-2"></i>
                        <span data-i18n="dashboard.changements_feed">Quoi de neuf ?</span>
                        <span class="badge bg-secondary ms-2" id="badgeNbChangements">0</span>
                    </h6>
                    <div class="btn-group btn-group-sm" id="filtreChangementsFeed">
                        <button type="button" class="btn btn-outline-danger btn-sm active" data-filtre="nouvelles">
                            <i class="bi bi-exclamation-triangle me-1"></i><span data-i18n="dashboard.nouvelles_defaillances">Nouveaux problemes</span>
                            <span class="badge bg-danger ms-1" id="countNouvelles">0</span>
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" data-filtre="recuperations">
                            <i class="bi bi-check-circle me-1"></i><span data-i18n="dashboard.recuperations">Resolus</span>
                            <span class="badge bg-success ms-1" id="countRecuperations">0</span>
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-sm" data-filtre="persistantes">
                            <i class="bi bi-arrow-repeat me-1"></i><span data-i18n="dashboard.persistantes">Persistants</span>
                            <span class="badge bg-warning text-dark ms-1" id="countPersistantes">0</span>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0" id="corpsChangementsFeed"></div>
                <div class="card-footer text-center py-3" id="feedVide">
                    <i class="bi bi-check-circle text-success fs-4 d-block mb-1"></i>
                    <span class="text-muted" data-i18n="dashboard.aucun_changement_feed">Aucun changement detecte. Tout est stable.</span>
                </div>
            </div>

            <!-- 3. Sante par client + Alertes recentes (2 colonnes) -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card h-100" id="cardSanteClients">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-heart-pulse me-2"></i><span data-i18n="dashboard.sante_clients">Sante par client</span></h6>
                            <div class="btn-group btn-group-sm" id="triSanteClients">
                                <button type="button" class="btn btn-outline-secondary active" data-tri="score" data-i18n="dashboard.tri_score">Score</button>
                                <button type="button" class="btn btn-outline-secondary" data-tri="alertes" data-i18n="dashboard.tri_alertes">Alertes</button>
                                <button type="button" class="btn btn-outline-secondary" data-tri="date" data-i18n="dashboard.tri_date">Derniere verif.</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3" id="grilleSanteClients">
                                <div class="col-12 text-center text-muted py-3" id="santeClientsVide">
                                    <i class="bi bi-hourglass-split"></i> Chargement...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100" id="cardAlertesRecentes" style="display:none;">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-bell me-2"></i><span data-i18n="alerte.recentes">Alertes</span></h6>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVoirToutesAlertes">
                                <span data-i18n="alerte.voir_detail">Voir tout</span>
                            </button>
                        </div>
                        <div class="card-body p-2" id="listeAlertesRecentes"></div>
                    </div>
                </div>
            </div>

            <!-- 4. Tendances (Chart.js) -->
            <div class="card mb-4" id="cardTendances">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2"></i><span data-i18n="dashboard.tendances">Tendances (30 jours)</span></h6>
                    <div class="d-flex gap-2 align-items-center">
                        <select class="form-select form-select-sm" id="filtreTendancesClient" style="width:auto;">
                            <option value="" data-i18n="dashboard.tous_clients">Tous les clients</option>
                        </select>
                        <ul class="nav nav-pills nav-pills-sm" id="tabsTendances">
                            <li class="nav-item">
                                <button class="nav-link active" data-graphique="taux" data-i18n="dashboard.taux_reussite">Taux de reussite</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-graphique="executions" data-i18n="dashboard.executions_jour">Executions / jour</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-graphique="ttfb" data-i18n="dashboard.ttfb_moyen">TTFB moyen</button>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="card-body">
                    <div class="graphique-conteneur">
                        <canvas id="canvasTendances"></canvas>
                    </div>
                </div>
            </div>

        </div><!-- /pane-dashboard -->

        <!-- ============================================================== -->
        <!-- ONGLET : Modeles                                               -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="pane-modeles" role="tabpanel" aria-labelledby="tab-modeles">

            <!-- Barre d'actions -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0 fw-bold" data-i18n="modeles.titre">Modeles de verification</h5>
                <button type="button" class="btn btn-primary btn-sm" id="btnAjouterModele" data-bs-toggle="modal" data-bs-target="#modalModele">
                    <i class="bi bi-plus-lg me-1"></i><span data-i18n="modeles.ajouter">Creer un modele</span>
                </button>
            </div>

            <!-- Liste des modeles -->
            <div id="listeModeles">
                <div class="card mb-3" id="modelesVide">
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-file-earmark-ruled fs-3 d-block mb-2"></i>
                        <span data-i18n="modeles.aucun">Aucun modele de verification. Creez-en un pour definir vos regles.</span>
                    </div>
                </div>
            </div>

            <!-- Template d'un modele (masque, clone par JS) -->
            <template id="tplModeleCard">
                <div class="card mb-3 carte-modele" data-modele-id="">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold nom-modele"></h6>
                            <small class="text-muted description-modele"></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary nombre-regles">0 regles</span>
                            <span class="badge badge-modele-global" style="display:none;" data-i18n="modeles.global">Global</span>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item btn-editer-modele" href="#"><i class="bi bi-pencil me-2"></i><span data-i18n="actions.modifier">Modifier</span></a></li>
                                    <li><a class="dropdown-item btn-gerer-regles" href="#"><i class="bi bi-list-check me-2"></i><span data-i18n="actions.gererRegles">Gerer les regles</span></a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger btn-supprimer-modele" href="#"><i class="bi bi-trash me-2"></i><span data-i18n="actions.supprimer">Supprimer</span></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body liste-regles-apercu">
                        <p class="text-muted small mb-0" data-i18n="modeles.aucuneRegle">Aucune regle definie.</p>
                    </div>
                </div>
            </template>

        </div><!-- /pane-modeles -->

        <!-- ============================================================== -->
        <!-- ONGLET : Executions                                            -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="pane-executions" role="tabpanel" aria-labelledby="tab-executions">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0 fw-bold" data-i18n="executions.titre">Historique des executions</h5>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" id="filtreExecutionStatut" style="width:auto;">
                        <option value="" data-i18n="executions.tousStatuts">Tous les statuts</option>
                        <option value="en_attente" data-i18n="executions.enAttente">En attente</option>
                        <option value="en_cours" data-i18n="executions.enCours">En cours</option>
                        <option value="termine" data-i18n="executions.terminee">Terminee</option>
                        <option value="erreur" data-i18n="executions.echouee">Echouee</option>
                        <option value="annule" data-i18n="executions.annulee">Annulee</option>
                    </select>
                    <select class="form-select form-select-sm" id="filtreExecutionClient" style="width:auto;">
                        <option value="" data-i18n="executions.tousClients">Tous les clients</option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="tableExecutions">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="date" data-i18n="table.date">Date</th>
                                    <th data-i18n="table.client">Client</th>
                                    <th data-i18n="table.statut">Statut</th>
                                    <th class="sortable" data-sort="urls" data-i18n="table.urls">URLs</th>
                                    <th data-i18n="table.succesEchecs">Succes / Echecs</th>
                                    <th class="sortable" data-sort="duree" data-i18n="table.duree">Duree</th>
                                    <th data-i18n="table.actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="bodyExecutions">
                                <tr id="rowExecutionsVide">
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="bi bi-clock-history fs-3 d-block mb-2"></i>
                                        <span data-i18n="executions.aucune">Aucune execution enregistree.</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /pane-executions -->

        <!-- ============================================================== -->
        <!-- ONGLET : Alertes                                               -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="pane-alertes" role="tabpanel" aria-labelledby="tab-alertes">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0 fw-bold"><i class="bi bi-bell me-2"></i><span data-i18n="alerte.titre">Alertes</span></h5>
            </div>

            <div id="listeAlertes">
                <p class="text-center text-muted py-4" data-i18n="alerte.aucune">Aucune alerte.</p>
            </div>

        </div><!-- /pane-alertes -->

        <!-- ============================================================== -->
        <!-- ONGLET INDEXATION                                               -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="pane-indexation" role="tabpanel" aria-labelledby="tab-indexation">

            <!-- Formulaire de lancement -->
            <div class="card mb-4" id="config-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-search me-2"></i><span data-i18n="indexation.titre">Audit d'indexation</span></h6>
                    <button type="button" class="config-toggle" data-bs-toggle="collapse" data-bs-target="#configBody" aria-expanded="true"><i class="bi bi-chevron-down"></i></button>
                </div>
                <div class="collapse show" id="configBody">
                <div class="card-body">
                    <form id="formIndexation" method="POST">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="indexDomaine" class="form-label" data-i18n="indexation.domaine">Domaine</label>
                                <input type="text" class="form-control" id="indexDomaine" name="domaine" placeholder="https://example.com" required>
                            </div>
                            <div class="col-md-4">
                                <label for="indexClient" class="form-label" data-i18n="indexation.client">Client (optionnel)</label>
                                <select class="form-select" id="indexClient" name="client_id">
                                    <option value="">— Aucun —</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" data-i18n="indexation.source">Source des URLs</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="sourceUrls" id="sourceManuel" value="manuel" checked>
                                    <label class="btn btn-outline-secondary btn-sm" for="sourceManuel" data-i18n="indexation.source_manuel">Saisie manuelle</label>
                                    <input type="radio" class="btn-check" name="sourceUrls" id="sourceClient" value="client">
                                    <label class="btn btn-outline-secondary btn-sm" for="sourceClient" data-i18n="indexation.source_client">Depuis client</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="indexUrls" class="form-label" data-i18n="indexation.urls_label">URLs a auditer (une par ligne)</label>
                            <textarea class="form-control" id="indexUrls" name="urls" rows="6" placeholder="https://example.com/page1&#10;https://example.com/page2&#10;..." required></textarea>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <small class="text-muted"><span id="indexUrlsCount">0</span> <span data-i18n="indexation.urls_comptees">URLs detectees</span></small>
                            <button type="submit" class="btn btn-primary" id="btnLancerIndexation">
                                <i class="bi bi-play-fill me-1"></i><span data-i18n="indexation.lancer">Lancer l'audit</span>
                            </button>
                        </div>
                    </form>
                </div>
                </div>
            </div>

            <!-- Barre de progression indexation -->
            <div id="progressIndexation" class="card mb-4" style="display:none;">
                <div class="card-body">
                    <div class="progress mb-2">
                        <div id="progressBarIndexation" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;">0%</div>
                    </div>
                    <p class="mb-0 small text-muted" id="progressStatusIndexation" data-i18n="progres.enAttente">En attente...</p>
                </div>
            </div>

            <!-- KPI Cards (masques jusqu'aux resultats) -->
            <div id="kpiIndexation" class="row g-3 mb-4" style="display:none;">
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <div class="fs-3 fw-bold" id="kpiIndexTotal">0</div>
                            <small class="text-muted" data-i18n="indexation.kpi.total">URLs analysees</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <div class="fs-3 fw-bold text-success" id="kpiIndexIndexables">0</div>
                            <small class="text-muted" data-i18n="indexation.kpi.indexables">Indexables</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <div class="fs-3 fw-bold text-warning" id="kpiIndexNonIndexables">0</div>
                            <small class="text-muted" data-i18n="indexation.kpi.non_indexables">Non indexables</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <div class="fs-3 fw-bold text-danger" id="kpiIndexContradictions">0</div>
                            <small class="text-muted" data-i18n="indexation.kpi.contradictions">Contradictions</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Graphiques -->
            <div id="chartsIndexation" class="row g-3 mb-4" style="display:none;">
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-body">
                            <canvas id="chartIndexStatuts" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card">
                        <div class="card-body">
                            <canvas id="chartIndexContradictions" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtres et tableau resultats -->
            <div id="resultatsIndexation" style="display:none;">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2"></i><span data-i18n="indexation.resultats">Resultats</span></h6>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <select class="form-select form-select-sm" id="filtreStatutIndex" style="width:auto;">
                                <option value="" data-i18n="indexation.filtre.tous_statuts">Tous les statuts</option>
                                <option value="indexable" data-i18n="indexation.filtre.indexable">Indexable</option>
                                <option value="non_indexable" data-i18n="indexation.filtre.non_indexable">Non indexable</option>
                                <option value="contradictoire" data-i18n="indexation.filtre.contradictoire">Contradictoire</option>
                                <option value="erreur" data-i18n="indexation.filtre.erreur">Erreur</option>
                            </select>
                            <select class="form-select form-select-sm" id="filtreSeveriteIndex" style="width:auto;">
                                <option value="" data-i18n="indexation.filtre.toutes_severites">Toutes severites</option>
                                <option value="critique" data-i18n="indexation.filtre.critique">Critique</option>
                                <option value="attention" data-i18n="indexation.filtre.attention">Attention</option>
                                <option value="info" data-i18n="indexation.filtre.info">Info</option>
                            </select>
                            <input type="search" class="form-control form-control-sm" id="rechercheUrlIndex" placeholder="Filtrer par URL..." style="width:200px;">
                            <button class="btn btn-outline-secondary btn-sm" id="btnExporterIndexation" title="Export CSV">
                                <i class="bi bi-download me-1"></i><span data-i18n="action.exporter_csv">Export CSV</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="tableIndexation">
                                <thead>
                                    <tr>
                                        <th data-i18n="indexation.col.url">URL</th>
                                        <th data-i18n="indexation.col.code_http">HTTP</th>
                                        <th data-i18n="indexation.col.meta_robots">Meta Robots</th>
                                        <th data-i18n="indexation.col.canonical">Canonical</th>
                                        <th data-i18n="indexation.col.robots_txt">Robots.txt</th>
                                        <th data-i18n="indexation.col.sitemap">Sitemap</th>
                                        <th data-i18n="indexation.col.statut">Statut</th>
                                        <th data-i18n="indexation.col.contradictions">Contradictions</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyIndexation"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historique des audits -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i><span data-i18n="indexation.historique">Historique des audits</span></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th data-i18n="indexation.hist.date">Date</th>
                                    <th data-i18n="indexation.hist.domaine">Domaine</th>
                                    <th data-i18n="indexation.hist.total">Total</th>
                                    <th data-i18n="indexation.hist.indexables">Indexables</th>
                                    <th data-i18n="indexation.hist.contradictions">Contradictions</th>
                                    <th data-i18n="indexation.hist.statut">Statut</th>
                                    <th data-i18n="indexation.hist.actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyHistoriqueIndex">
                                <tr><td colspan="7" class="text-center text-muted py-4" data-i18n="indexation.aucun_audit">Aucun audit d'indexation.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /pane-indexation -->

    </div><!-- /tab-content -->

</div><!-- /container -->


<!-- ====================================================================== -->
<!-- MODALES                                                                -->
<!-- ====================================================================== -->

<!-- Modal : Client (creation / edition) -->
<div class="modal fade" id="modalClient" tabindex="-1" aria-labelledby="modalClientLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalClientLabel" data-i18n="modal.client.titre">Ajouter un client</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formClient" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="clientId" name="id" value="">
                    <div class="mb-3">
                        <label for="clientNom" class="form-label" data-i18n="modal.client.nom">Nom du client</label>
                        <input type="text" class="form-control" id="clientNom" name="nom" required maxlength="255" placeholder="Mon Site E-commerce">
                    </div>
                    <div class="mb-3">
                        <label for="clientDomaine" class="form-label" data-i18n="modal.client.domaine">Domaine</label>
                        <input type="text" class="form-control" id="clientDomaine" name="domaine" required maxlength="255" placeholder="www.example.com">
                        <div class="form-text" data-i18n="modal.client.domaineAide">Sans protocole (ex: www.example.com)</div>
                    </div>
                    <div class="mb-3">
                        <label for="clientEmail" class="form-label" data-i18n="modal.client.email">Email de contact</label>
                        <input type="email" class="form-control" id="clientEmail" name="email_contact" maxlength="255" placeholder="seo@example.com">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="clientActif" name="actif" checked>
                        <label class="form-check-label" for="clientActif" data-i18n="modal.client.actif">Client actif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSauvegarderClient" data-i18n="modal.enregistrer">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : Groupe (creation / edition) -->
<div class="modal fade" id="modalGroupe" tabindex="-1" aria-labelledby="modalGroupeLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalGroupeLabel" data-i18n="modal.groupe.titre">Ajouter un groupe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formGroupe" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="groupeId" name="id" value="">
                    <input type="hidden" id="groupeClientId" name="client_id" value="">
                    <div class="mb-3">
                        <label for="groupeNom" class="form-label" data-i18n="modal.groupe.nom">Nom du groupe</label>
                        <input type="text" class="form-control" id="groupeNom" name="nom" required maxlength="255" placeholder="Pages produits">
                    </div>
                    <div class="mb-3">
                        <label for="groupeDescription" class="form-label" data-i18n="modal.groupe.description">Description</label>
                        <textarea class="form-control" id="groupeDescription" name="description" rows="3" placeholder="Description du groupe d'URLs..."></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="groupeActif" name="actif" checked>
                        <label class="form-check-label" for="groupeActif" data-i18n="modal.groupe.actif">Groupe actif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSauvegarderGroupe" data-i18n="modal.enregistrer">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : URL (creation / edition) -->
<div class="modal fade" id="modalUrl" tabindex="-1" aria-labelledby="modalUrlLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalUrlLabel" data-i18n="modal.url.titre">Ajouter une URL</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formUrl" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="urlId" name="id" value="">
                    <input type="hidden" id="urlGroupeId" name="groupe_id" value="">

                    <!-- Toggle mode simple / multiple -->
                    <div class="mb-3" id="blocToggleUrlMode">
                        <a href="#" id="toggleUrlMode" class="small text-decoration-none">
                            <i class="bi bi-list-ul me-1"></i><span data-i18n="modal.url.ajoutMultiple">Ajouter plusieurs URLs</span>
                        </a>
                    </div>

                    <!-- Mode simple (defaut) -->
                    <div id="blocUrlSimple">
                        <div class="mb-3">
                            <label for="urlAdresse" class="form-label" data-i18n="modal.url.adresse">URL</label>
                            <input type="url" class="form-control" id="urlAdresse" name="url" maxlength="2048" placeholder="https://www.example.com/page">
                        </div>
                        <div class="mb-3">
                            <label for="urlLibelle" class="form-label" data-i18n="modal.url.libelle">Libelle</label>
                            <input type="text" class="form-control" id="urlLibelle" name="libelle" maxlength="255" placeholder="Page d'accueil">
                            <div class="form-text" data-i18n="modal.url.libelleAide">Nom court pour identifier la page</div>
                        </div>
                        <div class="mb-3">
                            <label for="urlNotes" class="form-label" data-i18n="modal.url.notes">Notes</label>
                            <textarea class="form-control" id="urlNotes" name="notes" rows="2" placeholder="Remarques ou contexte supplementaire..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" data-i18n="modal.url.modeles">Modeles de verification associes</label>
                            <div id="urlModelesCheckboxes" class="d-flex flex-wrap gap-2">
                                <span class="text-muted small" data-i18n="modal.url.aucunModele">Aucun modele disponible</span>
                            </div>
                            <div class="form-text" data-i18n="modal.url.modelesAide">Selectionnez les modeles a appliquer lors des verifications</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="urlActif" name="actif" checked>
                            <label class="form-check-label" for="urlActif" data-i18n="modal.url.actif">URL active</label>
                        </div>
                    </div>

                    <!-- Mode multiple (masque par defaut) -->
                    <div id="blocUrlMultiple" style="display:none;">
                        <div class="mb-3">
                            <label for="urlsTextarea" class="form-label" data-i18n="modal.url.urlsTextarea">URLs (une par ligne)</label>
                            <textarea class="form-control font-monospace" id="urlsTextarea" rows="10" placeholder="https://www.example.com/page1&#10;https://www.example.com/page2&#10;https://www.example.com/page3"></textarea>
                            <div class="form-text">
                                <span id="urlsCompteur" class="fw-semibold">0</span> <span data-i18n="modal.url.urlsDetectees">URLs detectees</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSauvegarderUrl" data-i18n="modal.enregistrer">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : Modele (creation / edition) -->
<div class="modal fade" id="modalModele" tabindex="-1" aria-labelledby="modalModeleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalModeleLabel" data-i18n="modal.modele.titre">Creer un modele</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formModele" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="modeleId" name="id" value="">
                    <div class="mb-3">
                        <label for="modeleNom" class="form-label" data-i18n="modal.modele.nom">Nom du modele</label>
                        <input type="text" class="form-control" id="modeleNom" name="nom" required maxlength="255" placeholder="Verification SEO standard">
                    </div>
                    <div class="mb-3">
                        <label for="modeleDescription" class="form-label" data-i18n="modal.modele.description">Description</label>
                        <textarea class="form-control" id="modeleDescription" name="description" rows="3" placeholder="Ensemble de regles pour verifier..."></textarea>
                    </div>
                    <div class="mb-3" id="blocModeleTemplate">
                        <label for="modeleTemplate" class="form-label" data-i18n="modal.modele.template">Modele de depart</label>
                        <select class="form-select" id="modeleTemplate" name="template">
                            <option value="" data-i18n="modal.modele.templateAucun">— Aucun (modele vide) —</option>
                        </select>
                        <div class="form-text" data-i18n="modal.modele.templateAide">Les regles du template seront automatiquement ajoutees</div>
                    </div>
                    <div class="mb-3">
                        <label for="modeleClientId" class="form-label" data-i18n="modal.modele.client">Client (optionnel)</label>
                        <select class="form-select" id="modeleClientId" name="client_id">
                            <option value="" data-i18n="modal.modele.aucunClient">— Aucun (modele global) —</option>
                        </select>
                        <div class="form-text" data-i18n="modal.modele.clientAide">Laisser vide pour un modele global utilisable par tous les clients</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="modeleEstGlobal" name="est_global">
                        <label class="form-check-label" for="modeleEstGlobal" data-i18n="modal.modele.estGlobal">Modele global</label>
                        <div class="form-text" data-i18n="modal.modele.estGlobalAide">Un modele global est disponible pour tous les clients</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSauvegarderModele" data-i18n="modal.enregistrer">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : Regle (creation / edition) -->
<div class="modal fade" id="modalRegle" tabindex="-1" aria-labelledby="modalRegleLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalRegleLabel" data-i18n="modal.regle.titre">Ajouter une regle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formRegle" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="regleId" name="id" value="">
                    <input type="hidden" id="regleModeleId" name="modele_id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="regleTypeRegle" class="form-label" data-i18n="modal.regle.type">Type de regle</label>
                            <select class="form-select" id="regleTypeRegle" name="type_regle" required>
                                <option value="" data-i18n="modal.regle.choisirType">— Choisir un type —</option>
                                <option value="code_http">Code HTTP</option>
                                <option value="disponibilite">Disponibilite</option>
                                <option value="meta_seo">Meta SEO (title, description, canonical)</option>
                                <option value="en_tete_http">En-tete HTTP</option>
                                <option value="contenu_xpath">Contenu XPath</option>
                                <option value="changement_contenu">Changement de contenu</option>
                                <option value="comptage_occurrences">Comptage d'occurrences</option>
                                <option value="donnees_structurees">Donnees structurees (JSON-LD)</option>
                                <option value="images_seo">Images SEO (alt, dimensions)</option>
                                <option value="performance">Performance (TTFB, temps reponse)</option>
                                <option value="robots_txt">Robots.txt</option>
                                <option value="sitemap_xml">Sitemap XML</option>
                                <option value="ssl">Certificat SSL</option>
                                <option value="structure_titres">Structure des titres (Hn)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="regleSeverite" class="form-label" data-i18n="modal.regle.severite">Severite</label>
                            <select class="form-select" id="regleSeverite" name="severite" required>
                                <option value="info">Info</option>
                                <option value="avertissement">Avertissement</option>
                                <option value="erreur" selected>Erreur</option>
                                <option value="critique">Critique</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label for="regleNom" class="form-label" data-i18n="modal.regle.nom">Nom de la regle</label>
                        <input type="text" class="form-control" id="regleNom" name="nom" required maxlength="255" placeholder="Verifier le code HTTP 200">
                    </div>
                    <div class="mb-3">
                        <label for="regleConfiguration" class="form-label" data-i18n="modal.regle.configuration">Configuration (JSON)</label>
                        <textarea class="form-control font-monospace" id="regleConfiguration" name="configuration_json" rows="8" placeholder='{"code_attendu": 200}'></textarea>
                        <div class="form-text" id="regleConfigAide" data-i18n="modal.regle.configurationAide">La structure JSON depend du type de regle selectionne</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="regleActif" name="actif" checked>
                        <label class="form-check-label" for="regleActif" data-i18n="modal.regle.actif">Regle active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSauvegarderRegle" data-i18n="modal.enregistrer">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : Lancer une verification -->
<div class="modal fade" id="modalLancerVerification" tabindex="-1" aria-labelledby="modalLancerVerificationLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalLancerVerificationLabel" data-i18n="modal.verification.titre">Lancer une verification</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form id="formLancerVerification" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="verifClientId" class="form-label" data-i18n="modal.verification.client">Client</label>
                        <select class="form-select" id="verifClientId" name="client_id" required>
                            <option value="" data-i18n="modal.verification.choisirClient">— Selectionner un client —</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="verifGroupeId" class="form-label" data-i18n="modal.verification.groupe">Groupe (optionnel)</label>
                        <select class="form-select" id="verifGroupeId" name="groupe_id">
                            <option value="" data-i18n="modal.verification.tousGroupes">— Tous les groupes —</option>
                        </select>
                        <div class="form-text" data-i18n="modal.verification.groupeAide">Laisser vide pour verifier toutes les URLs du client</div>
                    </div>

                    <hr>
                    <h6 class="fw-bold mb-3" data-i18n="modal.verification.options">Options avancees</h6>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="verifUserAgent" class="form-label" data-i18n="modal.verification.userAgent">User-Agent</label>
                            <select class="form-select form-select-sm" id="verifUserAgent" name="user_agent">
                                <option value="googlebot" data-i18n="modal.verification.uaGooglebot">Googlebot</option>
                                <option value="googlebot-mobile" data-i18n="modal.verification.uaGooglebotMobile">Googlebot Mobile</option>
                                <option value="chrome" selected data-i18n="modal.verification.uaChrome">Chrome Desktop</option>
                                <option value="custom" data-i18n="modal.verification.uaPersonnalise">Personnalise</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="verifTimeout" class="form-label" data-i18n="modal.verification.timeout">Timeout (s)</label>
                            <input type="number" class="form-control form-control-sm" id="verifTimeout" name="timeout" value="30" min="5" max="120">
                        </div>
                        <div class="col-md-3">
                            <label for="verifDelai" class="form-label" data-i18n="modal.verification.delai">Delai (ms)</label>
                            <input type="number" class="form-control form-control-sm" id="verifDelai" name="delai" value="500" min="0" max="10000" step="100">
                        </div>
                    </div>
                    <div class="mt-3" id="verifUserAgentCustomWrapper" style="display:none;">
                        <label for="verifUserAgentCustom" class="form-label" data-i18n="modal.verification.userAgentCustom">User-Agent personnalise</label>
                        <input type="text" class="form-control form-control-sm" id="verifUserAgentCustom" name="user_agent_custom" placeholder="Mozilla/5.0 ...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnDemarrerVerification">
                        <i class="bi bi-play-fill me-1"></i><span data-i18n="modal.verification.demarrer">Demarrer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal : Detail client (drilldown) -->
<div class="modal fade" id="modalDetailClient" tabindex="-1" aria-labelledby="modalDetailClientLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalDetailClientLabel" data-i18n="modal.detail.titre">Detail du client</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Info client -->
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h4 class="fw-bold mb-1" id="detailClientNom"></h4>
                        <p class="text-muted mb-1">
                            <i class="bi bi-globe me-1"></i><span id="detailClientDomaine"></span>
                        </p>
                        <p class="text-muted mb-0 small">
                            <i class="bi bi-envelope me-1"></i><span id="detailClientEmail"></span>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditerClientDetail">
                            <i class="bi bi-pencil me-1"></i><span data-i18n="actions.modifier">Modifier</span>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="btnLancerVerifDetail">
                            <i class="bi bi-play-fill me-1"></i><span data-i18n="dashboard.lancerVerification">Lancer une verification</span>
                        </button>
                    </div>
                </div>

                <!-- Sous-onglets : Groupes / URLs / Modeles -->
                <ul class="nav nav-tabs mb-3" id="tabsDetailClient" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-detail-groupes" data-bs-toggle="tab" data-bs-target="#pane-detail-groupes" type="button" role="tab">
                            <i class="bi bi-folder me-1"></i><span data-i18n="detail.groupes">Groupes</span>
                            <span class="badge bg-secondary ms-1" id="badgeGroupes">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-detail-urls" data-bs-toggle="tab" data-bs-target="#pane-detail-urls" type="button" role="tab">
                            <i class="bi bi-link-45deg me-1"></i><span data-i18n="detail.urls">URLs</span>
                            <span class="badge bg-secondary ms-1" id="badgeUrls">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-detail-modeles" data-bs-toggle="tab" data-bs-target="#pane-detail-modeles" type="button" role="tab">
                            <i class="bi bi-file-earmark-ruled me-1"></i><span data-i18n="detail.modeles">Modeles</span>
                            <span class="badge bg-secondary ms-1" id="badgeModeles">0</span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="tabContentDetail">
                    <!-- Groupes -->
                    <div class="tab-pane fade show active" id="pane-detail-groupes" role="tabpanel">
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="btn btn-primary btn-sm" id="btnAjouterGroupeDetail">
                                <i class="bi bi-plus-lg me-1"></i><span data-i18n="detail.ajouterGroupe">Ajouter un groupe</span>
                            </button>
                        </div>
                        <div id="listeGroupesDetail">
                            <p class="text-muted text-center py-3" data-i18n="detail.aucunGroupe">Aucun groupe pour ce client.</p>
                        </div>
                    </div>
                    <!-- URLs -->
                    <div class="tab-pane fade" id="pane-detail-urls" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <input type="search" class="form-control form-control-sm" id="rechercheUrlsDetail" placeholder="Filtrer les URLs..." style="width:220px;">
                                <select class="form-select form-select-sm" id="filtreGroupeUrlsDetail" style="width:auto;">
                                    <option value="" data-i18n="detail.tousGroupes">Tous les groupes</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="btnAjouterUrlDetail">
                                <i class="bi bi-plus-lg me-1"></i><span data-i18n="detail.ajouterUrl">Ajouter une URL</span>
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0" id="tableUrlsDetail">
                                <thead>
                                    <tr>
                                        <th data-i18n="table.url">URL</th>
                                        <th data-i18n="table.libelle">Libelle</th>
                                        <th data-i18n="table.groupe">Groupe</th>
                                        <th data-i18n="table.dernierStatut">Dernier statut</th>
                                        <th data-i18n="table.actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="bodyUrlsDetail">
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3" data-i18n="detail.aucuneUrl">Aucune URL configuree.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Modeles associes -->
                    <div class="tab-pane fade" id="pane-detail-modeles" role="tabpanel">
                        <div id="listeModelesDetail">
                            <p class="text-muted text-center py-3" data-i18n="detail.aucunModele">Aucun modele associe a ce client.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.fermer">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Setup rapide (groupes + URLs) -->
<div class="modal fade" id="modalSetupRapide" tabindex="-1" aria-labelledby="modalSetupRapideLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalSetupRapideLabel">
                    <i class="bi bi-lightning me-2"></i><span data-i18n="setup.titre">Setup rapide</span>
                    <span class="fw-normal ms-2" id="setupClientNom"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="setupClientId" value="">
                <!-- Select client (visible si pas pre-rempli) -->
                <div class="mb-3" id="blocSetupSelectClient">
                    <label for="setupSelectClient" class="form-label" data-i18n="setup.selectClient">Client</label>
                    <select class="form-select" id="setupSelectClient">
                        <option value="">-- Choisir un client --</option>
                    </select>
                </div>
                <!-- Zone dynamique des blocs groupe -->
                <div id="setupGroupes"></div>
                <!-- Bouton ajouter un groupe -->
                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btnSetupAjouterGroupe">
                    <i class="bi bi-plus-lg me-1"></i><span data-i18n="setup.ajouterGroupe">Ajouter un groupe</span>
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="action.annuler">Annuler</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSetupEnregistrer">
                    <i class="bi bi-check-lg me-1"></i><span data-i18n="setup.enregistrerTout">Enregistrer tout</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Confirmation de suppression -->
<div class="modal fade" id="modalConfirmation" tabindex="-1" aria-labelledby="modalConfirmationLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" id="modalConfirmationLabel" data-i18n="modal.confirmation.titre">Confirmer la suppression</h5>
            </div>
            <div class="modal-body">
                <p id="confirmationMessage" class="mb-0" data-i18n="modal.confirmation.message">Etes-vous sur de vouloir supprimer cet element ? Cette action est irreversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="modal.annuler">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" id="btnConfirmerSuppression" data-i18n="modal.confirmer">Confirmer</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<!-- Template toast -->
<template id="tplToast">
    <div class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="translations.js"></script>
<script src="commun.js"></script>
<script src="app.js"></script>
<script src="regles-config.js"></script>
<script src="indexation.js"></script>
</body>
</html>
