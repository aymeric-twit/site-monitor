/**
 * Site Monitor — Application JavaScript principale.
 *
 * Gere le dashboard, les modales CRUD, le lancement de verifications
 * et le polling de progression.
 *
 * Depend de : commun.js (baseUrl, langueActuelle, t, traduirePage,
 *             afficherToast, ouvrirConfirmation, getCsrfToken, apiGet, apiPost,
 *             echapper, estJsonValide)
 */

'use strict';

// ---------------------------------------------------------------------------
// i18n — Extension specifique au dashboard
// ---------------------------------------------------------------------------

/** Change la langue active et retraduit la page. */
function changerLangue(code) {
    langueActuelle = code;
    document.querySelectorAll('#langSelector .btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-lang') === code);
    });
    traduirePage();
    // Recharger les donnees pour rendre les elements generes dynamiquement
    chargerDashboard();
    if (document.getElementById('pane-modeles').classList.contains('show')) {
        chargerModeles();
    }
    if (document.getElementById('pane-executions').classList.contains('show')) {
        chargerExecutions();
    }
}

// ---------------------------------------------------------------------------
// Cache local
// ---------------------------------------------------------------------------

/** Clients charges depuis le dashboard (utilise pour remplir les selects). */
let cacheClients = [];
/** Client actuellement affiche dans la modale detail. */
let detailClientActuelId = null;

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

async function chargerDashboard() {
    try {
        const res = await apiGet({ entite: 'dashboard' });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const d = res.donnees;

        // KPIs compacts (3 seulement)
        document.getElementById('kpiClientsActifs').textContent = d.stats_clients?.actifs ?? 0;
        document.getElementById('kpiUrlsSurveillees').textContent = d.stats_executions?.urls_surveillees ?? 0;
        const taux = d.stats_executions?.taux_reussite;
        document.getElementById('kpiTauxReussite').textContent = taux !== null && taux !== undefined ? taux + '%' : '\u2014';

        // Cache clients
        cacheClients = (d.clients || []).map(c => ({
            ...c,
            id: c.id ?? c.client_id,
        }));

        // Remplir les selects client dans les modales + filtre tendances
        remplirSelectsClient(cacheClients);
        remplirFiltreTendancesClient(cacheClients);

        // Recap clients
        renderRecapClients(cacheClients);

        // Masquer les blocs vides si aucun client
        const aucunClient = !cacheClients || cacheClients.length === 0;
        document.getElementById('cardSanteClients').style.display = aucunClient ? 'none' : '';
        document.getElementById('cardTendances').style.display = aucunClient ? 'none' : '';

        // Alertes recentes
        renderAlertesRecentes(d.alertes_recentes || []);
        mettreAJourBadgeAlertes(d.alertes_non_lues || 0);

        // Charger les sections en parallele
        const promesses = [chargerChangementsFeed()];
        if (!aucunClient) {
            promesses.push(chargerSanteParClient(), chargerTendances());
        }
        Promise.all(promesses);

    } catch (e) {
        console.error('chargerDashboard:', e);
        afficherToast(t('message.erreur_reseau'), 'danger');
    }
}

