<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitor — Client</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>

<!-- Navbar (supprimee automatiquement en mode embedded) -->
<nav class="navbar mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">
            <i class="bi bi-shield-check me-2"></i>Site Monitor
            <span class="d-block d-sm-inline ms-sm-2" data-i18n="nav.sousTitre">Monitoring SEO</span>
        </span>
    </div>
</nav>

<div class="container pb-5">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="<?php echo defined('PLATFORM_EMBEDDED') ? '.' : 'index.php'; ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><span data-i18n="action.retour">Retour</span>
            </a>
            <div>
                <h5 class="mb-0 fw-bold" id="clientNom">Client</h5>
                <small class="text-muted" id="clientDomaine"></small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary btn-sm" id="btnLancerAnalyse">
                <i class="bi bi-play-fill me-1"></i><span data-i18n="execution.lancer">Lancer une analyse</span>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnModifierClient">
                <i class="bi bi-pencil me-1"></i><span data-i18n="actions.modifier">Modifier</span>
            </button>
        </div>
    </div>

    <!-- Barre de progression -->
    <div id="progressSection" class="card mb-4" style="display:none;">
        <div class="card-body py-2">
            <div class="progress mb-1" style="height:6px;">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%;"></div>
            </div>
            <small class="text-muted" id="progressStatus"></small>
            <div id="progressLogs" class="mt-2 p-2 bg-dark text-light rounded font-monospace small" style="max-height:150px; overflow-y:auto; display:none; font-size:0.7rem; line-height:1.3;"></div>
        </div>
    </div>

    <!-- Layout 2 colonnes -->
    <div class="row">

        <!-- Colonne gauche : Groupes & URLs -->
        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-folder me-2"></i>Groupes & URLs</h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnAjouterGroupe">
                            <i class="bi bi-plus-lg me-1"></i>Groupe
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImportSitemap">
                            <i class="bi bi-diagram-3 me-1"></i>Sitemap
                        </button>
                    </div>
                </div>
                <div class="card-body p-0" id="listeGroupes">
                    <div class="text-center text-muted py-4" id="groupesVide">
                        <i class="bi bi-folder-plus fs-3 d-block mb-2"></i>
                        Aucun groupe. Ajoutez un groupe d'URLs ou importez depuis un sitemap.
                    </div>
                </div>
            </div>
        </div>

        <!-- Colonne droite : Regles + Planification -->
        <div class="col-lg-5 mb-4">

            <!-- Regles de surveillance -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i><span data-i18n="modele.titre">Regles de surveillance</span></h6>
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" id="menuPresetsRegles">
                            <li><a class="dropdown-item" href="#" id="btnCreerReglesVide"><i class="bi bi-file-earmark me-1"></i>Jeu vide</a></li>
                            <li><hr class="dropdown-divider"></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body p-0" id="listeRegles">
                    <div class="text-center text-muted py-3" id="reglesVide">
                        <i class="bi bi-shield-check fs-4 d-block mb-1"></i>
                        Aucune regle. Ajoutez un preset ou creez un jeu vide.
                    </div>
                </div>
            </div>

            <!-- Planification -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i><span data-i18n="planif.titre">Planification</span></h6>
                </div>
                <div class="card-body">
                    <div id="blocPlanifExistante" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong id="planifFrequenceLabel">--</strong>
                                <span class="text-muted small ms-2" id="planifProchaineLabel"></span>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="planifActifToggle">
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0" id="btnSupprimerPlanif"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                    <div id="blocPlanifCreer">
                        <div class="d-flex gap-2 align-items-end">
                            <select class="form-select form-select-sm" id="planifFrequence" style="width:auto;">
                                <option value="360">6h</option>
                                <option value="720">12h</option>
                                <option value="1440" selected>24h</option>
                                <option value="10080">Hebdo</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" id="btnCreerPlanif">
                                <i class="bi bi-clock-history me-1"></i>Activer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Changements recents pour ce client -->
    <div class="card mb-4" id="cardChangementsClient">
        <div class="card-header">
            <h6 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right me-2"></i>Derniers changements</h6>
        </div>
        <div class="card-body p-0" id="corpsChangementsClient"></div>
        <div class="card-footer text-center py-3" id="changementsClientVide">
            <i class="bi bi-check-circle text-success d-block mb-1"></i>
            <span class="text-muted small">Aucun changement detecte.</span>
        </div>
    </div>

</div><!-- /container -->

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>
<template id="tplToast">
    <div class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</template>

<!-- Modal confirmation -->
<div class="modal fade" id="modalConfirmation" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold">Confirmer</h5>
            </div>
            <div class="modal-body"><p id="confirmationMessage" class="mb-0"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" id="btnConfirmerSuppression">Confirmer</button>
            </div>
        </div>
    </div>
</div>

<?php $v = '1.3.0'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="commun.js?v=<?=$v?>"></script>
<script src="translations.js?v=<?=$v?>"></script>
<script src="client-page.js?v=<?=$v?>"></script>
</body>
</html>
