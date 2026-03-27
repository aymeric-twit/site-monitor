'use strict';

/**
 * Site Monitor — Page dediee client.
 * Depend de : commun.js, translations.js
 */

let clientId = null;
let clientData = null;

// ---------------------------------------------------------------------------
// Chargement initial
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', async () => {
    const params = new URLSearchParams(window.location.search);
    clientId = params.get('id');

    if (!clientId) {
        afficherToast('Parametre id manquant', 'danger');
        return;
    }

    traduirePage();
    await chargerClient();
    await Promise.all([
        chargerGroupes(),
        chargerRegles(),
        chargerPlanification(),
        chargerChangementsClient(),
    ]);

    // Event listeners
    document.getElementById('btnAjouterGroupe')?.addEventListener('click', ajouterGroupe);
    document.getElementById('btnLancerAnalyse')?.addEventListener('click', lancerAnalyse);
    document.getElementById('btnCreerReglesVide')?.addEventListener('click', () => creerJeuRegles(null));
    document.getElementById('btnCreerPlanif')?.addEventListener('click', creerPlanification);
    document.getElementById('planifActifToggle')?.addEventListener('change', (e) => togglePlanification(e.target.checked));
    document.getElementById('btnSupprimerPlanif')?.addEventListener('click', supprimerPlanification);
    document.getElementById('btnConfirmerSuppression')?.addEventListener('click', () => {
        if (_confirmCallback) { _confirmCallback(); _confirmCallback = null; }
        bootstrap.Modal.getInstance(document.getElementById('modalConfirmation'))?.hide();
    });

    // Peupler le dropdown presets
    peuplerMenuPresets();
});

// ---------------------------------------------------------------------------
// Client
// ---------------------------------------------------------------------------

async function chargerClient() {
    try {
        const res = await apiGet({ entite: 'client', action: 'obtenir', id: clientId });
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        clientData = res.donnees;
        document.getElementById('clientNom').textContent = clientData.nom || '';
        document.getElementById('clientDomaine').textContent = clientData.domaine || '';
        document.title = 'Site Monitor — ' + (clientData.nom || '');
    } catch (e) {
        console.error('chargerClient:', e);
    }
}

// ---------------------------------------------------------------------------
// Groupes & URLs
// ---------------------------------------------------------------------------