function renderRecapClients(clients) {
    const tbody = document.getElementById('bodyRecapClients');
    const rowVide = document.getElementById('rowRecapClientsVide');
    if (!tbody) return;

    tbody.querySelectorAll('tr:not(#rowRecapClientsVide)').forEach(tr => tr.remove());

    if (!clients || clients.length === 0) {
        rowVide.style.display = '';
        return;
    }
    rowVide.style.display = 'none';

    clients.forEach(c => {
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', () => ouvrirDetailClient(c.id));

        const taux = c.taux_reussite_dernier;
        let tauxHtml = '<span class="text-muted">\u2014</span>';
        if (taux !== null && taux !== undefined) {
            const couleur = taux >= 90 ? 'text-success' : (taux >= 50 ? 'text-warning' : 'text-danger');
            tauxHtml = `<span class="fw-bold ${couleur}">${taux}%</span>`;
        }

        const dernierStatut = c.derniere_execution
            ? '<span class="badge bg-success">OK</span>'
            : '<span class="badge bg-secondary">' + t('dashboard.jamais_verifie', 'Jamais') + '</span>';

        tr.innerHTML = `
            <td class="fw-semibold">${echapper(c.nom)}</td>
            <td><span class="text-muted">${echapper(c.domaine)}</span></td>
            <td>${c.nb_urls ?? 0}</td>
            <td>${dernierStatut}</td>
            <td>${tauxHtml}</td>
            <td>
                <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                    <button type="button" class="btn btn-outline-primary btn-sm" title="${t('client.voir')}" onclick="ouvrirDetailClient(${c.id})">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" title="${t('client.lancer')}" onclick="ouvrirLancerVerification(${c.id})">
                        <i class="bi bi-play-fill"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" title="${t('client.modifier')}" onclick="ouvrirEditionClient(${c.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function remplirSelectsClient(clients) {
    // Select dans la modale modele
    const selModele = document.getElementById('modeleClientId');
    if (selModele) {
        const valeur = selModele.value;
        selModele.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
        clients.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nom;
            selModele.appendChild(opt);
        });
        selModele.value = valeur;
    }

    // Select dans la modale lancer verification
    const selVerif = document.getElementById('verifClientId');
    if (selVerif) {
        const valeur = selVerif.value;
        selVerif.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
        clients.forEach(c => {
            if (!c.actif) return;
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nom + ' (' + c.domaine + ')';
            selVerif.appendChild(opt);
        });
        selVerif.value = valeur;
    }

    // Select filtre executions
    const selFiltreExec = document.getElementById('filtreExecutionClient');
    if (selFiltreExec) {
        const valeur = selFiltreExec.value;
        selFiltreExec.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
        clients.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nom;
            selFiltreExec.appendChild(opt);
        });
        selFiltreExec.value = valeur;
    }
}

// ---------------------------------------------------------------------------
// Client : CRUD
// ---------------------------------------------------------------------------

function reinitialiserFormClient() {
    const form = document.getElementById('formClient');
    form.reset();
    document.getElementById('clientId').value = '';
    document.getElementById('clientActif').checked = true;
    document.getElementById('modalClientLabel').textContent = t('client.ajouter', 'Ajouter un client');
}

async function ouvrirEditionClient(id) {
    try {
        const res = await apiGet({ entite: 'client', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const c = res.donnees;
        document.getElementById('clientId').value = c.id;
        document.getElementById('clientNom').value = c.nom || '';
        document.getElementById('clientDomaine').value = c.domaine || '';
        document.getElementById('clientEmail').value = c.email_contact || '';
        document.getElementById('clientActif').checked = !!c.actif;
        document.getElementById('modalClientLabel').textContent = t('client.modifier', 'Modifier le client');

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalClient'));
        modal.show();
    } catch (e) {
        console.error('ouvrirEditionClient:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function sauvegarderClient(e) {
    e.preventDefault();
    const id = document.getElementById('clientId').value;
    const action = id ? 'modifier' : 'creer';
    const donnees = {
        entite: 'client',
        action,
        nom: document.getElementById('clientNom').value,
        domaine: document.getElementById('clientDomaine').value,
        email_contact: document.getElementById('clientEmail').value,
        actif: document.getElementById('clientActif').checked ? '1' : '0',
    };
    if (id) donnees.id = id;

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || t('message.succes'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalClient'))?.hide();
        chargerDashboard();
    } catch (e) {
        console.error('sauvegarderClient:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function supprimerClient(id, nom) {
    ouvrirConfirmation(
        t('client.confirmer_suppression', `Etes-vous sur de vouloir supprimer le client "${nom}" et toutes ses donnees ?`),
        async () => {
            try {
                const res = await apiPost({ entite: 'client', action: 'supprimer', id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('client.supprime'), 'success');
                chargerDashboard();
            } catch (e) {
                console.error('supprimerClient:', e);
                afficherToast(t('message.erreur'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Setup rapide — creation de groupes + URLs en batch
// ---------------------------------------------------------------------------

let _setupGroupeIndex = 0;

function ouvrirSetupRapide(clientId, clientNom) {
    _setupGroupeIndex = 0;
    const container = document.getElementById('setupGroupes');
    container.innerHTML = '';

    document.getElementById('setupClientId').value = clientId || '';
    document.getElementById('setupClientNom').textContent = clientNom ? ('\u2014 ' + clientNom) : '';

    // Gerer le select client
    const blocSelect = document.getElementById('blocSetupSelectClient');
    if (clientId) {
        blocSelect.style.display = 'none';
    } else {
        blocSelect.style.display = '';
        const select = document.getElementById('setupSelectClient');
        select.innerHTML = '<option value="">-- Choisir un client --</option>';
        (cacheClients || []).forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nom + ' (' + c.domaine + ')';
            select.appendChild(opt);
        });
    }

    // Peupler le select template
    peuplerSelectSetupTemplate();

    // Reset checkbox lancer
    const cbLancer = document.getElementById('setupLancerImmediatement');
    if (cbLancer) cbLancer.checked = true;

    ajouterBlocGroupe();
}

async function peuplerSelectSetupTemplate() {
    const select = document.getElementById('setupTemplate');
    if (!select) return;
    select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
    try {
        const res = await apiGet({ entite: 'modele', action: 'templates' });
        if (!res.donnees) return;
        for (const [cle, tpl] of Object.entries(res.donnees)) {
            const opt = document.createElement('option');
            opt.value = cle;
            opt.textContent = tpl.nom + ' (' + tpl.nb_regles + ' regles)';
            select.appendChild(opt);
        }
    } catch (e) {
        console.error('peuplerSelectSetupTemplate:', e);
    }
}

function ajouterBlocGroupe() {
    const container = document.getElementById('setupGroupes');
    const idx = _setupGroupeIndex++;
    const div = document.createElement('div');
    div.className = 'card mb-3 setup-groupe-bloc';
    div.dataset.index = idx;
    div.innerHTML = `
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0 fw-semibold small">${t('setup.nomGroupe', 'Nom du groupe')}</label>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="this.closest('.setup-groupe-bloc').remove()" title="Supprimer">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <input type="text" class="form-control form-control-sm mb-2 setup-groupe-nom" placeholder="Ex: Pages produits">
            <label class="form-label mb-1 small text-muted">${t('setup.urlsPlaceholder', 'URLs (une par ligne)')}</label>
            <textarea class="form-control form-control-sm font-monospace setup-groupe-urls" rows="4" placeholder="https://www.example.com/page1&#10;https://www.example.com/page2"></textarea>
        </div>
    `;
    container.appendChild(div);
}

async function sauvegarderSetupRapide() {
    const clientId = document.getElementById('setupClientId').value
        || document.getElementById('setupSelectClient').value;

    if (!clientId) {
        afficherToast(t('setup.selectClient', 'Selectionnez un client'), 'warning');
        return;
    }

    const blocs = document.querySelectorAll('.setup-groupe-bloc');
    const groupes = [];

    blocs.forEach(bloc => {
        const nom = bloc.querySelector('.setup-groupe-nom').value.trim();
        const urlsTexte = bloc.querySelector('.setup-groupe-urls').value.trim();
        if (nom) {
            groupes.push({
                nom,
                urls: urlsTexte.split('\n').map(l => l.trim()).filter(l => l !== ''),
            });
        }
    });

    if (groupes.length === 0) {
        afficherToast(t('setup.ajouterGroupe', 'Ajoutez au moins un groupe'), 'warning');
        return;
    }

    const templateModele = document.getElementById('setupTemplate')?.value || '';
    const lancer = document.getElementById('setupLancerImmediatement')?.checked ? '1' : '0';

    try {
        const res = await apiPost({
            entite: 'groupe',
            action: 'creer_lot',
            client_id: clientId,
            groupes: JSON.stringify(groupes),
            template_modele: templateModele,
            lancer: lancer,
        });

        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }

        const d = res.donnees || {};
        let msg = (d.groupes_crees || 0) + ' groupe(s), ' + (d.urls_creees || 0) + ' URLs';
        if (d.regles_creees > 0) msg += ', ' + d.regles_creees + ' regles';
        msg += ' crees';
        afficherToast(msg, 'success');

        bootstrap.Modal.getInstance(document.getElementById('modalSetupRapide'))?.hide();

        // Si une verification a ete lancee, demarrer le polling
        if (d.job_id) {
            demarrerPolling(d.job_id);
        }

        chargerDashboard();

        // Rafraichir le detail client si ouvert
        if (detailClientActuelId) {
            chargerGroupesDetail(detailClientActuelId);
            chargerUrlsDetail(detailClientActuelId);
        }
    } catch (e) {
        console.error('sauvegarderSetupRapide:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Detail Client (modale drilldown)
// ---------------------------------------------------------------------------

async function ouvrirDetailClient(id) {
    detailClientActuelId = id;

    try {
        const res = await apiGet({ entite: 'client', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const c = res.donnees;

        document.getElementById('detailClientNom').textContent = c.nom || '';
        document.getElementById('detailClientDomaine').textContent = c.domaine || '';
        document.getElementById('detailClientEmail').textContent = c.email_contact || '\u2014';

        // Reinitialiser sur le premier onglet
        const tabGroupes = document.getElementById('tab-detail-groupes');
        if (tabGroupes) bootstrap.Tab.getOrCreateInstance(tabGroupes).show();

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetailClient'));
        modal.show();

        // Charger les sous-donnees en parallele
        await Promise.all([
            chargerGroupesDetail(id),
            chargerUrlsDetail(id),
            chargerModelesDetail(id),
            chargerPlanificationDetail(id),
        ]);

    } catch (e) {
        console.error('ouvrirDetailClient:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function chargerGroupesDetail(clientId) {
    try {
        const res = await apiGet({ entite: 'groupe', action: 'lister', client_id: clientId });
        const groupes = res.donnees || [];
        document.getElementById('badgeGroupes').textContent = groupes.length;

        const conteneur = document.getElementById('listeGroupesDetail');
        if (groupes.length === 0) {
            conteneur.innerHTML = `<p class="text-muted text-center py-3">${t('groupe.aucun', 'Aucun groupe.')}</p>`;
            return;
        }

        // Remplir le select de filtre URLs
        const filtre = document.getElementById('filtreGroupeUrlsDetail');
        if (filtre) {
            filtre.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
            groupes.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g.id;
                opt.textContent = g.nom;
                filtre.appendChild(opt);
            });
        }

        let html = '';
        groupes.forEach(g => {
            const statutBadge = g.actif
                ? '<span class="badge bg-success">Actif</span>'
                : '<span class="badge bg-secondary">Inactif</span>';
            html += `
                <div class="card mb-2">
                    <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${echapper(g.nom)}</strong>
                            <small class="text-muted ms-2">${echapper(g.description || '')}</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            ${statutBadge}
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ouvrirEditionGroupe(${g.id})" title="${t('groupe.modifier')}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="supprimerGroupe(${g.id}, '${echapper(g.nom)}')" title="${t('client.supprimer')}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        conteneur.innerHTML = html;

    } catch (e) {
        console.error('chargerGroupesDetail:', e);
    }
}

async function chargerUrlsDetail(clientId) {
    try {
        // Recuperer les groupes d'abord pour charger les URLs de chaque groupe
        const resGroupes = await apiGet({ entite: 'groupe', action: 'lister', client_id: clientId });
        const groupes = resGroupes.donnees || [];

        let toutesUrls = [];
        for (const g of groupes) {
            const resUrls = await apiGet({ entite: 'url', action: 'lister', groupe_id: g.id });
            const urls = (resUrls.donnees || []).map(u => ({ ...u, nom_groupe: g.nom }));
            toutesUrls = toutesUrls.concat(urls);
        }

        document.getElementById('badgeUrls').textContent = toutesUrls.length;

        const tbody = document.getElementById('bodyUrlsDetail');
        if (toutesUrls.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">${t('url.aucune', 'Aucune URL configuree.')}</td></tr>`;
            return;
        }

        let html = '';
        toutesUrls.forEach(u => {
            const statutBadge = u.dernier_statut
                ? `<span class="badge ${u.dernier_statut === 'succes' ? 'bg-success' : 'bg-danger'}">${echapper(u.dernier_statut)}</span>`
                : '<span class="badge bg-secondary">\u2014</span>';
            const urlAffichee = u.url.length > 60 ? u.url.substring(0, 57) + '...' : u.url;
            html += `
                <tr>
                    <td><a href="${echapper(u.url)}" target="_blank" rel="noopener" class="text-decoration-none small">${echapper(urlAffichee)}</a></td>
                    <td class="small">${echapper(u.libelle || '')}</td>
                    <td class="small">${echapper(u.nom_groupe || '')}</td>
                    <td>${statutBadge}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirEditionUrl(${u.id})" title="${t('url.modifier')}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="supprimerUrl(${u.id})" title="${t('client.supprimer')}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

        // Stocker les URLs pour le filtre local
        window._detailUrls = toutesUrls;

    } catch (e) {
        console.error('chargerUrlsDetail:', e);
    }
}

async function chargerModelesDetail(clientId) {
    try {
        const res = await apiGet({ entite: 'modele', action: 'lister' });
        const tousModeles = res.donnees || [];
        // Modeles associes a ce client ou globaux
        const modeles = tousModeles.filter(m => m.est_global || String(m.client_id) === String(clientId) || !m.client_id);

        document.getElementById('badgeModeles').textContent = modeles.length;

        const conteneur = document.getElementById('listeModelesDetail');
        if (modeles.length === 0) {
            conteneur.innerHTML = `<p class="text-muted text-center py-3">${t('modele.aucun', 'Aucun modele associe.')}</p>`;
            return;
        }

        let html = '';
        modeles.forEach(m => {
            const globalBadge = m.est_global
                ? '<span class="badge bg-info ms-1">Global</span>'
                : '';
            html += `
                <div class="card mb-2">
                    <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${echapper(m.nom)}</strong>${globalBadge}
                            <small class="text-muted ms-2">${echapper(m.description || '')}</small>
                        </div>
                        <span class="badge bg-secondary">${m.nombre_regles ?? 0} ${t('modele.regles', 'regles')}</span>
                    </div>
                </div>
            `;
        });
        conteneur.innerHTML = html;

    } catch (e) {
        console.error('chargerModelesDetail:', e);
    }
}

// ---------------------------------------------------------------------------
// Groupes : CRUD
// ---------------------------------------------------------------------------

function reinitialiserFormGroupe() {
    const form = document.getElementById('formGroupe');
    form.reset();
    document.getElementById('groupeId').value = '';
    document.getElementById('groupeActif').checked = true;
    document.getElementById('modalGroupeLabel').textContent = t('groupe.ajouter', 'Ajouter un groupe');
}

// ---------------------------------------------------------------------------
// Planifications — verification automatique periodique
// ---------------------------------------------------------------------------

let _planifActuelleId = null;

async function chargerPlanificationDetail(clientId) {
    try {
        const res = await apiGet({ entite: 'planification', action: 'lister', client_id: clientId });
        if (res.erreur) return;

        const planifs = res.donnees || [];
        const blocExistante = document.getElementById('blocPlanifExistante');
        const blocCreer = document.getElementById('blocPlanifCreer');

        if (planifs.length > 0) {
            const p = planifs[0];
            _planifActuelleId = p.id;
            blocExistante.style.display = '';
            blocCreer.style.display = 'none';

            const freqLabels = { 360: 'Toutes les 6h', 720: 'Toutes les 12h', 1440: 'Quotidien', 10080: 'Hebdomadaire' };
            document.getElementById('planifFrequenceLabel').textContent = freqLabels[p.frequence_minutes] || (p.frequence_minutes + ' min');
            document.getElementById('planifProchaineLabel').textContent = p.prochaine_execution
                ? t('planif.prochaine', 'Prochaine') + ' : ' + new Date(p.prochaine_execution).toLocaleString('fr-FR')
                : '';
            document.getElementById('planifActifToggle').checked = !!p.actif;
        } else {
            _planifActuelleId = null;
            blocExistante.style.display = 'none';
            blocCreer.style.display = '';
        }
    } catch (e) {
        console.error('chargerPlanificationDetail:', e);
    }
}

async function creerPlanification() {
    if (!detailClientActuelId) return;
    const frequence = document.getElementById('planifFrequence').value;

    try {
        const res = await apiPost({
            entite: 'planification',
            action: 'creer',
            client_id: detailClientActuelId,
            frequence_minutes: frequence,
        });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || t('planif.creee', 'Planification activee'), 'success');
        chargerPlanificationDetail(detailClientActuelId);
    } catch (e) {
        console.error('creerPlanification:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function togglePlanification(actif) {
    if (!_planifActuelleId) return;
    try {
        await apiPost({ entite: 'planification', action: 'modifier', id: _planifActuelleId, actif: actif ? '1' : '0' });
    } catch (e) {
        console.error('togglePlanification:', e);
    }
}

async function supprimerPlanification() {
    if (!_planifActuelleId) return;
    try {
        await apiPost({ entite: 'planification', action: 'supprimer', id: _planifActuelleId });
        afficherToast(t('planif.supprimee', 'Planification supprimee'), 'success');
        chargerPlanificationDetail(detailClientActuelId);
    } catch (e) {
        console.error('supprimerPlanification:', e);
    }
}

// ---------------------------------------------------------------------------
// Import sitemap
// ---------------------------------------------------------------------------

let _sitemapUrlsDecouvertes = [];
let _sitemapGroupesSuggeres = {};
let _sitemapTargetClientId = null;
let _sitemapTargetGroupeId = null;

async function ouvrirImportSitemap(clientId, groupeId) {
    _sitemapTargetClientId = clientId || detailClientActuelId;
    _sitemapTargetGroupeId = groupeId || null;
    _sitemapUrlsDecouvertes = [];

    document.getElementById('sitemapClientId').value = _sitemapTargetClientId || '';
    document.getElementById('sitemapChargement').style.display = '';
    document.getElementById('sitemapResultats').style.display = 'none';
    document.getElementById('sitemapListeUrls').innerHTML = '';
    document.getElementById('sitemapGroupes').innerHTML = '';

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalImportSitemap'));
    modal.show();

    try {
        const res = await apiGet({ entite: 'url', action: 'decouvrir_sitemap', client_id: _sitemapTargetClientId });
        document.getElementById('sitemapChargement').style.display = 'none';

        if (res.erreur) {
            document.getElementById('sitemapResultats').style.display = '';
            document.getElementById('sitemapListeUrls').innerHTML = '<div class="p-3 text-danger">' + echapper(res.erreur) + '</div>';
            return;
        }

        const data = res.donnees;
        _sitemapUrlsDecouvertes = data.urls || [];
        _sitemapGroupesSuggeres = data.groupes_suggeres || {};

        document.getElementById('sitemapTotal').textContent = data.total || 0;
        document.getElementById('sitemapResultats').style.display = '';

        renderSitemapGroupes();
        renderSitemapUrls('');
    } catch (e) {
        console.error('ouvrirImportSitemap:', e);
        document.getElementById('sitemapChargement').style.display = 'none';
        document.getElementById('sitemapResultats').style.display = '';
        document.getElementById('sitemapListeUrls').innerHTML = '<div class="p-3 text-danger">' + echapper(e.message) + '</div>';
    }
}

function renderSitemapGroupes() {
    const container = document.getElementById('sitemapGroupes');
    const entries = Object.entries(_sitemapGroupesSuggeres);
    if (entries.length === 0) return;

    container.innerHTML = '<label class="form-label small fw-semibold mb-1">' + t('sitemap.groupesSuggeres', 'Groupes detectes') + '</label>' +
        '<div class="d-flex flex-wrap gap-1">' +
        entries.slice(0, 15).map(([nom, data]) =>
            `<button type="button" class="btn btn-outline-primary btn-sm sitemap-groupe-btn" data-groupe="${echapper(nom)}">
                ${echapper(nom)} <span class="badge bg-primary ms-1">${data.total}</span>
            </button>`
        ).join('') +
        '</div>';
}

function renderSitemapUrls(filtre) {
    const container = document.getElementById('sitemapListeUrls');
    const filtreLC = filtre.toLowerCase();

    const urlsFiltrees = filtreLC
        ? _sitemapUrlsDecouvertes.filter(u => u.toLowerCase().includes(filtreLC))
        : _sitemapUrlsDecouvertes;

    container.innerHTML = urlsFiltrees.slice(0, 200).map(url =>
        `<div class="d-flex align-items-center px-2 py-1 border-bottom sitemap-url-row">
            <input type="checkbox" class="form-check-input me-2 sitemap-url-cb" value="${echapper(url)}" checked>
            <span class="small font-monospace text-truncate" title="${echapper(url)}">${echapper(url)}</span>
        </div>`
    ).join('');

    if (urlsFiltrees.length > 200) {
        container.innerHTML += '<div class="p-2 text-muted small text-center">... et ' + (urlsFiltrees.length - 200) + ' autres</div>';
    }

    majCompteurSitemap();
}

function majCompteurSitemap() {
    const cochees = document.querySelectorAll('#sitemapListeUrls .sitemap-url-cb:checked').length;
    document.getElementById('sitemapSelectionCount').textContent = cochees;
}

async function importerSitemapSelection() {
    const cochees = document.querySelectorAll('#sitemapListeUrls .sitemap-url-cb:checked');
    if (cochees.length === 0) {
        afficherToast(t('sitemap.aucuneSelection', 'Selectionnez au moins une URL'), 'warning');
        return;
    }

    const urls = Array.from(cochees).map(cb => cb.value);

    // Si on est dans le contexte setup rapide, injecter les URLs dans un bloc groupe
    const setupModal = document.getElementById('modalSetupRapide');
    if (setupModal && setupModal.classList.contains('show')) {
        // Fermer le modal sitemap et injecter dans le dernier bloc groupe du setup
        bootstrap.Modal.getInstance(document.getElementById('modalImportSitemap'))?.hide();
        const blocs = document.querySelectorAll('.setup-groupe-bloc');
        const dernierBloc = blocs[blocs.length - 1];
        if (dernierBloc) {
            const textarea = dernierBloc.querySelector('.setup-groupe-urls');
            textarea.value = (textarea.value ? textarea.value + '\n' : '') + urls.join('\n');
        }
        afficherToast(urls.length + ' URLs importees dans le setup', 'success');
        return;
    }

    // Sinon, creer les URLs dans le groupe cible via l'API batch
    if (!_sitemapTargetClientId) {
        afficherToast(t('message.erreur'), 'danger');
        return;
    }

    // Si pas de groupe cible, creer un groupe "Sitemap import"
    let groupeId = _sitemapTargetGroupeId;
    if (!groupeId) {
        try {
            const resGroupe = await apiPost({
                entite: 'groupe', action: 'creer',
                client_id: _sitemapTargetClientId,
                nom: 'Import sitemap',
            });
            groupeId = resGroupe.donnees?.id;
        } catch (e) {
            afficherToast(t('message.erreur'), 'danger');
            return;
        }
    }

    try {
        const res = await apiPost({
            entite: 'url', action: 'creer_lot',
            groupe_id: groupeId,
            urls: urls.join('\n'),
        });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || urls.length + ' URLs importees', 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalImportSitemap'))?.hide();
        if (detailClientActuelId) {
            chargerUrlsDetail(detailClientActuelId);
            chargerGroupesDetail(detailClientActuelId);
        }
        chargerDashboard();
    } catch (e) {
        console.error('importerSitemapSelection:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Groupes
// ---------------------------------------------------------------------------

function ouvrirAjoutGroupe(clientId) {
    reinitialiserFormGroupe();
    document.getElementById('groupeClientId').value = clientId;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalGroupe'));
    modal.show();
}

async function ouvrirEditionGroupe(id) {
    try {
        const res = await apiGet({ entite: 'groupe', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const g = res.donnees;
        document.getElementById('groupeId').value = g.id;
        document.getElementById('groupeClientId').value = g.client_id;
        document.getElementById('groupeNom').value = g.nom || '';
        document.getElementById('groupeDescription').value = g.description || '';
        document.getElementById('groupeActif').checked = !!g.actif;
        document.getElementById('modalGroupeLabel').textContent = t('groupe.modifier', 'Modifier le groupe');

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalGroupe'));
        modal.show();
    } catch (e) {
        console.error('ouvrirEditionGroupe:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function sauvegarderGroupe(e) {
    e.preventDefault();
    const id = document.getElementById('groupeId').value;
    const action = id ? 'modifier' : 'creer';
    const donnees = {
        entite: 'groupe',
        action,
        client_id: document.getElementById('groupeClientId').value,
        nom: document.getElementById('groupeNom').value,
        description: document.getElementById('groupeDescription').value,
        actif: document.getElementById('groupeActif').checked ? '1' : '0',
    };
    if (id) donnees.id = id;

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || t('message.succes'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalGroupe'))?.hide();

        // Rafraichir le detail client si ouvert
        if (detailClientActuelId) {
            await chargerGroupesDetail(detailClientActuelId);
            await chargerUrlsDetail(detailClientActuelId);
        }
        chargerDashboard();
    } catch (e) {
        console.error('sauvegarderGroupe:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function supprimerGroupe(id, nom) {
    ouvrirConfirmation(
        `${t('message.confirmer_suppression')} "${nom}"`,
        async () => {
            try {
                const res = await apiPost({ entite: 'groupe', action: 'supprimer', id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('message.succes'), 'success');
                if (detailClientActuelId) {
                    await chargerGroupesDetail(detailClientActuelId);
                    await chargerUrlsDetail(detailClientActuelId);
                }
                chargerDashboard();
            } catch (e) {
                console.error('supprimerGroupe:', e);
                afficherToast(t('message.erreur'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// URLs : CRUD
// ---------------------------------------------------------------------------

function reinitialiserFormUrl() {
    const form = document.getElementById('formUrl');
    form.reset();
    document.getElementById('urlId').value = '';
    document.getElementById('urlActif').checked = true;
    document.getElementById('modalUrlLabel').textContent = t('url.ajouter', 'Ajouter une URL');

    // Revenir en mode simple
    document.getElementById('blocUrlSimple').style.display = '';
    document.getElementById('blocUrlMultiple').style.display = 'none';
    document.getElementById('blocToggleUrlMode').style.display = '';
    document.getElementById('urlsTextarea').value = '';
    document.getElementById('urlsCompteur').textContent = '0';
    const toggle = document.getElementById('toggleUrlMode');
    toggle.querySelector('span').textContent = t('modal.url.ajoutMultiple', 'Ajouter plusieurs URLs');
    toggle.dataset.mode = 'simple';
}

async function ouvrirAjoutUrl(groupeId) {
    reinitialiserFormUrl();
    document.getElementById('urlGroupeId').value = groupeId;
    await chargerCheckboxesModeles(null);
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUrl'));
    modal.show();
}

async function ouvrirAjoutUrlPourClient(clientId) {
    reinitialiserFormUrl();

    // Recuperer les groupes du client pour pre-remplir le groupe
    try {
        const res = await apiGet({ entite: 'groupe', action: 'lister', client_id: clientId });
        const groupes = res.donnees || [];
        if (groupes.length === 0) {
            afficherToast(t('groupe.aucun', 'Creez d\'abord un groupe pour ce client.'), 'warning');
            return;
        }
        // Utiliser le premier groupe par defaut
        document.getElementById('urlGroupeId').value = groupes[0].id;

        await chargerCheckboxesModeles(null);
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUrl'));
        modal.show();
    } catch (e) {
        console.error('ouvrirAjoutUrlPourClient:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function ouvrirEditionUrl(id) {
    try {
        const res = await apiGet({ entite: 'url', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const u = res.donnees;
        document.getElementById('urlId').value = u.id;
        document.getElementById('urlGroupeId').value = u.groupe_id;
        document.getElementById('urlAdresse').value = u.url || '';
        document.getElementById('urlLibelle').value = u.libelle || '';
        document.getElementById('urlNotes').value = u.notes || '';
        document.getElementById('urlActif').checked = !!u.actif;
        document.getElementById('modalUrlLabel').textContent = t('url.modifier', 'Modifier l\'URL');

        // Masquer le toggle et forcer mode simple en edition
        document.getElementById('blocToggleUrlMode').style.display = 'none';
        document.getElementById('blocUrlSimple').style.display = '';
        document.getElementById('blocUrlMultiple').style.display = 'none';

        await chargerCheckboxesModeles(u.modeles || []);

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUrl'));
        modal.show();
    } catch (e) {
        console.error('ouvrirEditionUrl:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

/**
 * Charge les checkboxes de modeles dans #urlModelesCheckboxes.
 * @param {number[]|null} modelesCoches - IDs des modeles deja associes
 */
async function chargerCheckboxesModeles(modelesCoches) {
    const conteneur = document.getElementById('urlModelesCheckboxes');
    try {
        const res = await apiGet({ entite: 'modele', action: 'lister' });
        const modeles = res.donnees || [];

        if (modeles.length === 0) {
            conteneur.innerHTML = `<span class="text-muted small">${t('modal.url.aucunModele', 'Aucun modele disponible')}</span>`;
            return;
        }

        const cochesSet = new Set((modelesCoches || []).map(Number));
        let html = '';
        modeles.forEach(m => {
            const checked = cochesSet.has(m.id) ? 'checked' : '';
            html += `
                <div class="form-check">
                    <input class="form-check-input modele-checkbox" type="checkbox" value="${m.id}" id="modeleCheck_${m.id}" ${checked}>
                    <label class="form-check-label small" for="modeleCheck_${m.id}">${echapper(m.nom)}</label>
                </div>
            `;
        });
        conteneur.innerHTML = html;
    } catch (e) {
        console.error('chargerCheckboxesModeles:', e);
        conteneur.innerHTML = `<span class="text-muted small">${t('message.erreur')}</span>`;
    }
}

async function sauvegarderUrl(e) {
    e.preventDefault();
    const id = document.getElementById('urlId').value;
    const modeMultiple = document.getElementById('toggleUrlMode')?.dataset.mode === 'multiple';

    // Mode multiple : ajout en lot
    if (!id && modeMultiple) {
        const urls = document.getElementById('urlsTextarea').value.trim();
        if (!urls) {
            afficherToast(t('modal.url.urlsTextarea', 'Saisissez au moins une URL'), 'warning');
            return;
        }
        try {
            const res = await apiPost({
                entite: 'url',
                action: 'creer_lot',
                groupe_id: document.getElementById('urlGroupeId').value,
                urls: urls,
            });
            if (res.erreur) {
                afficherToast(res.erreur, 'danger');
                return;
            }
            afficherToast(res.message || t('modal.url.urlsAjoutees', 'URLs ajoutees'), 'success');
            bootstrap.Modal.getInstance(document.getElementById('modalUrl'))?.hide();
            if (detailClientActuelId) await chargerUrlsDetail(detailClientActuelId);
            chargerDashboard();
        } catch (err) {
            console.error('sauvegarderUrl (lot):', err);
            afficherToast(t('message.erreur'), 'danger');
        }
        return;
    }

    // Mode simple : creation ou edition unitaire
    const action = id ? 'modifier' : 'creer';
    const donnees = {
        entite: 'url',
        action,
        groupe_id: document.getElementById('urlGroupeId').value,
        url: document.getElementById('urlAdresse').value,
        libelle: document.getElementById('urlLibelle').value,
        notes: document.getElementById('urlNotes').value,
        actif: document.getElementById('urlActif').checked ? '1' : '0',
    };
    if (id) donnees.id = id;

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }

        const urlId = id || res.donnees?.id;

        // Gerer les associations de modeles
        if (urlId) {
            const checkboxes = document.querySelectorAll('#urlModelesCheckboxes .modele-checkbox');
            for (const cb of checkboxes) {
                const modeleId = cb.value;
                if (cb.checked) {
                    await apiPost({ entite: 'url', action: 'associer_modele', url_id: urlId, modele_id: modeleId });
                } else {
                    await apiPost({ entite: 'url', action: 'dissocier_modele', url_id: urlId, modele_id: modeleId });
                }
            }
        }

        afficherToast(res.message || t('message.succes'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalUrl'))?.hide();

        if (detailClientActuelId) {
            await chargerUrlsDetail(detailClientActuelId);
        }
        chargerDashboard();
    } catch (e) {
        console.error('sauvegarderUrl:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function supprimerUrl(id) {
    ouvrirConfirmation(
        t('message.confirmer_suppression', 'Confirmer la suppression ?'),
        async () => {
            try {
                const res = await apiPost({ entite: 'url', action: 'supprimer', id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('message.succes'), 'success');
                if (detailClientActuelId) {
                    await chargerUrlsDetail(detailClientActuelId);
                }
                chargerDashboard();
            } catch (e) {
                console.error('supprimerUrl:', e);
                afficherToast(t('message.erreur'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Modeles : CRUD
// ---------------------------------------------------------------------------

let _templatesCharges = false;
async function peuplerSelectTemplates() {
    if (_templatesCharges) return;
    try {
        const res = await apiGet({ entite: 'modele', action: 'templates' });
        if (res.erreur || !res.donnees) return;
        const select = document.getElementById('modeleTemplate');
        if (!select) return;
        // Garder la premiere option (placeholder)
        select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
        for (const [cle, tpl] of Object.entries(res.donnees)) {
            const opt = document.createElement('option');
            opt.value = cle;
            opt.textContent = tpl.nom + ' (' + tpl.nb_regles + ' regles)';
            select.appendChild(opt);
        }
        _templatesCharges = true;
    } catch (e) {
        console.error('peuplerSelectTemplates:', e);
    }
}

async function chargerModeles() {
    try {
        const res = await apiGet({ entite: 'modele', action: 'lister' });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const modeles = res.donnees || [];

        const conteneur = document.getElementById('listeModeles');
        const vide = document.getElementById('modelesVide');

        // Supprimer les cartes existantes (sauf #modelesVide)
        conteneur.querySelectorAll('.carte-modele').forEach(el => el.remove());

        if (modeles.length === 0) {
            vide.style.display = '';
            return;
        }
        vide.style.display = 'none';

        const tpl = document.getElementById('tplModeleCard');

        for (const m of modeles) {
            const clone = tpl.content.cloneNode(true);
            const card = clone.querySelector('.carte-modele');
            card.setAttribute('data-modele-id', m.id);
            card.querySelector('.nom-modele').textContent = m.nom;
            card.querySelector('.description-modele').textContent = m.description || '';
            card.querySelector('.nombre-regles').textContent = (m.nb_regles ?? 0) + ' ' + t('modele.regles', 'regles');

            if (m.est_global) {
                card.querySelector('.badge-modele-global').style.display = '';
            }

            // Boutons
            card.querySelector('.btn-editer-modele').addEventListener('click', (e) => {
                e.preventDefault();
                ouvrirEditionModele(m.id);
            });
            card.querySelector('.btn-gerer-regles').addEventListener('click', (e) => {
                e.preventDefault();
                ouvrirGestionRegles(m.id, m.nom);
            });
            card.querySelector('.btn-supprimer-modele').addEventListener('click', (e) => {
                e.preventDefault();
                supprimerModele(m.id, m.nom);
            });

            // Apercu des regles
            if (m.nb_regles > 0) {
                await chargerApercuRegles(card, m.id);
            }

            conteneur.appendChild(clone);
        }

    } catch (e) {
        console.error('chargerModeles:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function chargerApercuRegles(cardElement, modeleId) {
    try {
        const res = await apiGet({ entite: 'regle', action: 'lister', modele_id: modeleId });
        const regles = res.donnees || [];
        const conteneur = cardElement.querySelector('.liste-regles-apercu');

        if (regles.length === 0) return;

        let html = '<div class="d-flex flex-wrap gap-1">';
        regles.forEach(r => {
            const couleurSeverite = {
                'info': 'bg-info',
                'avertissement': 'bg-warning text-dark',
                'erreur': 'bg-danger',
                'critique': 'bg-dark',
            };
            const badgeClass = couleurSeverite[r.severite] || 'bg-secondary';
            html += `<span class="badge ${badgeClass}" style="cursor:pointer;" onclick="ouvrirEditionRegle(${r.id})">${echapper(r.nom)}</span>`;
        });
        html += '</div>';
        conteneur.innerHTML = html;
    } catch (e) {
        console.error('chargerApercuRegles:', e);
    }
}

function reinitialiserFormModele() {
    const form = document.getElementById('formModele');
    form.reset();
    document.getElementById('modeleId').value = '';
    document.getElementById('modeleEstGlobal').checked = false;
    document.getElementById('modalModeleLabel').textContent = t('modele.ajouter', 'Creer un modele');

    // Afficher le bloc template (creation uniquement)
    const blocTemplate = document.getElementById('blocModeleTemplate');
    if (blocTemplate) blocTemplate.style.display = '';

    // Peupler le select template si pas encore fait
    peuplerSelectTemplates();
}

async function ouvrirEditionModele(id) {
    try {
        const res = await apiGet({ entite: 'modele', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const m = res.donnees;
        document.getElementById('modeleId').value = m.id;
        document.getElementById('modeleNom').value = m.nom || '';
        document.getElementById('modeleDescription').value = m.description || '';
        document.getElementById('modeleClientId').value = m.client_id || '';
        document.getElementById('modeleEstGlobal').checked = !!m.est_global;
        document.getElementById('modalModeleLabel').textContent = t('modele.modifier', 'Modifier le modele');

        // Masquer le bloc template en mode edition
        const blocTemplate = document.getElementById('blocModeleTemplate');
        if (blocTemplate) blocTemplate.style.display = 'none';

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalModele'));
        modal.show();
    } catch (e) {
        console.error('ouvrirEditionModele:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function sauvegarderModele(e) {
    e.preventDefault();
    const id = document.getElementById('modeleId').value;
    const action = id ? 'modifier' : 'creer';
    const donnees = {
        entite: 'modele',
        action,
        nom: document.getElementById('modeleNom').value,
        description: document.getElementById('modeleDescription').value,
        client_id: document.getElementById('modeleClientId').value,
        est_global: document.getElementById('modeleEstGlobal').checked ? '1' : '0',
    };
    if (id) donnees.id = id;

    // Template uniquement a la creation
    if (!id) {
        const template = document.getElementById('modeleTemplate')?.value;
        if (template) donnees.template = template;
    }

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        let msg = res.message || t('message.succes');
        if (res.donnees?.regles_creees > 0) {
            msg += ' (' + res.donnees.regles_creees + ' ' + t('modal.modele.reglesCreees', 'regles creees') + ')';
        }
        afficherToast(msg, 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalModele'))?.hide();
        chargerModeles();
    } catch (e) {
        console.error('sauvegarderModele:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function supprimerModele(id, nom) {
    ouvrirConfirmation(
        `${t('message.confirmer_suppression')} "${nom}"`,
        async () => {
            try {
                const res = await apiPost({ entite: 'modele', action: 'supprimer', id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('message.succes'), 'success');
                chargerModeles();
            } catch (e) {
                console.error('supprimerModele:', e);
                afficherToast(t('message.erreur'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Regles : CRUD (gestion dans le contexte d'un modele)
// ---------------------------------------------------------------------------

/** Modele en cours de gestion des regles. */
let gestionReglesModeleId = null;
let gestionReglesModeleNom = '';

function ouvrirGestionRegles(modeleId, modeleNom) {
    // Rediriger vers la page dediee de gestion des regles
    window.location.href = baseUrl + '/regles.php?modele_id=' + encodeURIComponent(modeleId);
}

function reinitialiserFormRegle() {
    const form = document.getElementById('formRegle');
    form.reset();
    document.getElementById('regleId').value = '';
    document.getElementById('regleModeleId').value = '';
    document.getElementById('regleActif').checked = true;
    document.getElementById('regleSeverite').value = 'erreur';
    document.getElementById('regleConfiguration').value = '';
    document.getElementById('modalRegleLabel').textContent = t('regle.ajouter', 'Ajouter une regle');

    // Reinitialiser le formulaire dynamique
    const conteneur = document.getElementById('conteneurChampsDynamiques');
    if (conteneur) conteneur.innerHTML = '';
    const toggle = document.getElementById('modeExpertRegle');
    if (toggle) {
        toggle.checked = false;
        const blocJson = document.getElementById('regleConfiguration')?.closest('.mb-3');
        if (blocJson) blocJson.classList.add('d-none');
    }
}

async function ouvrirEditionRegle(id) {
    try {
        const res = await apiGet({ entite: 'regle', action: 'obtenir', id });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const r = res.donnees;
        document.getElementById('regleId').value = r.id;
        document.getElementById('regleModeleId').value = r.modele_id;
        document.getElementById('regleTypeRegle').value = r.type_regle || '';
        document.getElementById('regleSeverite').value = r.severite || 'erreur';
        document.getElementById('regleNom').value = r.nom || '';
        document.getElementById('regleActif').checked = !!r.actif;

        // Configuration : afficher comme JSON et construire le formulaire dynamique
        const config = r.configuration || r.configuration_json;
        const configObj = typeof config === 'string' ? JSON.parse(config || '{}') : (config || {});
        document.getElementById('regleConfiguration').value = JSON.stringify(configObj, null, 2);

        // Construire le formulaire dynamique avec les valeurs existantes
        if (typeof window.construireFormulaire === 'function' && r.type_regle) {
            window.construireFormulaire(r.type_regle, configObj);
        }

        document.getElementById('modalRegleLabel').textContent = t('regle.modifier', 'Modifier la regle');

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRegle'));
        modal.show();
    } catch (e) {
        console.error('ouvrirEditionRegle:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function sauvegarderRegle(e) {
    e.preventDefault();
    const id = document.getElementById('regleId').value;
    const action = id ? 'modifier' : 'creer';

    // Synchroniser le formulaire dynamique vers le textarea (sauf en mode expert)
    const modeExpert = document.getElementById('modeExpertRegle')?.checked;
    if (!modeExpert && typeof window.formulaireVersJson === 'function') {
        const config = window.formulaireVersJson();
        const textarea = document.getElementById('regleConfiguration');
        if (textarea) {
            textarea.value = Object.keys(config).length > 0 ? JSON.stringify(config, null, 2) : '';
        }
    }

    // Valider le JSON de configuration
    const configBrut = document.getElementById('regleConfiguration').value.trim();
    if (configBrut && !estJsonValide(configBrut)) {
        afficherToast('Configuration JSON invalide', 'danger');
        return;
    }

    const donnees = {
        entite: 'regle',
        action,
        modele_id: document.getElementById('regleModeleId').value,
        type_regle: document.getElementById('regleTypeRegle').value,
        severite: document.getElementById('regleSeverite').value,
        nom: document.getElementById('regleNom').value,
        configuration_json: configBrut || '{}',
        actif: document.getElementById('regleActif').checked ? '1' : '0',
    };
    if (id) donnees.id = id;

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || t('message.succes'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('modalRegle'))?.hide();
        // Rafraichir la liste des modeles pour mettre a jour l'apercu des regles
        chargerModeles();
    } catch (e) {
        console.error('sauvegarderRegle:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function supprimerRegle(id) {
    ouvrirConfirmation(
        t('message.confirmer_suppression', 'Confirmer la suppression ?'),
        async () => {
            try {
                const res = await apiPost({ entite: 'regle', action: 'supprimer', id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('message.succes'), 'success');
                chargerModeles();
            } catch (e) {
                console.error('supprimerRegle:', e);
                afficherToast(t('message.erreur'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Executions
// ---------------------------------------------------------------------------

async function chargerExecutions() {
    try {
        const filtreStatut = document.getElementById('filtreExecutionStatut').value;
        const filtreClient = document.getElementById('filtreExecutionClient').value;

        const params = { entite: 'execution', action: 'lister' };
        if (filtreClient) params.client_id = filtreClient;

        const res = await apiGet(params);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }

        let executions = res.donnees || [];

        // Filtrage cote client par statut
        if (filtreStatut) {
            executions = executions.filter(ex => ex.statut === filtreStatut);
        }

        const tbody = document.getElementById('bodyExecutions');
        const rowVide = document.getElementById('rowExecutionsVide');

        // Supprimer les lignes existantes sauf la ligne vide
        tbody.querySelectorAll('tr:not(#rowExecutionsVide)').forEach(tr => tr.remove());

        if (executions.length === 0) {
            rowVide.style.display = '';
            return;
        }
        rowVide.style.display = 'none';

        executions.forEach(ex => {
            const tr = document.createElement('tr');

            const statutCouleurs = {
                'en_attente': 'bg-secondary',
                'en_cours': 'bg-primary',
                'termine': 'bg-success',
                'erreur': 'bg-danger',
                'annule': 'bg-warning text-dark',
            };
            const badgeClass = statutCouleurs[ex.statut] || 'bg-secondary';
            const statutLabel = t('statut.' + (ex.statut || ''), ex.statut || '');

            const dateStr = ex.demarree_le
                ? new Date(ex.demarree_le).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
                : '\u2014';

            const dureeMs = ex.duree_ms;
            const dureeStr = dureeMs
                ? formaterDuree(dureeMs / 1000)
                : '\u2014';

            tr.innerHTML = `
                <td class="small">${dateStr}</td>
                <td>${echapper(ex.client_nom || '\u2014')}</td>
                <td><span class="badge ${badgeClass}">${echapper(statutLabel)}</span></td>
                <td>${ex.urls_total ?? 0}</td>
                <td>
                    <span class="text-success fw-bold">${ex.succes ?? 0}</span> /
                    <span class="text-danger fw-bold">${ex.echecs ?? 0}</span>
                </td>
                <td class="small">${dureeStr}</td>
                <td>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="voirResultatsExecution(${ex.id})" title="${t('execution.voir')}">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });

    } catch (e) {
        console.error('chargerExecutions:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function voirResultatsExecution(executionId) {
    try {
        // Charger le resume par URL et les resultats detailles
        const [resResume, resDetails, resMetriques] = await Promise.all([
            apiGet({ entite: 'resultat', action: 'resume_urls', execution_id: executionId }),
            apiGet({ entite: 'resultat', action: 'par_execution', execution_id: executionId }),
            apiGet({ entite: 'metrique', action: 'par_execution', execution_id: executionId }),
        ]);

        const resume = resResume.donnees || [];
        const details = resDetails.donnees || [];
        const metriques = resMetriques.donnees || [];

        if (resume.length === 0) {
            afficherToast('Aucun resultat pour cette execution.', 'info');
            return;
        }

        // Creer un index des metriques par url_id
        const metriquesParUrl = {};
        metriques.forEach(m => { metriquesParUrl[m.url_id] = m; });

        // Construire le HTML de la modale
        let html = `<div class="mb-3"><strong>Execution #${executionId}</strong> — ${resume.length} URL(s)</div>`;

        resume.forEach(r => {
            const m = metriquesParUrl[r.url_id];
            const metriquesHtml = m ? `
                <div class="d-flex gap-3 mb-2">
                    <span class="metrique-card p-1"><span class="metrique-label">TTFB</span> <span class="metrique-valeur" style="font-size:0.875rem">${Math.round(m.ttfb_ms)}ms</span></span>
                    <span class="metrique-card p-1"><span class="metrique-label">Total</span> <span class="metrique-valeur" style="font-size:0.875rem">${Math.round(m.temps_total_ms)}ms</span></span>
                    <span class="metrique-card p-1"><span class="metrique-label">Taille</span> <span class="metrique-valeur" style="font-size:0.875rem">${formaterOctets(m.taille_octets)}</span></span>
                    <span class="metrique-card p-1"><span class="metrique-label">HTTP</span> <span class="metrique-valeur" style="font-size:0.875rem">${m.code_http}</span></span>
                </div>
            ` : '';

            const statutBadge = r.echecs > 0
                ? `<span class="badge bg-danger">${r.echecs} echec(s)</span>`
                : `<span class="badge bg-success">${r.succes} succes</span>`;

            // Resultats detailles pour cette URL
            const resultatsUrl = details.filter(d => d.url_id === r.url_id);
            const resultatsHtml = resultatsUrl.map(d => {
                const badge = d.succes
                    ? '<i class="bi bi-check-circle text-success me-1"></i>'
                    : '<i class="bi bi-x-circle text-danger me-1"></i>';
                return `
                    <div class="border-bottom py-1 px-2" style="font-size:0.8125rem">
                        ${badge}
                        <span class="${d.succes ? '' : 'text-danger'}">${echapper(d.message || '')}</span>
                        ${d.valeur_attendue ? `<span class="text-muted ms-2">Attendu: ${echapper(d.valeur_attendue)}</span>` : ''}
                        ${d.valeur_obtenue ? `<span class="text-muted ms-2">Obtenu: ${echapper(d.valeur_obtenue)}</span>` : ''}
                        ${renderDetailsJson(d.details_json)}
                    </div>
                `;
            }).join('');

            html += `
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <div>
                            <span class="fw-semibold" style="font-size:0.875rem">${echapper(r.libelle || r.url)}</span>
                            <div class="text-muted" style="font-size:0.75rem">${echapper(r.url)}</div>
                        </div>
                        ${statutBadge}
                    </div>
                    <div class="card-body p-2">
                        ${metriquesHtml}
                        ${resultatsHtml}
                    </div>
                </div>
            `;
        });

        // Afficher dans une modale generique
        afficherModaleResultats(html);

    } catch (e) {
        console.error('voirResultatsExecution:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

function formaterOctets(octets) {
    if (octets < 1024) return octets + ' o';
    if (octets < 1048576) return (octets / 1024).toFixed(1) + ' Ko';
    return (octets / 1048576).toFixed(1) + ' Mo';
}

function afficherModaleResultats(contenuHtml) {
    // Reutiliser ou creer la modale des resultats
    let modale = document.getElementById('modalResultats');
    if (!modale) {
        modale = document.createElement('div');
        modale.id = 'modalResultats';
        modale.className = 'modal fade';
        modale.setAttribute('tabindex', '-1');
        modale.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>${t('resultat.titre', 'Resultats')}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="corpsModaleResultats"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">${t('action.fermer', 'Fermer')}</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modale);
    }

    document.getElementById('corpsModaleResultats').innerHTML = contenuHtml;
    bootstrap.Modal.getOrCreateInstance(modale).show();
}

// ---------------------------------------------------------------------------
// Lancer une verification
// ---------------------------------------------------------------------------

function ouvrirLancerVerification(clientId) {
    document.getElementById('verifClientId').value = clientId || '';
    if (clientId) {
        chargerGroupesVerification(clientId);
    }
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLancerVerification'));
    modal.show();
}

async function chargerGroupesVerification(clientId) {
    const select = document.getElementById('verifGroupeId');
    select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());

    if (!clientId) return;

    try {
        const res = await apiGet({ entite: 'groupe', action: 'lister', client_id: clientId });
        const groupes = res.donnees || [];
        groupes.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g.id;
            opt.textContent = g.nom;
            select.appendChild(opt);
        });
    } catch (e) {
        console.error('chargerGroupesVerification:', e);
    }
}

async function lancerVerification(e) {
    e.preventDefault();
    collapserHelpPanel();

    const clientId = document.getElementById('verifClientId').value;
    if (!clientId) {
        afficherToast(t('verification.client', 'Selectionnez un client'), 'warning');
        return;
    }

    const donnees = {
        entite: 'execution',
        action: 'lancer',
        client_id: clientId,
        groupe_id: document.getElementById('verifGroupeId').value || '',
        user_agent: document.getElementById('verifUserAgent').value,
        timeout: document.getElementById('verifTimeout').value,
        delai_ms: document.getElementById('verifDelai').value,
    };

    // Si user agent custom
    if (donnees.user_agent === 'custom') {
        const custom = document.getElementById('verifUserAgentCustom')?.value;
        if (custom) donnees.user_agent = custom;
    }

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }

        const jobId = res.donnees?.job_id;
        afficherToast(res.message || t('execution.en_cours'), 'success');

        bootstrap.Modal.getInstance(document.getElementById('modalLancerVerification'))?.hide();

        if (jobId) {
            demarrerPolling(jobId);
        }

    } catch (e) {
        console.error('lancerVerification:', e);
        afficherToast(t('message.erreur'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Progression (polling)
// ---------------------------------------------------------------------------

let pollingInterval = null;

function demarrerPolling(jobId) {
    const section = document.getElementById('progressSection');
    const bar = document.getElementById('progressBar');
    const status = document.getElementById('progressStatus');
    const logs = document.getElementById('progressLogs');

    section.style.display = '';
    bar.style.width = '0%';
    bar.textContent = '0%';
    status.textContent = t('statut.en_attente', 'En attente...');
    logs.style.display = '';
    logs.innerHTML = '';
    _lastLogStep = '';

    // Arreter un polling precedent
    if (pollingInterval) clearInterval(pollingInterval);

    pollingInterval = setInterval(async () => {
        try {
            const response = await fetch(baseUrl + '/progress.php?job=' + encodeURIComponent(jobId));
            if (!response.ok) {
                ajouterLog('Erreur HTTP ' + response.status, 'danger');
                arreterPolling(t('message.erreur', 'Erreur'));
                return;
            }

            const data = await response.json();
            const pct = data.percent || 0;
            const step = data.step || data.status || '';

            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            status.textContent = step;

            // Ajouter au log si le message a change
            if (step && step !== _lastLogStep) {
                const couleur = data.status === 'error' ? 'text-danger' : (pct >= 95 ? 'text-success' : 'text-info');
                ajouterLog('[' + pct + '%] ' + step, couleur);
                _lastLogStep = step;
            }

            // Afficher le worker_log si present (erreurs PHP du worker)
            if (data.worker_log && !logs.dataset.workerLogShown) {
                logs.dataset.workerLogShown = '1';
                ajouterLog('--- Worker error log ---', 'text-warning');
                data.worker_log.split('\n').slice(-10).forEach(l => {
                    if (l.trim()) ajouterLog(l.trim(), 'text-danger');
                });
            }

            if (data.status === 'done' || data.status === 'completed' || data.status === 'error' || pct >= 100) {
                if (data.status === 'error') {
                    ajouterLog('ERREUR : ' + (data.step || 'Erreur inconnue'), 'text-danger');
                    afficherToast(data.step || t('message.erreur'), 'danger');
                } else {
                    const r = data.resume || data.result || {};
                    ajouterLog('Termine — ' + (r.urls_traitees || '?') + ' URLs, ' + (r.succes || 0) + ' succes, ' + (r.echecs || 0) + ' echecs (' + (r.duree_ms || 0) + 'ms)', 'text-success');
                    afficherToast(t('message.succes', 'Verification terminee'), 'success');
                }

                arreterPolling(data.step || data.status);

                // Rafraichir les donnees
                chargerDashboard();
                chargerExecutions();

                // Auto-collapse config
                var configBody = document.getElementById('configBody');
                if (configBody) { bootstrap.Collapse.getOrCreateInstance(configBody, {toggle:false}).hide(); }
            }

        } catch (e) {
            console.error('polling:', e);
            ajouterLog('Erreur reseau : ' + e.message, 'text-danger');
            arreterPolling(t('message.erreur_reseau'));
        }
    }, 2000);
}

let _lastLogStep = '';
function ajouterLog(message, couleurClass) {
    const logs = document.getElementById('progressLogs');
    if (!logs) return;
    const horodatage = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const ligne = document.createElement('div');
    ligne.className = couleurClass || '';
    ligne.textContent = horodatage + '  ' + message;
    logs.appendChild(ligne);
    logs.scrollTop = logs.scrollHeight;
}

function arreterPolling(messageFinal) {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }
    const bar = document.getElementById('progressBar');
    const status = document.getElementById('progressStatus');
    bar.classList.remove('progress-bar-animated');
    if (messageFinal) status.textContent = messageFinal;

    // Masquer apres 30 secondes (laisser le temps de lire les logs)
    setTimeout(() => {
        document.getElementById('progressSection').style.display = 'none';
        document.getElementById('progressLogs').innerHTML = '';
        bar.classList.add('progress-bar-animated');
        bar.style.width = '0%';
        bar.textContent = '0%';
    }, 30000);
}

// ---------------------------------------------------------------------------
// Filtres detail URLs (recherche et groupe)
// ---------------------------------------------------------------------------

function filtrerUrlsDetail() {
    const recherche = (document.getElementById('rechercheUrlsDetail')?.value || '').toLowerCase();
    const groupeFiltre = document.getElementById('filtreGroupeUrlsDetail')?.value || '';
    const urls = window._detailUrls || [];

    const filtrees = urls.filter(u => {
        const matchRecherche = !recherche
            || (u.url || '').toLowerCase().includes(recherche)
            || (u.libelle || '').toLowerCase().includes(recherche);
        const matchGroupe = !groupeFiltre || String(u.groupe_id) === groupeFiltre;
        return matchRecherche && matchGroupe;
    });

    const tbody = document.getElementById('bodyUrlsDetail');
    if (filtrees.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">${t('url.aucune')}</td></tr>`;
        return;
    }

    let html = '';
    filtrees.forEach(u => {
        const statutBadge = u.dernier_statut
            ? `<span class="badge ${u.dernier_statut === 'succes' ? 'bg-success' : 'bg-danger'}">${echapper(u.dernier_statut)}</span>`
            : '<span class="badge bg-secondary">\u2014</span>';
        const urlAffichee = u.url.length > 60 ? u.url.substring(0, 57) + '...' : u.url;
        html += `
            <tr>
                <td><a href="${echapper(u.url)}" target="_blank" rel="noopener" class="text-decoration-none small">${echapper(urlAffichee)}</a></td>
                <td class="small">${echapper(u.libelle || '')}</td>
                <td class="small">${echapper(u.nom_groupe || '')}</td>
                <td>${statutBadge}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirEditionUrl(${u.id})"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="supprimerUrl(${u.id})"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// ---------------------------------------------------------------------------
// Utilitaires
// ---------------------------------------------------------------------------

function formaterDuree(secondes) {
    if (!secondes || secondes <= 0) return '\u2014';
    if (secondes < 60) return secondes + 's';
    const min = Math.floor(secondes / 60);
    const sec = Math.round(secondes % 60);
    return min + 'min ' + sec + 's';
}

// ---------------------------------------------------------------------------
// Tri des tableaux (colonnes sortable)
// ---------------------------------------------------------------------------

let triActuel = { colonne: null, asc: true };

function configurerTri() {
    document.querySelectorAll('.sortable').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const colonne = th.getAttribute('data-sort');
            if (triActuel.colonne === colonne) {
                triActuel.asc = !triActuel.asc;
            } else {
                triActuel.colonne = colonne;
                triActuel.asc = true;
            }

            const table = th.closest('table');
            const tbody = table.querySelector('tbody');
            const lignes = Array.from(tbody.querySelectorAll('tr')).filter(
                tr => !tr.id || !tr.id.includes('Vide')
            );

            const idx = Array.from(th.parentElement.children).indexOf(th);

            lignes.sort((a, b) => {
                const cellA = a.cells[idx]?.textContent?.trim() || '';
                const cellB = b.cells[idx]?.textContent?.trim() || '';
                const numA = parseFloat(cellA.replace(/[^0-9.-]/g, ''));
                const numB = parseFloat(cellB.replace(/[^0-9.-]/g, ''));

                if (!isNaN(numA) && !isNaN(numB)) {
                    return triActuel.asc ? numA - numB : numB - numA;
                }
                return triActuel.asc
                    ? cellA.localeCompare(cellB, 'fr')
                    : cellB.localeCompare(cellA, 'fr');
            });

            lignes.forEach(tr => tbody.appendChild(tr));

            // Indicateur visuel
            table.querySelectorAll('.sortable').forEach(s => {
                s.classList.remove('tri-asc', 'tri-desc');
            });
            th.classList.add(triActuel.asc ? 'tri-asc' : 'tri-desc');
        });
    });
}

// ---------------------------------------------------------------------------
// Installation automatique de la base de donnees
// ---------------------------------------------------------------------------

async function installerBaseDeDonnees() {
    try {
        const fd = new FormData();
        fd.append('action', 'migrer');
        const csrfToken = getCsrfToken();
        if (csrfToken) fd.append('_csrf_token', csrfToken);

        const response = await fetch(baseUrl + '/installer.php', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.error) {
            console.warn('Migration:', data.error);
        }
    } catch (e) {
        console.warn('Installation BD:', e.message);
    }
}

// ---------------------------------------------------------------------------
// Initialisation
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', async () => {

    // i18n : selecteur de langue
    document.querySelectorAll('#langSelector .btn').forEach(btn => {
        btn.addEventListener('click', () => changerLangue(btn.getAttribute('data-lang')));
    });

    // Langue initiale
    const langues = window.MODULE_LANGUAGES || [];
    if (langues.length > 0 && !langues.includes(langueActuelle)) {
        langueActuelle = langues[0];
    }
    traduirePage();

    // Installation automatique de la BD
    await installerBaseDeDonnees();

    // Initialiser les formulaires dynamiques de regles
    if (typeof window.initReglesConfig === 'function') {
        window.initReglesConfig();
    }

    // Charger le dashboard
    await chargerDashboard();

    // --- Formulaires ---

    // Client
    document.getElementById('formClient').addEventListener('submit', sauvegarderClient);
    document.getElementById('modalClient').addEventListener('show.bs.modal', () => {
        // Reinitialiser si c'est un ajout (pas ouvert via ouvrirEditionClient)
        if (!document.getElementById('clientId').value) {
            reinitialiserFormClient();
        }
    });
    document.getElementById('modalClient').addEventListener('hidden.bs.modal', () => {
        reinitialiserFormClient();
    });

    // Groupe
    document.getElementById('formGroupe').addEventListener('submit', sauvegarderGroupe);
    document.getElementById('modalGroupe').addEventListener('hidden.bs.modal', () => {
        reinitialiserFormGroupe();
    });

    // URL
    document.getElementById('formUrl').addEventListener('submit', sauvegarderUrl);
    document.getElementById('modalUrl').addEventListener('hidden.bs.modal', () => {
        reinitialiserFormUrl();
    });

    // Toggle mode simple/multiple URLs
    document.getElementById('toggleUrlMode').addEventListener('click', (e) => {
        e.preventDefault();
        const toggle = e.currentTarget;
        const simple = document.getElementById('blocUrlSimple');
        const multiple = document.getElementById('blocUrlMultiple');
        if (toggle.dataset.mode === 'simple') {
            toggle.dataset.mode = 'multiple';
            toggle.querySelector('span').textContent = t('modal.url.ajoutSimple', 'Ajout simple');
            toggle.querySelector('i').className = 'bi bi-pencil me-1';
            simple.style.display = 'none';
            multiple.style.display = '';
        } else {
            toggle.dataset.mode = 'simple';
            toggle.querySelector('span').textContent = t('modal.url.ajoutMultiple', 'Ajouter plusieurs URLs');
            toggle.querySelector('i').className = 'bi bi-list-ul me-1';
            simple.style.display = '';
            multiple.style.display = 'none';
        }
    });

    // Compteur d'URLs dans le textarea
    document.getElementById('urlsTextarea').addEventListener('input', (e) => {
        const lignes = e.target.value.split('\n').filter(l => l.trim() !== '');
        document.getElementById('urlsCompteur').textContent = lignes.length;
    });

    // Modele
    document.getElementById('formModele').addEventListener('submit', sauvegarderModele);
    document.getElementById('modalModele').addEventListener('show.bs.modal', () => {
        if (!document.getElementById('modeleId').value) {
            reinitialiserFormModele();
        }
    });
    document.getElementById('modalModele').addEventListener('hidden.bs.modal', () => {
        reinitialiserFormModele();
    });

    // Regle
    document.getElementById('formRegle').addEventListener('submit', sauvegarderRegle);
    document.getElementById('modalRegle').addEventListener('hidden.bs.modal', () => {
        reinitialiserFormRegle();
    });

    // Lancer verification
    document.getElementById('formLancerVerification').addEventListener('submit', lancerVerification);
    document.getElementById('modalLancerVerification').addEventListener('hidden.bs.modal', () => {
        document.getElementById('formLancerVerification').reset();
    });

    // Confirmation de suppression
    document.getElementById('btnConfirmerSuppression').addEventListener('click', () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('modalConfirmation'));
        modal?.hide();
        if (confirmationCallback) {
            confirmationCallback();
            confirmationCallback = null;
        }
    });

    // --- Onglets principaux ---

    document.getElementById('tab-modeles').addEventListener('shown.bs.tab', () => {
        chargerModeles();
    });

    document.getElementById('tab-executions').addEventListener('shown.bs.tab', () => {
        chargerExecutions();
    });

    // --- Filtres executions ---

    document.getElementById('filtreExecutionStatut').addEventListener('change', () => {
        chargerExecutions();
    });
    document.getElementById('filtreExecutionClient').addEventListener('change', () => {
        chargerExecutions();
    });

    // --- Detail client : boutons ---

    document.getElementById('btnAjouterGroupeDetail').addEventListener('click', () => {
        if (detailClientActuelId) {
            ouvrirAjoutGroupe(detailClientActuelId);
        }
    });

    document.getElementById('btnAjouterUrlDetail').addEventListener('click', () => {
        if (detailClientActuelId) {
            ouvrirAjoutUrlPourClient(detailClientActuelId);
        }
    });

    document.getElementById('btnEditerClientDetail').addEventListener('click', () => {
        if (detailClientActuelId) {
            bootstrap.Modal.getInstance(document.getElementById('modalDetailClient'))?.hide();
            setTimeout(() => ouvrirEditionClient(detailClientActuelId), 300);
        }
    });

    document.getElementById('btnLancerVerifDetail').addEventListener('click', () => {
        if (detailClientActuelId) {
            bootstrap.Modal.getInstance(document.getElementById('modalDetailClient'))?.hide();
            setTimeout(() => ouvrirLancerVerification(detailClientActuelId), 300);
        }
    });

    // --- Detail client : filtres URLs ---

    const rechercheUrls = document.getElementById('rechercheUrlsDetail');
    if (rechercheUrls) {
        rechercheUrls.addEventListener('input', filtrerUrlsDetail);
    }
    const filtreGroupeUrls = document.getElementById('filtreGroupeUrlsDetail');
    if (filtreGroupeUrls) {
        filtreGroupeUrls.addEventListener('change', filtrerUrlsDetail);
    }

    // --- Verification : user-agent custom ---

    document.getElementById('verifUserAgent').addEventListener('change', (e) => {
        const wrapper = document.getElementById('verifUserAgentCustomWrapper');
        wrapper.style.display = e.target.value === 'custom' ? '' : 'none';
    });

    // --- Verification : charger groupes quand le client change ---

    document.getElementById('verifClientId').addEventListener('change', (e) => {
        chargerGroupesVerification(e.target.value);
    });

    // --- Annuler verification en cours ---

    document.getElementById('btnAnnulerVerification').addEventListener('click', () => {
        arreterPolling(t('statut.annule', 'Annule'));
        afficherToast(t('statut.annule', 'Verification annulee'), 'warning');
    });

    // --- Tri des colonnes ---

    configurerTri();

    // --- Onglet Alertes ---

    document.getElementById('tab-alertes')?.addEventListener('shown.bs.tab', () => {
        chargerAlertes();
    });

    // --- Bouton voir toutes les alertes ---

    document.getElementById('btnVoirToutesAlertes')?.addEventListener('click', () => {
        const tabAlertes = document.getElementById('tab-alertes');
        if (tabAlertes) {
            bootstrap.Tab.getOrCreateInstance(tabAlertes).show();
        }
    });

    // --- Dashboard avance : tri sante clients ---

    document.getElementById('triSanteClients')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-tri]');
        if (!btn) return;
        document.querySelectorAll('#triSanteClients .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        trierCartesClients(btn.dataset.tri);
    });

    // --- Feed "Quoi de neuf ?" : onglets ---
    document.getElementById('filtreChangementsFeed')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-filtre]');
        if (!btn) return;
        document.querySelectorAll('#filtreChangementsFeed .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderChangementsFeed(btn.dataset.filtre);
    });

    // --- Setup rapide ---
    document.getElementById('btnSetupAjouterGroupe')?.addEventListener('click', () => ajouterBlocGroupe());

    // --- Import sitemap ---
    document.getElementById('btnSetupImportSitemap')?.addEventListener('click', () => {
        const clientId = document.getElementById('setupClientId').value || document.getElementById('setupSelectClient').value;
        if (clientId) ouvrirImportSitemap(clientId);
        else afficherToast(t('setup.selectClient', 'Selectionnez un client'), 'warning');
    });
    document.getElementById('btnSitemapImporter')?.addEventListener('click', () => importerSitemapSelection());
    document.getElementById('sitemapFiltre')?.addEventListener('input', (e) => renderSitemapUrls(e.target.value));
    document.getElementById('btnSitemapToutCocher')?.addEventListener('click', () => {
        document.querySelectorAll('#sitemapListeUrls .sitemap-url-cb').forEach(cb => { cb.checked = true; });
        majCompteurSitemap();
    });
    document.getElementById('btnSitemapToutDecocher')?.addEventListener('click', () => {
        document.querySelectorAll('#sitemapListeUrls .sitemap-url-cb').forEach(cb => { cb.checked = false; });
        majCompteurSitemap();
    });
    document.getElementById('sitemapListeUrls')?.addEventListener('change', () => majCompteurSitemap());
    document.getElementById('sitemapGroupes')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.sitemap-groupe-btn');
        if (!btn) return;
        const nom = btn.dataset.groupe;
        document.getElementById('sitemapFiltre').value = '/' + nom;
        renderSitemapUrls('/' + nom);
    });

    // --- Planifications ---
    document.getElementById('btnCreerPlanif')?.addEventListener('click', () => creerPlanification());
    document.getElementById('planifActifToggle')?.addEventListener('change', (e) => togglePlanification(e.target.checked));
    document.getElementById('btnSupprimerPlanif')?.addEventListener('click', () => supprimerPlanification());
    document.getElementById('btnSetupEnregistrer')?.addEventListener('click', () => sauvegarderSetupRapide());
    document.getElementById('modalSetupRapide')?.addEventListener('show.bs.modal', () => {
        // Si pas pre-rempli par ouvrirSetupRapide(), ouvrir avec le select client
        if (!document.getElementById('setupClientId').value) {
            ouvrirSetupRapide(null, null);
        }
    });

    // --- Dashboard avance : filtre tendances par client ---

    document.getElementById('filtreTendancesClient')?.addEventListener('change', (e) => {
        chargerTendances(e.target.value || null);
    });

    // --- Dashboard avance : onglets tendances ---

    document.getElementById('tabsTendances')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-graphique]');
        if (!btn) return;
        document.querySelectorAll('#tabsTendances .nav-link').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        graphiqueActuel = btn.dataset.graphique;
        afficherGraphiqueTendances(graphiqueActuel);
    });
});

// ============================================================
// DASHBOARD AVANCE — UTILITAIRES
// ============================================================

/**
 * Temps relatif : "il y a 2h", "il y a 3j", etc.
 */
function tempsRelatif(dateStr) {
    if (!dateStr) return t('dashboard.jamais_verifie', 'Jamais verifie');
    const maintenant = Date.now();
    const date = new Date(dateStr).getTime();
    const diff = maintenant - date;
    const minutes = Math.floor(diff / 60000);
    const heures = Math.floor(diff / 3600000);
    const jours = Math.floor(diff / 86400000);

    if (minutes < 1) return 'a l\'instant';
    if (minutes < 60) return `${minutes}min`;
    if (heures < 24) return `${heures}h`;
    if (jours < 30) return `${jours}j`;
    return `${Math.floor(jours / 30)}mois`;
}

/**
 * Dessine un sparkline sur un canvas 2D.
 */
function dessinerSparkline(canvas, donnees, couleur = '#66b2b2') {
    if (!canvas || !donnees || donnees.length < 2) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.offsetWidth * 2;
    const h = canvas.height = 60;
    ctx.clearRect(0, 0, w, h);

    const min = Math.min(...donnees);
    const max = Math.max(...donnees);
    const range = max - min || 1;
    const padding = 4;

    ctx.beginPath();
    ctx.strokeStyle = couleur;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';

    donnees.forEach((val, i) => {
        const x = padding + (i / (donnees.length - 1)) * (w - padding * 2);
        const y = h - padding - ((val - min) / range) * (h - padding * 2);
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.stroke();

    // Remplissage degrade
    const dernier = donnees[donnees.length - 1];
    const yDernier = h - padding - ((dernier - min) / range) * (h - padding * 2);
    ctx.lineTo(w - padding, h);
    ctx.lineTo(padding, h);
    ctx.closePath();
    const gradient = ctx.createLinearGradient(0, 0, 0, h);
    gradient.addColorStop(0, couleur + '30');
    gradient.addColorStop(1, couleur + '05');
    ctx.fillStyle = gradient;
    ctx.fill();
}

/**
 * Classe CSS du badge score.
 */
function classeScore(taux) {
    if (taux === null || taux === undefined) return 'score-inconnu';
    if (taux >= 80) return 'score-bon';
    if (taux >= 50) return 'score-moyen';
    return 'score-mauvais';
}

// ============================================================
// DASHBOARD AVANCE — LOADERS
// ============================================================

/**
 * Remplir le select de filtre tendances.
 */
function remplirFiltreTendancesClient(clients) {
    const select = document.getElementById('filtreTendancesClient');
    if (!select) return;
    // Garder l'option "Tous les clients"
    const premierOption = select.options[0];
    select.innerHTML = '';
    select.appendChild(premierOption);
    clients.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id ?? c.client_id;
        opt.textContent = c.nom;
        select.appendChild(opt);
    });
}

/**
 * Charge les stats par client et affiche les cartes sante.
 */
async function chargerSanteParClient() {
    try {
        const res = await apiGet({ entite: 'dashboard', action: 'stats_par_client' });
        if (res.erreur) return;

        const clients = res.donnees || [];
        const grille = document.getElementById('grilleSanteClients');
        const vide = document.getElementById('santeClientsVide');

        if (clients.length === 0) {
            vide.innerHTML = `<i class="bi bi-inbox"></i> ${t('dashboard.aucun_client', 'Aucun client')}`;
            return;
        }
        if (vide) vide.remove();

        grille.innerHTML = clients.map(c => {
            const taux = c.taux_reussite_dernier;
            const scoreText = taux !== null && taux !== undefined ? Math.round(taux) : '--';
            const scoreClasse = classeScore(taux);
            const alertes = parseInt(c.alertes_non_lues) || 0;
            const ttfb = c.ttfb_moyen ? Math.round(c.ttfb_moyen) + 'ms' : '--';
            const derniere = tempsRelatif(c.derniere_execution);
            const clientId = c.client_id;

            return `
                <div class="col-md-6 col-xl-4 carte-sante-wrapper"
                     data-score="${taux ?? -1}" data-alertes="${alertes}" data-date="${c.derniere_execution || ''}">
                    <div class="carte-sante-client">
                        <div class="carte-header">
                            <div>
                                <div class="carte-nom">${echapper(c.nom)}</div>
                                <div class="carte-domaine">${echapper(c.domaine || '')}</div>
                            </div>
                            <div class="badge-score-sante ${scoreClasse}">${scoreText}</div>
                        </div>
                        <canvas class="sparkline-canvas" data-sparkline='${JSON.stringify(c.sparkline_data || [])}'></canvas>
                        <div class="stats-mini">
                            <div class="stat-item"><i class="bi bi-link-45deg"></i>${c.nb_urls ?? 0} URLs</div>
                            <div class="stat-item"><i class="bi bi-bell"></i>${alertes} ${t('alerte.non_lues', 'alertes')}</div>
                            <div class="stat-item"><i class="bi bi-speedometer2"></i>${ttfb}</div>
                            <div class="stat-item"><i class="bi bi-clock"></i>${derniere}</div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="ouvrirDetailClient(${clientId})">
                                <i class="bi bi-eye me-1"></i>${t('client.voir', 'Voir')}
                            </button>
                            <button class="btn btn-outline-success btn-sm flex-fill" onclick="ouvrirLancerVerification(${clientId})">
                                <i class="bi bi-play-fill me-1"></i>${t('client.lancer', 'Lancer')}
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Dessiner les sparklines
        grille.querySelectorAll('.sparkline-canvas').forEach(canvas => {
            const data = JSON.parse(canvas.getAttribute('data-sparkline') || '[]');
            if (data.length >= 2) {
                dessinerSparkline(canvas, data);
            }
        });

    } catch (e) {
        console.error('chargerSanteParClient:', e);
        const vide = document.getElementById('santeClientsVide');
        if (vide) vide.innerHTML = `<i class="bi bi-exclamation-triangle text-warning"></i> ${e.message || t('message.erreur')}`;
    }
}

/**
 * Trier les cartes sante par critere.
 */
function trierCartesClients(critere) {
    const grille = document.getElementById('grilleSanteClients');
    const cartes = [...grille.querySelectorAll('.carte-sante-wrapper')];

    cartes.sort((a, b) => {
        switch (critere) {
            case 'score':
                return (parseFloat(b.dataset.score) || -1) - (parseFloat(a.dataset.score) || -1);
            case 'alertes':
                return (parseInt(b.dataset.alertes) || 0) - (parseInt(a.dataset.alertes) || 0);
            case 'date':
                return (b.dataset.date || '').localeCompare(a.dataset.date || '');
            default:
                return 0;
        }
    });

    cartes.forEach(c => grille.appendChild(c));
}

/**
 * Charge les URLs a risque.
 */
async function chargerUrlsARisque() {
    try {
        const res = await apiGet({ entite: 'dashboard', action: 'urls_a_risque' });
        if (res.erreur) return;

        const urls = res.donnees || [];
        const card = document.getElementById('cardUrlsARisque');
        const tbody = document.getElementById('bodyUrlsRisque');

        if (urls.length === 0) {
            card.style.display = 'none';
            return;
        }

        card.style.display = '';
        tbody.innerHTML = urls.map(u => {
            const severite = u.severite_max || 'erreur';
            const messages = (u.messages || '').split(',').slice(0, 2).join(', ');
            const urlTronquee = (u.url || '').length > 60 ? u.url.substring(0, 60) + '...' : u.url;
            return `
                <tr>
                    <td title="${echapper(u.url)}">
                        <span class="fw-semibold">${echapper(u.libelle || urlTronquee)}</span>
                        <br><small class="text-muted">${echapper(urlTronquee)}</small>
                    </td>
                    <td>${echapper(u.client_nom || '')}</td>
                    <td><span class="badge-severite severite-${severite}">${severite}</span></td>
                    <td class="fw-bold">${u.nb_echecs}</td>
                    <td><small class="text-muted">${echapper(messages).substring(0, 100)}</small></td>
                </tr>
            `;
        }).join('');

    } catch (e) {
        console.error('chargerUrlsARisque:', e);
    }
}

/**
 * Charge les changements de contenu recents.
 */
// ---------------------------------------------------------------------------
// Feed "Quoi de neuf ?" — comparaison entre 2 dernieres executions
// ---------------------------------------------------------------------------

let cacheChangementsFeed = null;

async function chargerChangementsFeed() {
    try {
        const res = await apiGet({ entite: 'dashboard', action: 'changements_feed' });
        if (res.erreur) {
            console.warn('changements_feed:', res.erreur);
            return;
        }

        cacheChangementsFeed = res.donnees;
        const r = res.donnees.resume || {};

        document.getElementById('countNouvelles').textContent = r.nb_nouvelles || 0;
        document.getElementById('countRecuperations').textContent = r.nb_recuperations || 0;
        document.getElementById('countPersistantes').textContent = r.nb_persistantes || 0;
        document.getElementById('badgeNbChangements').textContent = (r.nb_nouvelles || 0) + (r.nb_recuperations || 0);

        renderChangementsFeed('nouvelles');
    } catch (e) {
        console.error('chargerChangementsFeed:', e);
    }
}

function renderChangementsFeed(filtre) {
    if (!cacheChangementsFeed) return;

    const cle = filtre === 'nouvelles' ? 'nouvelles_defaillances'
        : filtre === 'recuperations' ? 'recuperations'
        : 'defaillances_persistantes';

    const items = cacheChangementsFeed[cle] || [];
    const corps = document.getElementById('corpsChangementsFeed');
    const feedVide = document.getElementById('feedVide');

    if (items.length === 0) {
        corps.innerHTML = '';
        feedVide.style.display = '';
        return;
    }
    feedVide.style.display = 'none';

    // Grouper par client
    const parClient = {};
    items.forEach(item => {
        const key = item.client_nom || 'Inconnu';
        if (!parClient[key]) parClient[key] = [];
        parClient[key].push(item);
    });

    const icones = {
        nouvelles: 'bi-exclamation-triangle',
        recuperations: 'bi-check-circle',
        persistantes: 'bi-arrow-repeat',
    };
    const icone = icones[filtre] || 'bi-arrow-left-right';

    let html = '';
    for (const [clientNom, clientItems] of Object.entries(parClient)) {
        html += `<div class="feed-groupe-client"><i class="bi bi-building me-1"></i>${echapper(clientNom)}</div>`;
        clientItems.forEach(item => {
            const urlAffichee = item.url_libelle || (item.url?.length > 60 ? item.url.substring(0, 60) + '...' : item.url);
            const meta = [
                item.regle_type || item.regle_nom,
                (item.message || '').substring(0, 100),
            ].filter(Boolean).join(' \u00b7 ');

            html += `
                <div class="feed-item feed-${filtre}">
                    <div class="feed-icone feed-icone-${filtre}"><i class="bi ${icone}"></i></div>
                    <div class="feed-contenu">
                        <div class="feed-url" title="${echapper(item.url || '')}">${echapper(urlAffichee)}</div>
                        <div class="feed-meta">${echapper(meta)}</div>
                    </div>
                    <div class="feed-badges">
                        <span class="badge-severite severite-${item.severite || 'erreur'}">${t('severite.' + (item.severite || 'erreur'))}</span>
                    </div>
                </div>`;
        });
    }

    corps.innerHTML = html;
}

/**
 * Charge et affiche les tendances Chart.js.
 */
let chartTendancesInstance = null;
let donneesTendancesCache = null;
let graphiqueActuel = 'taux';

async function chargerTendances(clientId = null) {
    try {
        const params = { entite: 'dashboard', action: 'tendances' };
        if (clientId) params.client_id = clientId;

        const res = await apiGet(params);
        if (res.erreur) return;

        donneesTendancesCache = res.donnees;
        afficherGraphiqueTendances(graphiqueActuel);

    } catch (e) {
        console.error('chargerTendances:', e);
    }
}

function afficherGraphiqueTendances(type) {
    if (!donneesTendancesCache) return;
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js non charge');
        return;
    }

    const canvas = document.getElementById('canvasTendances');
    if (!canvas) return;

    if (chartTendancesInstance) {
        chartTendancesInstance.destroy();
        chartTendancesInstance = null;
    }

    const execJour = donneesTendancesCache.executions_par_jour || [];
    const metJour = donneesTendancesCache.metriques_par_jour || [];

    let config;

    switch (type) {
        case 'taux': {
            const labels = execJour.map(e => e.jour);
            const data = execJour.map(e => e.taux_moyen !== null ? parseFloat(e.taux_moyen).toFixed(1) : null);
            config = {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: t('dashboard.taux_reussite', 'Taux de reussite (%)'),
                        data,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } },
                    plugins: { legend: { display: false } },
                },
            };
            break;
        }
        case 'executions': {
            const labels = execJour.map(e => e.jour);
            const data = execJour.map(e => parseInt(e.nb_executions) || 0);
            config = {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: t('dashboard.executions_jour', 'Executions / jour'),
                        data,
                        backgroundColor: 'rgba(102,178,178,0.6)',
                        borderColor: '#66b2b2',
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { display: false } },
                },
            };
            break;
        }
        case 'ttfb': {
            const labels = metJour.map(m => m.jour);
            const dataTtfb = metJour.map(m => m.ttfb_moyen !== null ? parseInt(m.ttfb_moyen) : null);
            const dataTotal = metJour.map(m => m.temps_total_moyen !== null ? parseInt(m.temps_total_moyen) : null);
            config = {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'TTFB (ms)',
                            data: dataTtfb,
                            borderColor: '#fbb03b',
                            backgroundColor: 'rgba(251,176,59,0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                        },
                        {
                            label: t('metrique.temps_total', 'Temps total (ms)'),
                            data: dataTotal,
                            borderColor: '#004c4c',
                            backgroundColor: 'rgba(0,76,76,0.05)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { callback: v => v + 'ms' } } },
                },
            };
            break;
        }
    }

    if (config) {
        chartTendancesInstance = new Chart(canvas, config);
    }
}

