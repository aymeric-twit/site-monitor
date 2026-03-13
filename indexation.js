/**
 * Site Monitor — Module Audit d'indexation.
 *
 * Charge apres commun.js et app.js.
 * Utilise : baseUrl, apiGet, apiPost, getCsrfToken, afficherToast, t
 */

'use strict';

// ---------------------------------------------------------------------------
// Etat
// ---------------------------------------------------------------------------

let donneesIndexation = null;
let auditIdCourant = null;
let jobIdIndexation = null;
let chartIndexStatuts = null;
let chartIndexContradictions = null;
let pollingIndexation = null;

// ---------------------------------------------------------------------------
// Initialisation
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    // Charger l'historique quand l'onglet est affiche
    const tabIndexation = document.getElementById('tab-indexation');
    if (tabIndexation) {
        tabIndexation.addEventListener('shown.bs.tab', () => {
            chargerHistoriqueIndexation();
            chargerClientsIndexation();
        });
    }

    // Compteur URLs dans le textarea
    const textareaUrls = document.getElementById('indexUrls');
    if (textareaUrls) {
        textareaUrls.addEventListener('input', () => {
            const lignes = textareaUrls.value.split('\n').filter(l => l.trim() !== '');
            document.getElementById('indexUrlsCount').textContent = lignes.length;
        });
    }

    // Source URLs : charger depuis client
    document.querySelectorAll('input[name="sourceUrls"]').forEach(radio => {
        radio.addEventListener('change', () => {
            if (radio.value === 'client' && radio.checked) {
                chargerUrlsDepuisClient();
            }
        });
    });

    // Auto-fill domaine depuis client
    const selectClient = document.getElementById('indexClient');
    if (selectClient) {
        selectClient.addEventListener('change', () => {
            const option = selectClient.selectedOptions[0];
            if (option && option.dataset.domaine) {
                document.getElementById('indexDomaine').value = option.dataset.domaine;
            }
            // Recharger les URLs si source = client
            if (document.querySelector('input[name="sourceUrls"]:checked')?.value === 'client') {
                chargerUrlsDepuisClient();
            }
        });
    }

    // Formulaire
    const form = document.getElementById('formIndexation');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            lancerAuditIndexation();
        });
    }

    // Filtres
    document.getElementById('filtreStatutIndex')?.addEventListener('change', filtrerResultatsIndexation);
    document.getElementById('filtreSeveriteIndex')?.addEventListener('change', filtrerResultatsIndexation);
    document.getElementById('rechercheUrlIndex')?.addEventListener('input', filtrerResultatsIndexation);

    // Export CSV
    document.getElementById('btnExporterIndexation')?.addEventListener('click', () => {
        if (auditIdCourant) {
            window.location.href = baseUrl + '/download-indexation.php?audit_id=' + auditIdCourant;
        }
    });
});

// ---------------------------------------------------------------------------
// Charger la liste des clients dans le select
// ---------------------------------------------------------------------------

async function chargerClientsIndexation() {
    try {
        const data = await apiGet({ entite: 'client', action: 'lister' });
        const select = document.getElementById('indexClient');
        if (!select || !data.donnees) return;

        // Garder la premiere option
        const premiereOption = select.options[0];
        select.innerHTML = '';
        select.appendChild(premiereOption);

        data.donnees.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.client_id;
            opt.textContent = c.nom;
            opt.dataset.domaine = c.domaine || '';
            select.appendChild(opt);
        });
    } catch { /* silencieux */ }
}

// ---------------------------------------------------------------------------
// Charger les URLs d'un client
// ---------------------------------------------------------------------------