async function chargerGroupes() {
    try {
        const res = await apiGet({ entite: 'groupe', action: 'lister', client_id: clientId });
        if (res.erreur) return;

        const groupes = res.donnees || [];
        const container = document.getElementById('listeGroupes');
        const vide = document.getElementById('groupesVide');

        if (groupes.length === 0) { vide.style.display = ''; return; }
        vide.style.display = 'none';

        // Charger les URLs pour chaque groupe
        let html = '<div class="accordion accordion-flush" id="accordionGroupes">';
        for (const g of groupes) {
            const urlsRes = await apiGet({ entite: 'url', action: 'lister', groupe_id: g.id });
            const urls = urlsRes.donnees || [];

            html += `
            <div class="accordion-item" data-groupe-id="${g.id}">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2 px-3" type="button" data-bs-toggle="collapse" data-bs-target="#groupe-${g.id}">
                        <span class="fw-semibold">${echapper(g.nom)}</span>
                        <span class="badge bg-secondary ms-2">${urls.length}</span>
                    </button>
                </h2>
                <div id="groupe-${g.id}" class="accordion-collapse collapse" data-bs-parent="#accordionGroupes">
                    <div class="accordion-body p-0">
                        <div class="list-group list-group-flush">
                            ${urls.map(u => `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-3 small">
                                    <span class="font-monospace text-truncate me-2" title="${echapper(u.url)}">${echapper(u.url)}</span>
                                    <button class="btn btn-outline-danger btn-sm py-0 px-1 flex-shrink-0" onclick="supprimerUrl(${u.id})" title="Supprimer"><i class="bi bi-x"></i></button>
                                </div>
                            `).join('')}
                        </div>
                        <div class="p-2">
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace" placeholder="https://..." id="inputUrl-${g.id}">
                                <button class="btn btn-outline-primary" onclick="ajouterUrlRapide(${g.id})"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        }
        html += '</div>';
        container.innerHTML = html;

    } catch (e) {
        console.error('chargerGroupes:', e);
    }
}

async function ajouterGroupe() {
    const nom = prompt(t('setup.nomGroupe', 'Nom du groupe :'));
    if (!nom || !nom.trim()) return;

    try {
        const res = await apiPost({ entite: 'groupe', action: 'creer', client_id: clientId, nom: nom.trim() });
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        afficherToast('Groupe cree', 'success');
        chargerGroupes();
    } catch (e) {
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function ajouterUrlRapide(groupeId) {
    const input = document.getElementById('inputUrl-' + groupeId);
    const url = input?.value.trim();
    if (!url) return;

    try {
        const res = await apiPost({ entite: 'url', action: 'creer', groupe_id: groupeId, url: url });
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        input.value = '';
        chargerGroupes();
    } catch (e) {
        afficherToast(t('message.erreur'), 'danger');
    }
}

async function supprimerUrl(id) {
    try {
        await apiPost({ entite: 'url', action: 'supprimer', id: id });
        chargerGroupes();
    } catch (e) {
        afficherToast(t('message.erreur'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Regles de surveillance
// ---------------------------------------------------------------------------

async function chargerRegles() {
    try {
        const res = await apiGet({ entite: 'modele', action: 'lister' });
        if (res.erreur) return;

        // Filtrer les modeles de ce client + les globaux
        const modeles = (res.donnees || []).filter(m =>
            m.client_id == clientId || m.est_global
        );

        const container = document.getElementById('listeRegles');
        const vide = document.getElementById('reglesVide');

        if (modeles.length === 0) { vide.style.display = ''; return; }
        vide.style.display = 'none';

        container.innerHTML = '<div class="list-group list-group-flush">' + modeles.map(m => {
            const nbRegles = m.nb_regles ?? m.nombre_regles ?? 0;
            const isGlobal = m.est_global ? '<span class="badge bg-info ms-1">Global</span>' : '';
            return `
                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                    <div>
                        <span class="fw-semibold">${echapper(m.nom)}</span>
                        <span class="badge bg-secondary ms-1">${nbRegles} regles</span>
                        ${isGlobal}
                    </div>
                    <a href="${baseUrl}/regles.php?modele_id=${m.id}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i>Gerer
                    </a>
                </div>`;
        }).join('') + '</div>';

    } catch (e) {
        console.error('chargerRegles:', e);
    }
}

async function peuplerMenuPresets() {
    try {
        const res = await apiGet({ entite: 'modele', action: 'templates' });
        if (!res.donnees) return;
        const menu = document.getElementById('menuPresetsRegles');
        for (const [cle, tpl] of Object.entries(res.donnees)) {
            const li = document.createElement('li');
            li.innerHTML = `<a class="dropdown-item" href="#"><i class="bi bi-lightning me-1"></i>${echapper(tpl.nom)} <span class="text-muted">(${tpl.nb_regles})</span></a>`;
            li.querySelector('a').addEventListener('click', (e) => { e.preventDefault(); creerJeuRegles(cle); });
            menu.appendChild(li);
        }
    } catch (e) {
        console.error('peuplerMenuPresets:', e);
    }
}

async function creerJeuRegles(presetCle) {
    const nom = presetCle
        ? null // Le nom sera auto-genere depuis le preset
        : prompt(t('modele.nom', 'Nom du jeu de regles :'));

    if (!presetCle && (!nom || !nom.trim())) return;

    try {
        const donnees = {
            entite: 'modele',
            action: 'creer',
            client_id: clientId,
            nom: nom?.trim() || '',
        };
        if (presetCle) donnees.template = presetCle;

        const res = await apiPost(donnees);
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        afficherToast(res.message || 'Regles creees', 'success');
        chargerRegles();
    } catch (e) {
        afficherToast(t('message.erreur'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Planification
// ---------------------------------------------------------------------------

let _planifId = null;

async function chargerPlanification() {
    try {
        const res = await apiGet({ entite: 'planification', action: 'lister', client_id: clientId });
        if (res.erreur) return;

        const planifs = res.donnees || [];
        const blocEx = document.getElementById('blocPlanifExistante');
        const blocCreer = document.getElementById('blocPlanifCreer');

        if (planifs.length > 0) {
            const p = planifs[0];
            _planifId = p.id;
            blocEx.style.display = '';
            blocCreer.style.display = 'none';
            const freqs = { 360: '6h', 720: '12h', 1440: '24h', 10080: 'Hebdo' };
            document.getElementById('planifFrequenceLabel').textContent = freqs[p.frequence_minutes] || (p.frequence_minutes + 'min');
            document.getElementById('planifProchaineLabel').textContent = p.prochaine_execution
                ? 'Prochaine : ' + new Date(p.prochaine_execution).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
                : '';
            document.getElementById('planifActifToggle').checked = !!p.actif;
        } else {
            _planifId = null;
            blocEx.style.display = 'none';
            blocCreer.style.display = '';
        }
    } catch (e) {
        console.error('chargerPlanification:', e);
    }
}

async function creerPlanification() {
    try {
        const res = await apiPost({
            entite: 'planification', action: 'creer',
            client_id: clientId,
            frequence_minutes: document.getElementById('planifFrequence').value,
        });
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        afficherToast('Planification activee', 'success');
        chargerPlanification();
    } catch (e) { afficherToast(t('message.erreur'), 'danger'); }
}

async function togglePlanification(actif) {
    if (!_planifId) return;
    try { await apiPost({ entite: 'planification', action: 'modifier', id: _planifId, actif: actif ? '1' : '0' }); }
    catch (e) { console.error(e); }
}

async function supprimerPlanification() {
    if (!_planifId) return;
    try {
        await apiPost({ entite: 'planification', action: 'supprimer', id: _planifId });
        afficherToast('Planification supprimee', 'success');
        chargerPlanification();
    } catch (e) { afficherToast(t('message.erreur'), 'danger'); }
}

// ---------------------------------------------------------------------------
// Lancer une analyse
// ---------------------------------------------------------------------------

async function lancerAnalyse() {
    try {
        const res = await apiPost({ entite: 'execution', action: 'lancer', client_id: clientId });
        if (res.erreur) { afficherToast(res.erreur, 'danger'); return; }
        afficherToast(res.message || 'Analyse lancee', 'success');
        if (res.donnees?.job_id) {
            demarrerPollingClient(res.donnees.job_id);
        }
    } catch (e) {
        afficherToast(t('message.erreur'), 'danger');
    }
}

function demarrerPollingClient(jobId) {
    const section = document.getElementById('progressSection');
    const bar = document.getElementById('progressBar');
    const status = document.getElementById('progressStatus');
    section.style.display = '';
    bar.style.width = '0%';

    const interval = setInterval(async () => {
        try {
            const response = await fetch(baseUrl + '/progress.php?job=' + encodeURIComponent(jobId));
            if (!response.ok) { clearInterval(interval); return; }
            const data = await response.json();
            const pct = data.percent || 0;
            bar.style.width = pct + '%';
            status.textContent = data.step || '';

            if (data.status === 'done' || data.status === 'error' || pct >= 100) {
                clearInterval(interval);
                bar.classList.remove('progress-bar-animated');
                if (data.status === 'error') {
                    afficherToast(data.step || 'Erreur', 'danger');
                } else {
                    afficherToast('Analyse terminee', 'success');
                }
                setTimeout(() => { section.style.display = 'none'; }, 5000);
                chargerChangementsClient();
            }
        } catch (e) {
            clearInterval(interval);
        }
    }, 2000);
}

// ---------------------------------------------------------------------------
// Changements recents pour ce client
// ---------------------------------------------------------------------------

async function chargerChangementsClient() {
    try {
        const res = await apiGet({ entite: 'dashboard', action: 'changements_feed' });
        if (res.erreur) return;

        const data = res.donnees;
        const items = [
            ...(data.nouvelles_defaillances || []),
            ...(data.defaillances_persistantes || []),
            ...(data.recuperations || []),
        ].filter(i => String(i.client_id) === String(clientId));

        const corps = document.getElementById('corpsChangementsClient');
        const vide = document.getElementById('changementsClientVide');

        if (items.length === 0) { corps.innerHTML = ''; vide.style.display = ''; return; }
        vide.style.display = 'none';

        corps.innerHTML = items.slice(0, 30).map(item => {
            const urlAff = item.url_libelle || (item.url?.length > 60 ? item.url.substring(0, 60) + '...' : item.url);
            const meta = [item.regle_type || item.regle_nom, (item.message || '').substring(0, 100)].filter(Boolean).join(' \u00b7 ');
            const type = item.type === 'baseline' ? 'persistantes' : (data.nouvelles_defaillances?.includes(item) ? 'nouvelles' : (data.recuperations?.includes(item) ? 'recuperations' : 'persistantes'));
            const icones = { nouvelles: 'bi-exclamation-triangle', recuperations: 'bi-check-circle', persistantes: 'bi-arrow-repeat' };
            return `
                <div class="feed-item feed-${type}">
                    <div class="feed-icone feed-icone-${type}"><i class="bi ${icones[type] || 'bi-arrow-repeat'}"></i></div>
                    <div class="feed-contenu">
                        <div class="feed-url" title="${echapper(item.url || '')}">${echapper(urlAff)}</div>
                        <div class="feed-meta">${echapper(meta)}</div>
                    </div>
                    <span class="badge-severite severite-${item.severite || 'erreur'}">${item.severite || ''}</span>
                </div>`;
        }).join('');

    } catch (e) {
        console.error('chargerChangementsClient:', e);
    }
}

// ---------------------------------------------------------------------------
// Confirmation
// ---------------------------------------------------------------------------

let _confirmCallback = null;