// ============================================================
// ALERTES
// ============================================================

function renderAlertesRecentes(alertes) {
    const card = document.getElementById('cardAlertesRecentes');
    const conteneur = document.getElementById('listeAlertesRecentes');

    if (!alertes || alertes.length === 0) {
        card.style.display = 'none';
        return;
    }

    card.style.display = '';
    conteneur.innerHTML = alertes.map(a => renderAlerteCard(a)).join('');
}

function renderAlerteCard(a) {
    const severiteClasse = `severite-${a.severite}`;
    const date = a.cree_le ? new Date(a.cree_le).toLocaleString() : '';
    return `
        <div class="alerte-card ${severiteClasse}">
            <div class="d-flex justify-content-between align-items-start">
                <div class="alerte-sujet">${echapper(a.sujet)}</div>
                <div class="alerte-date">${date}</div>
            </div>
            <div class="alerte-corps mt-1">${echapper(a.corps_texte).substring(0, 300)}${a.corps_texte.length > 300 ? '...' : ''}</div>
        </div>
    `;
}

function mettreAJourBadgeAlertes(nombre) {
    const badge = document.getElementById('badgeAlertes');
    if (!badge) return;
    if (nombre > 0) {
        badge.textContent = nombre;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

async function chargerAlertes() {
    try {
        const res = await apiGet({ entite: 'alerte', action: 'lister', limite: 50 });
        if (res.erreur) return;

        const conteneur = document.getElementById('listeAlertes');
        const alertes = res.donnees || [];

        if (alertes.length === 0) {
            conteneur.innerHTML = `<p class="text-center text-muted py-4">${t('alerte.aucune')}</p>`;
            return;
        }

        conteneur.innerHTML = alertes.map(a => renderAlerteCard(a)).join('');
    } catch (e) {
        console.error('chargerAlertes:', e);
    }
}

// ============================================================
// DETAILS ENRICHIS DES RESULTATS
// ============================================================

function renderDetailsJson(detailsJson) {
    if (!detailsJson) return '';

    let details;
    try {
        details = typeof detailsJson === 'string' ? JSON.parse(detailsJson) : detailsJson;
    } catch {
        return '';
    }

    if (!details || Object.keys(details).length === 0) return '';

    // Filtrer les cles internes (prefixees par _) et les contenus trop longs
    const detailsFiltres = {};
    for (const [cle, valeur] of Object.entries(details)) {
        if (cle.startsWith('_')) continue;
        if (typeof valeur === 'string' && valeur.length > 500) {
            detailsFiltres[cle] = valeur.substring(0, 500) + '...';
        } else {
            detailsFiltres[cle] = valeur;
        }
    }

    if (Object.keys(detailsFiltres).length === 0) return '';

    const id = 'details-' + Math.random().toString(36).substring(2, 9);
    return `
        <button class="details-toggle" onclick="document.getElementById('${id}').style.display = document.getElementById('${id}').style.display === 'none' ? '' : 'none'">
            <i class="bi bi-chevron-down me-1"></i>Details
        </button>
        <div class="details-contenu" id="${id}" style="display:none;">${echapper(JSON.stringify(detailsFiltres, null, 2))}</div>
    `;
}

// --- Help panel collapse ---
function collapserHelpPanel() {
    const body = document.getElementById('helpPanelBody');
    if (body) {
        const bsCollapse = bootstrap.Collapse.getInstance(body);
        if (bsCollapse) bsCollapse.hide();
    }
}
