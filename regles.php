<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Monitor — Regles</title>
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
        <?php if (!defined('PLATFORM_EMBEDDED')): ?>
        <div class="btn-group btn-group-sm" id="langSelector">
            <button type="button" class="btn btn-outline-light" data-lang="fr">FR</button>
            <button type="button" class="btn btn-outline-light" data-lang="en">EN</button>
        </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container pb-5">

    <!-- Header avec retour -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="<?php echo defined('PLATFORM_EMBEDDED') ? '.' : 'index.php'; ?>"
               class="btn btn-outline-secondary btn-sm" id="btnRetour">
                <i class="bi bi-arrow-left me-1"></i><span data-i18n="action.retour">Retour</span>
            </a>
            <div>
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-list-check me-2"></i><span data-i18n="regle.titre">Regles</span>
                    <span class="text-muted fw-normal" id="nomModele"></span>
                </h5>
            </div>
        </div>
        <div class="d-flex gap-2">
            <div class="dropdown">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-lightning me-1"></i><span data-i18n="regle.presets">Presets</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="menuPresets"></ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-collection me-1"></i><span data-i18n="regle.templates">Modeles</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="menuTemplates"></ul>
            </div>
        </div>
    </div>

    <!-- Tableau des regles -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-regles" id="tableRegles">
                    <thead>
                        <tr>
                            <th class="col-type" data-i18n="regle.type">Type</th>
                            <th class="col-nom" data-i18n="regle.nom">Nom</th>
                            <th class="col-valeur" data-i18n="regle.valeur">Valeur</th>
                            <th class="col-severite" data-i18n="regle.severite">Severite</th>
                            <th class="col-actif" data-i18n="regle.actif">Actif</th>
                            <th class="col-actions" data-i18n="regle.actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bodyRegles">
                        <tr id="rowReglesVide">
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-funnel fs-3 d-block mb-2"></i>
                                <span data-i18n="regle.aucune">Aucune regle definie. Ajoutez-en une ci-dessous ou utilisez un preset.</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="ligne-ajout">
                            <td>
                                <select class="form-select form-select-sm" id="ajoutType">
                                    <option value="">— Type —</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm" id="ajoutNom" placeholder="Nom (auto)">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm" id="ajoutValeur" placeholder="Valeur...">
                            </td>
                            <td>
                                <select class="form-select form-select-sm" id="ajoutSeverite">
                                    <option value="info">Info</option>
                                    <option value="avertissement">Avertissement</option>
                                    <option value="erreur" selected>Erreur</option>
                                    <option value="critique">Critique</option>
                                </select>
                            </td>
                            <td></td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm" id="btnAjouterRegle" title="Ajouter">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Aide format valeur -->
    <div class="card mt-3" id="carteAideValeur" style="display:none;">
        <div class="card-body py-2 px-3">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                <span id="aideValeurTexte"></span>
            </small>
        </div>
    </div>

</div><!-- /container -->

<!-- Modal : Confirmation de suppression -->
<div class="modal fade" id="modalConfirmation" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:1rem;overflow:hidden;">
            <div class="modal-header" style="background:var(--brand-dark);border-bottom:2.5px solid var(--brand-gold);">
                <h5 class="modal-title text-white fw-bold" data-i18n="modal.confirmation.titre">Confirmer la suppression</h5>
            </div>
            <div class="modal-body">
                <p id="confirmationMessage" class="mb-0">Etes-vous sur ?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" data-i18n="action.annuler">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" id="btnConfirmerSuppression" data-i18n="action.confirmer">Confirmer</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>
<template id="tplToast">
    <div class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="commun.js"></script>
<script src="translations.js"></script>
<script src="regles-config.js"></script>
<script src="regles-page.js"></script>
</body>
</html>