async function chargerUrlsDepuisClient() {
    const clientId = document.getElementById('indexClient')?.value;
    if (!clientId) return;

    try {
        const data = await apiGet({ entite: 'indexation', action: 'urls_client', client_id: clientId });
        if (data.donnees) {
            const textarea = document.getElementById('indexUrls');
            textarea.value = data.donnees.join('\n');
            textarea.dispatchEvent(new Event('input'));
        }
    } catch {
        afficherToast(t('message.erreur_reseau'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Lancer un audit
// ---------------------------------------------------------------------------

async function lancerAuditIndexation() {
    const domaine = document.getElementById('indexDomaine').value.trim();
    const urls = document.getElementById('indexUrls').value.trim();
    const clientId = document.getElementById('indexClient')?.value || '';

    if (!domaine || !urls) {
        afficherToast(t('indexation.erreur.domaine_urls_requis', 'Domaine et URLs requis'), 'warning');
        return;
    }

    const btn = document.getElementById('btnLancerIndexation');
    btn.disabled = true;

    try {
        const data = await apiPost({
            entite: 'indexation',
            action: 'lancer',
            domaine: domaine,
            urls: urls,
            client_id: clientId,
        });

        if (data.erreur) {
            afficherToast(data.erreur, 'danger');
            btn.disabled = false;
            return;
        }

        jobIdIndexation = data.donnees.job_id;
        auditIdCourant = data.donnees.audit_id;

        afficherToast(t('indexation.audit_lance', 'Audit lance'), 'success');
        demarrerPollingIndexation(jobIdIndexation);

    } catch {
        afficherToast(t('message.erreur_reseau'), 'danger');
        btn.disabled = false;
    }
}

// ---------------------------------------------------------------------------
// Polling progression
// ---------------------------------------------------------------------------

function demarrerPollingIndexation(jobId) {
    const section = document.getElementById('progressIndexation');
    section.style.display = '';

    pollingIndexation = setInterval(async () => {
        try {
            const response = await fetch(baseUrl + '/progress.php?job=' + jobId);
            const data = await response.json();

            const bar = document.getElementById('progressBarIndexation');
            const status = document.getElementById('progressStatusIndexation');

            const pct = data.percent || 0;
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            status.textContent = data.step || '';

            if (data.status === 'done') {
                arreterPollingIndexation();
                section.style.display = 'none';
                document.getElementById('btnLancerIndexation').disabled = false;
                chargerResultatsAudit(auditIdCourant);
                chargerHistoriqueIndexation();
            } else if (data.status === 'error') {
                arreterPollingIndexation();
                section.style.display = 'none';
                document.getElementById('btnLancerIndexation').disabled = false;
                afficherToast(data.message || t('message.erreur'), 'danger');
                chargerHistoriqueIndexation();
            }
        } catch { /* retry */ }
    }, 2000);
}

function arreterPollingIndexation() {
    if (pollingIndexation) {
        clearInterval(pollingIndexation);
        pollingIndexation = null;
    }
}

// ---------------------------------------------------------------------------
// Charger les resultats d'un audit
// ---------------------------------------------------------------------------

async function chargerResultatsAudit(auditId) {
    auditIdCourant = auditId;

    try {
        // Charger l'audit et les resultats en parallele
        const [dataAudit, dataResultats] = await Promise.all([
            apiGet({ entite: 'indexation', action: 'obtenir', audit_id: auditId }),
            apiGet({ entite: 'indexation', action: 'resultats', audit_id: auditId }),
        ]);

        if (dataAudit.erreur || dataResultats.erreur) {
            afficherToast(dataAudit.erreur || dataResultats.erreur, 'danger');
            return;
        }

        const audit = dataAudit.donnees.audit;
        donneesIndexation = dataResultats.donnees;

        // KPIs
        rendreKpisIndexation(audit);

        // Charts
        rendreChartsIndexation(dataAudit.donnees.stats_statut, dataAudit.donnees.stats_contradictions);

        // Tableau
        rendreTableauIndexation(donneesIndexation);

        // Afficher les sections
        document.getElementById('kpiIndexation').style.display = '';
        document.getElementById('chartsIndexation').style.display = '';
        document.getElementById('resultatsIndexation').style.display = '';

    } catch {
        afficherToast(t('message.erreur_reseau'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Rendre les KPIs
// ---------------------------------------------------------------------------

function rendreKpisIndexation(audit) {
    document.getElementById('kpiIndexTotal').textContent = audit.urls_total || 0;
    document.getElementById('kpiIndexIndexables').textContent = audit.urls_indexables || 0;
    document.getElementById('kpiIndexNonIndexables').textContent = audit.urls_non_indexables || 0;
    document.getElementById('kpiIndexContradictions').textContent = audit.urls_contradictoires || 0;
}

// ---------------------------------------------------------------------------
// Charts
// ---------------------------------------------------------------------------

function rendreChartsIndexation(statsStatut, statsContradictions) {
    // Donut statuts
    const ctxStatuts = document.getElementById('chartIndexStatuts');
    if (chartIndexStatuts) chartIndexStatuts.destroy();

    chartIndexStatuts = new Chart(ctxStatuts, {
        type: 'doughnut',
        data: {
            labels: [
                t('indexation.statut.indexable', 'Indexable'),
                t('indexation.statut.non_indexable', 'Non indexable'),
                t('indexation.statut.contradictoire', 'Contradictoire'),
                t('indexation.statut.erreur', 'Erreur'),
            ],
            datasets: [{
                data: [
                    statsStatut.indexable || 0,
                    statsStatut.non_indexable || 0,
                    statsStatut.contradictoire || 0,
                    statsStatut.erreur || 0,
                ],
                backgroundColor: ['#198754', '#fbb03b', '#dc3545', '#6c757d'],
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                title: { display: true, text: t('indexation.chart.statuts', 'Statuts d\'indexation') },
            },
        },
    });

    // Bar contradictions
    const ctxContra = document.getElementById('chartIndexContradictions');
    if (chartIndexContradictions) chartIndexContradictions.destroy();

    const contradictionLabels = {
        'sitemap_plus_noindex': 'Sitemap + Noindex',
        'robots_bloque_plus_sitemap': 'Robots bloque + Sitemap',
        'canonical_autre_plus_sitemap': 'Canonical autre + Sitemap',
        'redirection_plus_sitemap': 'Redirect + Sitemap',
        'erreur_plus_sitemap': 'Erreur + Sitemap',
        'noindex_plus_canonical_self': 'Noindex + Canonical self',
        'double_blocage': 'Double blocage',
        'indexable_hors_sitemap': 'Indexable hors sitemap',
    };

    const contradictionCouleurs = {
        'sitemap_plus_noindex': '#dc3545',
        'robots_bloque_plus_sitemap': '#dc3545',
        'erreur_plus_sitemap': '#dc3545',
        'canonical_autre_plus_sitemap': '#fbb03b',
        'redirection_plus_sitemap': '#fbb03b',
        'indexable_hors_sitemap': '#fbb03b',
        'noindex_plus_canonical_self': '#66b2b2',
        'double_blocage': '#66b2b2',
    };

    const types = Object.keys(statsContradictions || {});
    chartIndexContradictions = new Chart(ctxContra, {
        type: 'bar',
        data: {
            labels: types.map(t => contradictionLabels[t] || t),
            datasets: [{
                label: t('indexation.chart.contradictions', 'Contradictions'),
                data: types.map(t => statsContradictions[t]),
                backgroundColor: types.map(t => contradictionCouleurs[t] || '#6c757d'),
            }],
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                title: { display: true, text: t('indexation.chart.par_type', 'Contradictions par type') },
            },
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}

// ---------------------------------------------------------------------------
// Tableau resultats
// ---------------------------------------------------------------------------

function rendreTableauIndexation(resultats) {
    const tbody = document.getElementById('tbodyIndexation');
    if (!tbody) return;

    if (!resultats || resultats.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">' + t('indexation.aucun_resultat', 'Aucun resultat.') + '</td></tr>';
        return;
    }

    tbody.innerHTML = resultats.map(r => {
        const urlTronquee = r.url.length > 60 ? r.url.substring(0, 57) + '...' : r.url;
        const badgeStatut = badgeStatutIndexation(r.statut_indexation);
        const iconeRobots = r.robots_txt_autorise ? '<span class="text-success">&#10003;</span>' : '<span class="text-danger">&#10007;</span>';
        const iconeSitemap = r.present_sitemap ? '<span class="text-success">&#10003;</span>' : '<span class="text-danger">&#10007;</span>';

        let contradictionsHtml = '';
        if (r.contradictions && r.contradictions.length > 0) {
            contradictionsHtml = r.contradictions.map(c => {
                const cls = 'badge-severite-' + (c.severite || 'info');
                return '<span class="badge ' + cls + ' me-1 mb-1" title="' + escHtmlAttr(c.message || '') + '">' + escHtml(c.type || '') + '</span>';
            }).join('');
        }

        const canonicalTexte = r.canonical
            ? (r.canonical_auto_reference ? '<small class="text-muted">self</small>' : '<small title="' + escHtmlAttr(r.canonical) + '">autre</small>')
            : '';

        return '<tr data-statut="' + (r.statut_indexation || '') + '" data-severite="' + (r.severite_max || '') + '" data-url="' + escHtmlAttr(r.url) + '">'
            + '<td title="' + escHtmlAttr(r.url) + '"><small>' + escHtml(urlTronquee) + '</small></td>'
            + '<td>' + badgeCodeHttp(r.code_http) + '</td>'
            + '<td><small>' + escHtml(r.meta_robots || '') + '</small></td>'
            + '<td>' + canonicalTexte + '</td>'
            + '<td class="text-center">' + iconeRobots + '</td>'
            + '<td class="text-center">' + iconeSitemap + '</td>'
            + '<td>' + badgeStatut + '</td>'
            + '<td>' + contradictionsHtml + '</td>'
            + '</tr>';
    }).join('');
}

function badgeStatutIndexation(statut) {
    const classes = {
        'indexable': 'bg-success',
        'non_indexable': 'bg-warning text-dark',
        'contradictoire': 'bg-danger',
        'erreur': 'bg-secondary',
    };
    const cls = classes[statut] || 'bg-secondary';
    const label = t('indexation.statut.' + statut, statut);
    return '<span class="badge ' + cls + '">' + escHtml(label) + '</span>';
}

function badgeCodeHttp(code) {
    if (!code) return '<span class="text-muted">—</span>';
    let cls = 'text-success';
    if (code >= 300 && code < 400) cls = 'text-info';
    if (code >= 400) cls = 'text-danger';
    return '<span class="fw-bold ' + cls + '">' + code + '</span>';
}

// ---------------------------------------------------------------------------
// Filtrage
// ---------------------------------------------------------------------------

function filtrerResultatsIndexation() {
    const statut = document.getElementById('filtreStatutIndex')?.value || '';
    const severite = document.getElementById('filtreSeveriteIndex')?.value || '';
    const recherche = (document.getElementById('rechercheUrlIndex')?.value || '').toLowerCase();

    const lignes = document.querySelectorAll('#tbodyIndexation tr[data-url]');
    lignes.forEach(tr => {
        let visible = true;
        if (statut && tr.dataset.statut !== statut) visible = false;
        if (severite && tr.dataset.severite !== severite) visible = false;
        if (recherche && !tr.dataset.url.toLowerCase().includes(recherche)) visible = false;
        tr.style.display = visible ? '' : 'none';
    });
}

// ---------------------------------------------------------------------------
// Historique
// ---------------------------------------------------------------------------

async function chargerHistoriqueIndexation() {
    try {
        const data = await apiGet({ entite: 'indexation', action: 'lister' });
        const tbody = document.getElementById('tbodyHistoriqueIndex');
        if (!tbody || !data.donnees) return;

        if (data.donnees.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' + t('indexation.aucun_audit', 'Aucun audit d\'indexation.') + '</td></tr>';
            return;
        }

        tbody.innerHTML = data.donnees.map(a => {
            const badgeStatut = a.statut === 'termine'
                ? '<span class="badge bg-success">' + t('statut.termine', 'Termine') + '</span>'
                : a.statut === 'en_cours'
                    ? '<span class="badge bg-primary">' + t('statut.en_cours', 'En cours') + '</span>'
                    : '<span class="badge bg-secondary">' + escHtml(a.statut) + '</span>';

            const btnVoir = a.statut === 'termine'
                ? '<button class="btn btn-outline-primary btn-sm" onclick="chargerResultatsAudit(' + a.id + ')">' + t('client.voir', 'Voir') + '</button>'
                : '';

            return '<tr>'
                + '<td><small>' + escHtml(a.cree_le || '') + '</small></td>'
                + '<td>' + escHtml(a.domaine || '') + '</td>'
                + '<td>' + (a.urls_total || 0) + '</td>'
                + '<td><span class="text-success fw-bold">' + (a.urls_indexables || 0) + '</span></td>'
                + '<td><span class="text-danger fw-bold">' + (a.urls_contradictoires || 0) + '</span></td>'
                + '<td>' + badgeStatut + '</td>'
                + '<td>' + btnVoir + '</td>'
                + '</tr>';
        }).join('');

    } catch { /* silencieux */ }
}

// ---------------------------------------------------------------------------
// Utilitaires
// ---------------------------------------------------------------------------

function escHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escHtmlAttr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
