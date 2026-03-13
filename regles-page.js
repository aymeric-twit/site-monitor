/**
 * Site Monitor — Page dediee de gestion des regles (inline).
 *
 * Affiche les regles d'un modele sous forme de tableau editable
 * avec une ligne par regle (style PageCrawl "Tracked Elements").
 *
 * Depend de : commun.js (baseUrl, langueActuelle, t, traduirePage,
 *             afficherToast, ouvrirConfirmation, getCsrfToken, apiGet, apiPost,
 *             echapper, estJsonValide)
 *           : translations.js
 *           : regles-config.js (SCHEMAS_REGLES, CATEGORIES_REGLES,
 *             META_TYPES_REGLES, PRESETS_REGLES, TEMPLATES_REGLES)
 */

'use strict';

// ---------------------------------------------------------------------------
// Etat
// ---------------------------------------------------------------------------

let modeleId = null;

// ---------------------------------------------------------------------------
// Conversion Valeur <-> Config JSON
// ---------------------------------------------------------------------------

/** Aide contextuelle pour le champ valeur selon le type. */
const AIDE_VALEUR = {
    code_http:           'Ex: 200  ou  200 | 2xx  ou  200 | 5 | https://url-finale',
    en_tete_http:        'Ex: X-Frame-Options | present  ou  Content-Type | contient | text/html',
    performance:         'Ex: ttfb | 800  (metriques: temps_total, ttfb, dns, connexion, ssl, taille)',
    ssl:                 'Ex: validite  ou  expiration | 30',
    disponibilite:       'Ex: soft_404  ou  maintenance|en cours  ou  soft_404 | lang | fr',
    meta_seo:            'Ex: title  ou  title | 30-60  ou  canonical',
    open_graph:          'Ex: og_complet  ou  og_title | 60  ou  og_image | img_check',
    twitter_card:        'Ex: card_type | summary_large_image  ou  complet',
    structure_titres:    'Ex: h1_unique  ou  contenu_h1 | Mon titre',
    donnees_structurees: 'Ex: presence_json_ld  ou  type_schema | Product  ou  champs_obligatoires | Product | name,price',
    liens_seo:           'Ex: comptage_internes | 5-100  ou  fil_ariane',
    images_seo:          'Ex: alt_manquant  ou  lazy_loading',
    performance_front:   'Ex: css_bloquants | 3  ou  mixed_content',
    xpath:               'Ex: //h1 | existe  ou  //h1 | texte_contient | Accueil',
    balise_html:         'Ex: h1.main-title | existe  ou  .breadcrumb | compte | 1-5',
    comptage_occurrences:'Ex: texte | mot-cle | superieur | 3',
    changement_contenu:  'Ex: body  ou  xpath | //main | 10  ou  body | 20 | stale_7',
    ressources_externes: 'Ex: presence_pattern | analytics.js,gtm.js',
    robots_txt:          'Ex: accessible  ou  sitemap_present  ou  urls_critiques | /page1,/page2',
    sitemap_xml:         'Ex: accessible  ou  comptage_urls | 100-50000',
    security_txt:        'Ex: accessible  ou  contact_present',
    ads_txt:             'Ex: accessible  ou  syntaxe_valide',
    favicon:             'Ex: present',
};

/**
 * Convertit une valeur texte libre en objet de configuration JSON
 * selon le type de regle.
 *
 * @param {string} type — Type de la regle (ex: 'code_http')
 * @param {string} valeur — Texte libre saisi par l'utilisateur
 * @returns {object} — Config JSON pour l'API
 */
function parseValeurConfig(type, valeur) {
    if (!valeur || !valeur.trim()) return {};
    const parties = valeur.split(/\s*\|\s*/);

    switch (type) {
        case 'code_http': {
            const config = { code_attendu: parseInt(parties[0], 10) || 200 };
            if (parties[1]) {
                if (/^\d+$/.test(parties[1])) {
                    config.max_redirections = parseInt(parties[1], 10);
                } else {
                    config.plage_acceptee = parties[1];
                }
            }
            if (parties[2]) config.url_finale_attendue = parties[2];
            return config;
        }

        case 'en_tete_http': {
            const config = { nom_entete: parties[0] || '' };
            if (parties[1]) config.operation = parties[1];
            if (parties[2]) config.valeur_attendue = parties[2];
            return config;
        }

        case 'performance': {
            const config = { metrique: parties[0] || 'temps_total', unite: 'ms' };
            if (parties[1]) {
                const num = parseInt(parties[1], 10);
                if (!isNaN(num)) config.seuil_max = num;
            }
            if (parties[2]) config.unite = parties[2];
            return config;
        }

        case 'ssl': {
            const config = { verification: parties[0] || 'validite' };
            if (parties[1]) {
                const num = parseInt(parties[1], 10);
                if (!isNaN(num)) config.jours_avant_expiration = num;
            }
            return config;
        }

        case 'disponibilite': {
            const config = {};
            // Detecter le flag lang dans les parties
            const idxLang = parties.indexOf('lang');
            if (idxLang !== -1 && parties[idxLang + 1]) {
                config.verifier_lang = true;
                config.lang_attendue = parties[idxLang + 1];
            }
            if (parties[0]) {
                if (parties[0] === 'soft_404') {
                    config.detecter_soft_404 = true;
                } else if (parties[0] !== 'lang') {
                    config.pattern_maintenance = parties[0];
                }
            }
            if (parties[1] && parties[1] !== 'lang' && !config.lang_attendue?.includes(parties[1])) {
                config.pattern_erreur = parties[1];
            }
            return config;
        }

        case 'meta_seo': {
            const config = { verification: parties[0] || 'title' };
            if (parties[1]) {
                const range = parties[1].match(/^(\d+)\s*-\s*(\d+)$/);
                if (range) {
                    config.longueur_min = parseInt(range[1], 10);
                    config.longueur_max = parseInt(range[2], 10);
                } else {
                    config.contenu_attendu = parties[1];
                }
            }
            return config;
        }

        case 'open_graph': {
            const config = { verification: parties[0] || 'og_complet' };
            for (let i = 1; i < parties.length; i++) {
                if (parties[i] === 'img_check') {
                    config.verifier_image_accessible = true;
                } else {
                    const num = parseInt(parties[i], 10);
                    if (!isNaN(num)) config.longueur_max = num;
                }
            }
            return config;
        }

        case 'twitter_card': {
            const config = { verification: parties[0] || 'complet' };
            if (parties[1]) config.contenu_attendu = parties[1];
            return config;
        }

        case 'structure_titres': {
            const config = { verification: parties[0] || 'h1_unique' };
            if (parties[1]) config.contenu_attendu = parties[1];
            return config;
        }

        case 'donnees_structurees': {
            const config = { verification: parties[0] || 'presence_json_ld' };
            if (parties[1]) {
                // Si contient une virgule, c'est une liste de champs, pas un type
                if (parties[1].includes(',')) {
                    config.champs = parties[1].split(',').map(s => s.trim());
                } else {
                    config.type_attendu = parties[1];
                }
            }
            if (parties[2]) config.champs = parties[2].split(',').map(s => s.trim());
            return config;
        }

        case 'liens_seo': {
            const config = { verification: parties[0] || 'comptage_internes' };
            if (parties[1]) {
                const range = parties[1].match(/^(\d+)\s*-\s*(\d+)$/);
                if (range) {
                    config.nombre_min = parseInt(range[1], 10);
                    config.nombre_max = parseInt(range[2], 10);
                } else {
                    config.domaine_reference = parties[1];
                }
            }
            return config;
        }

        case 'images_seo': {
            return { verification: parties[0] || 'alt_manquant' };
        }

        case 'performance_front': {
            const config = { verification: parties[0] || 'css_bloquants' };
            if (parties[1]) {
                const num = parseInt(parties[1], 10);
                if (!isNaN(num)) {
                    const verif = config.verification;
                    if (verif === 'css_bloquants') config.max_css = num;
                    else if (verif === 'js_bloquants') config.max_js = num;
                    else if (verif === 'inline_excessif') config.seuil_inline_octets = num;
                }
            }
            return config;
        }

        case 'xpath': {
            const config = { expression: parties[0] || '' };
            if (parties[1]) config.operation = parties[1];
            if (parties[2]) config.valeur_attendue = parties[2];
            if (parties[3]) config.attribut = parties[3];
            return config;
        }

        case 'balise_html': {
            const config = { selecteur: parties[0] || '' };
            if (parties[1]) config.operation = parties[1];
            if (parties[2]) {
                const range = parties[2].match(/^(\d+)\s*-\s*(\d+)$/);
                if (range) {
                    config.nombre_min = parseInt(range[1], 10);
                    config.nombre_max = parseInt(range[2], 10);
                } else {
                    config.valeur_attendue = parties[2];
                }
            }
            if (parties[3]) config.attribut = parties[3];
            return config;
        }

        case 'comptage_occurrences': {
            const config = { type_recherche: parties[0] || 'texte' };
            if (parties[1]) config.motif = parties[1];
            if (parties[2]) config.operateur = parties[2];
            if (parties[3]) {
                const num = parseInt(parties[3], 10);
                if (!isNaN(num)) config.valeur_attendue = num;
            }
            return config;
        }

        case 'changement_contenu': {
            const config = { zone: parties[0] || 'body' };
            if (parties[0] === 'xpath' && parties[1]) config.expression_xpath = parties[1];
            else if (parties[0] === 'regex' && parties[1]) config.expression_regex = parties[1];
            // Parcourir les parties pour trouver seuil et stale_N
            for (let i = 1; i < parties.length; i++) {
                const staleMatch = parties[i].match(/^stale_(\d+)$/);
                if (staleMatch) {
                    config.alerter_si_identique_jours = parseInt(staleMatch[1], 10);
                } else if (/^\d+$/.test(parties[i]) && !config.expression_xpath && !config.expression_regex) {
                    config.seuil_changement_pourcent = parseInt(parties[i], 10);
                } else if (/^\d+$/.test(parties[i]) && i >= 2) {
                    config.seuil_changement_pourcent = parseInt(parties[i], 10);
                }
            }
            return config;
        }

        case 'ressources_externes': {
            const config = { verification: parties[0] || 'presence_pattern' };
            if (parties[1]) config.patterns = parties[1].split(',').map(s => s.trim());
            if (parties[2]) config.patterns_exclus = parties[2].split(',').map(s => s.trim());
            return config;
        }

        case 'robots_txt': {
            const config = { verification: parties[0] || 'accessible' };
            if (parties[1]) {
                if (config.verification === 'urls_critiques') {
                    config.urls_critiques = parties[1].split(',').map(s => s.trim());
                } else if (config.verification === 'taille') {
                    const num = parseInt(parties[1], 10);
                    if (!isNaN(num)) config.taille_max_octets = num;
                }
            }
            return config;
        }

        case 'sitemap_xml': {
            const config = { verification: parties[0] || 'accessible' };
            if (parties[1]) {
                const range = parties[1].match(/^(\d+)\s*-\s*(\d+)$/);
                if (range) {
                    config.nombre_urls_min = parseInt(range[1], 10);
                    config.nombre_urls_max = parseInt(range[2], 10);
                } else {
                    const num = parseInt(parties[1], 10);
                    if (!isNaN(num)) config.taille_max_octets = num;
                }
            }
            return config;
        }

        case 'security_txt':
        case 'ads_txt':
        case 'favicon':
            return { verification: parties[0] || 'accessible' };

        default:
            try {
                return JSON.parse(valeur);
            } catch {
                return { valeur: valeur };
            }
    }
}

/**
 * Convertit un objet de configuration JSON en texte lisible
 * pour affichage dans le champ valeur.
 *
 * @param {string} type — Type de la regle
 * @param {object} config — Configuration JSON
 * @returns {string} — Texte lisible
 */
function configVersValeur(type, config) {
    if (!config || typeof config !== 'object') return '';

    switch (type) {
        case 'code_http': {
            const parts = [config.code_attendu ?? 200];
            if (config.plage_acceptee) parts.push(config.plage_acceptee);
            else if (config.max_redirections != null) parts.push(config.max_redirections);
            if (config.url_finale_attendue) parts.push(config.url_finale_attendue);
            return parts.join(' | ');
        }

        case 'en_tete_http': {
            const parts = [config.nom_entete || ''];
            if (config.operation) parts.push(config.operation);
            if (config.valeur_attendue) parts.push(config.valeur_attendue);
            return parts.join(' | ');
        }

        case 'performance': {
            const parts = [config.metrique || 'temps_total'];
            if (config.seuil_max != null) parts.push(config.seuil_max);
            return parts.join(' | ');
        }

        case 'ssl': {
            const parts = [config.verification || 'validite'];
            if (config.jours_avant_expiration) parts.push(config.jours_avant_expiration);
            return parts.join(' | ');
        }

        case 'disponibilite': {
            const parts = [];
            if (config.detecter_soft_404) {
                parts.push('soft_404');
            } else if (config.pattern_maintenance) {
                parts.push(config.pattern_maintenance);
            }
            if (config.pattern_erreur) parts.push(config.pattern_erreur);
            if (config.verifier_lang) {
                parts.push('lang');
                if (config.lang_attendue) parts.push(config.lang_attendue);
            }
            return parts.join(' | ');
        }

        case 'meta_seo': {
            const parts = [config.verification || 'title'];
            if (config.longueur_min != null && config.longueur_max != null) {
                parts.push(config.longueur_min + '-' + config.longueur_max);
            } else if (config.contenu_attendu) {
                parts.push(config.contenu_attendu);
            }
            return parts.join(' | ');
        }

        case 'open_graph': {
            const parts = [config.verification || 'og_complet'];
            if (config.longueur_max) parts.push(config.longueur_max);
            if (config.verifier_image_accessible) parts.push('img_check');
            return parts.join(' | ');
        }

        case 'twitter_card': {
            const parts = [config.verification || 'complet'];
            if (config.contenu_attendu) parts.push(config.contenu_attendu);
            return parts.join(' | ');
        }

        case 'structure_titres': {
            const parts = [config.verification || 'h1_unique'];
            if (config.contenu_attendu) parts.push(config.contenu_attendu);
            return parts.join(' | ');
        }

        case 'donnees_structurees': {
            const parts = [config.verification || 'presence_json_ld'];
            if (config.type_attendu) parts.push(config.type_attendu);
            if (config.champs && Array.isArray(config.champs)) parts.push(config.champs.join(','));
            return parts.join(' | ');
        }

        case 'liens_seo': {
            const parts = [config.verification || 'comptage_internes'];
            if (config.nombre_min != null && config.nombre_max != null) {
                parts.push(config.nombre_min + '-' + config.nombre_max);
            } else if (config.domaine_reference) {
                parts.push(config.domaine_reference);
            }
            return parts.join(' | ');
        }

        case 'images_seo':
            return config.verification || 'alt_manquant';

        case 'performance_front': {
            const parts = [config.verification || 'css_bloquants'];
            if (config.max_css != null) parts.push(config.max_css);
            else if (config.max_js != null) parts.push(config.max_js);
            else if (config.seuil_inline_octets != null) parts.push(config.seuil_inline_octets);
            return parts.join(' | ');
        }

        case 'xpath': {
            const parts = [config.expression || ''];
            if (config.operation) parts.push(config.operation);
            if (config.valeur_attendue) parts.push(config.valeur_attendue);
            if (config.attribut) parts.push(config.attribut);
            return parts.join(' | ');
        }

        case 'balise_html': {
            const parts = [config.selecteur || ''];
            if (config.operation) parts.push(config.operation);
            if (config.operation === 'compte' && config.nombre_min != null && config.nombre_max != null) {
                parts.push(config.nombre_min + '-' + config.nombre_max);
            } else if (config.valeur_attendue) {
                parts.push(config.valeur_attendue);
            }
            if (config.attribut) parts.push(config.attribut);
            return parts.join(' | ');
        }

        case 'comptage_occurrences': {
            const parts = [config.type_recherche || 'texte'];
            if (config.motif) parts.push(config.motif);
            if (config.operateur) parts.push(config.operateur);
            if (config.valeur_attendue != null) parts.push(config.valeur_attendue);
            return parts.join(' | ');
        }

        case 'changement_contenu': {
            const parts = [config.zone || 'body'];
            if (config.expression_xpath) parts.push(config.expression_xpath);
            else if (config.expression_regex) parts.push(config.expression_regex);
            if (config.seuil_changement_pourcent != null) parts.push(config.seuil_changement_pourcent);
            if (config.alerter_si_identique_jours) parts.push('stale_' + config.alerter_si_identique_jours);
            return parts.join(' | ');
        }

        case 'ressources_externes': {
            const parts = [config.verification || 'presence_pattern'];
            if (config.patterns && Array.isArray(config.patterns)) parts.push(config.patterns.join(','));
            if (config.patterns_exclus && Array.isArray(config.patterns_exclus)) parts.push(config.patterns_exclus.join(','));
            return parts.join(' | ');
        }

        case 'robots_txt': {
            const parts = [config.verification || 'accessible'];
            if (config.urls_critiques && Array.isArray(config.urls_critiques)) parts.push(config.urls_critiques.join(','));
            else if (config.taille_max_octets) parts.push(config.taille_max_octets);
            return parts.join(' | ');
        }

        case 'sitemap_xml': {
            const parts = [config.verification || 'accessible'];
            if (config.nombre_urls_min != null && config.nombre_urls_max != null) {
                parts.push(config.nombre_urls_min + '-' + config.nombre_urls_max);
            } else if (config.taille_max_octets) {
                parts.push(config.taille_max_octets);
            }
            return parts.join(' | ');
        }

        case 'security_txt':
        case 'ads_txt':
        case 'favicon':
            return config.verification || 'accessible';

        default:
            return JSON.stringify(config);
    }
}

/**
 * Genere un nom automatique depuis le type et la valeur.
 */
function genererNomAuto(type, valeur) {
    const meta = META_TYPES_REGLES[type];
    const typeName = meta ? _libelleLocal(meta) : type;
    if (!valeur) return typeName;

    const parties = valeur.split(/\s*\|\s*/);
    const resume = parties.slice(0, 2).join(' ');
    return typeName + ' \u2014 ' + resume;
}

function _libelleLocal(obj) {
    if (!obj) return '';
    if (langueActuelle === 'en' && obj.libelleEn) return obj.libelleEn;
    return obj.libelle || obj.libelleEn || '';
}

// ---------------------------------------------------------------------------
// Peuplement du select type (avec optgroups)
// ---------------------------------------------------------------------------

function peuplerSelectType(selectEl) {
    selectEl.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = t('regle.placeholder_type', '\u2014 Type \u2014');
    selectEl.appendChild(placeholder);

    for (const [, categorie] of Object.entries(CATEGORIES_REGLES)) {
        const optgroup = document.createElement('optgroup');
        optgroup.label = _libelleLocal(categorie);
        for (const typeRegle of categorie.types) {
            const meta = META_TYPES_REGLES[typeRegle];
            if (!meta) continue;
            const option = document.createElement('option');
            option.value = typeRegle;
            option.textContent = _libelleLocal(meta);
            optgroup.appendChild(option);
        }
        selectEl.appendChild(optgroup);
    }
}

// ---------------------------------------------------------------------------
// Rendu d'une ligne de regle existante
// ---------------------------------------------------------------------------

function creerLigneRegle(regle) {
    const tr = document.createElement('tr');
    tr.setAttribute('data-regle-id', regle.id);

    // Parsing securise de la config JSON
    let configObj = {};
    try {
        const raw = regle.configuration || regle.configuration_json || '{}';
        const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
        configObj = typeof parsed === 'string' ? JSON.parse(parsed) : parsed;
    } catch (e) {
        console.warn('Config invalide pour la regle', regle.id, e);
    }

    const typeRegle = regle.type_regle || '';
    const valeurTexte = configVersValeur(typeRegle, configObj);

    // Type (select)
    const tdType = document.createElement('td');
    const selectType = document.createElement('select');
    selectType.className = 'form-select form-select-sm champ-inline';
    peuplerSelectType(selectType);
    selectType.value = typeRegle;
    selectType.addEventListener('change', () => sauvegarderLigne(regle.id));
    tdType.appendChild(selectType);

    // Nom
    const tdNom = document.createElement('td');
    const inputNom = document.createElement('input');
    inputNom.type = 'text';
    inputNom.className = 'form-control form-control-sm champ-inline';
    inputNom.value = regle.nom || '';
    inputNom.addEventListener('blur', () => sauvegarderLigne(regle.id));
    inputNom.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sauvegarderLigne(regle.id); } });
    tdNom.appendChild(inputNom);

    // Valeur
    const tdValeur = document.createElement('td');
    const inputValeur = document.createElement('input');
    inputValeur.type = 'text';
    inputValeur.className = 'form-control form-control-sm champ-inline font-monospace';
    inputValeur.value = valeurTexte;
    inputValeur.placeholder = AIDE_VALEUR[typeRegle] || t('regle.placeholder_valeur', 'Valeur...');
    inputValeur.addEventListener('blur', () => sauvegarderLigne(regle.id));
    inputValeur.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sauvegarderLigne(regle.id); } });
    inputValeur.addEventListener('focus', () => afficherAideValeur(typeRegle));
    tdValeur.appendChild(inputValeur);

    // Severite
    const tdSev = document.createElement('td');
    const selectSev = document.createElement('select');
    selectSev.className = 'form-select form-select-sm champ-inline';
    ['info', 'avertissement', 'erreur', 'critique'].forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = t('severite.' + s, s.charAt(0).toUpperCase() + s.slice(1));
        selectSev.appendChild(opt);
    });
    selectSev.value = regle.severite || 'erreur';
    selectSev.addEventListener('change', () => sauvegarderLigne(regle.id));
    tdSev.appendChild(selectSev);

    // Actif (toggle)
    const tdActif = document.createElement('td');
    const divSwitch = document.createElement('div');
    divSwitch.className = 'form-check form-switch mb-0';
    const inputActif = document.createElement('input');
    inputActif.type = 'checkbox';
    inputActif.className = 'form-check-input';
    inputActif.checked = !!regle.actif;
    inputActif.addEventListener('change', () => sauvegarderLigne(regle.id));
    divSwitch.appendChild(inputActif);
    tdActif.appendChild(divSwitch);

    // Actions
    const tdActions = document.createElement('td');
    const btnSuppr = document.createElement('button');
    btnSuppr.type = 'button';
    btnSuppr.className = 'btn btn-outline-danger btn-sm';
    btnSuppr.title = t('regle.supprimer_titre', 'Supprimer');
    btnSuppr.innerHTML = '<i class="bi bi-trash"></i>';
    btnSuppr.addEventListener('click', () => supprimerRegle(regle.id));
    tdActions.appendChild(btnSuppr);

    tr.appendChild(tdType);
    tr.appendChild(tdNom);
    tr.appendChild(tdValeur);
    tr.appendChild(tdSev);
    tr.appendChild(tdActif);
    tr.appendChild(tdActions);

    return tr;
}

// ---------------------------------------------------------------------------
// Chargement des regles
// ---------------------------------------------------------------------------

async function chargerRegles() {
    try {
        const res = await apiGet({ entite: 'regle', action: 'lister', modele_id: modeleId });
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        const regles = res.donnees || [];
        const tbody = document.getElementById('bodyRegles');
        const rowVide = document.getElementById('rowReglesVide');

        tbody.querySelectorAll('tr:not(#rowReglesVide)').forEach(tr => tr.remove());

        if (regles.length === 0) {
            rowVide.style.display = '';
            return;
        }
        rowVide.style.display = 'none';

        regles.forEach(r => {
            tbody.appendChild(creerLigneRegle(r));
        });
    } catch (e) {
        console.error('chargerRegles:', e);
        afficherToast(t('regle.erreur_chargement', 'Erreur lors du chargement des regles'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Sauvegarde inline d'une ligne
// ---------------------------------------------------------------------------

const _sauvegardeEnCours = new Set();

async function sauvegarderLigne(id) {
    if (_sauvegardeEnCours.has(id)) return;
    _sauvegardeEnCours.add(id);

    try {
        const tr = document.querySelector(`tr[data-regle-id="${id}"]`);
        if (!tr) return;

        const selectType = tr.querySelector('td:nth-child(1) select');
        const inputNom = tr.querySelector('td:nth-child(2) input');
        const inputValeur = tr.querySelector('td:nth-child(3) input');
        const selectSev = tr.querySelector('td:nth-child(4) select');
        const inputActif = tr.querySelector('td:nth-child(5) input');

        const typeRegle = selectType.value;
        const valeur = inputValeur.value.trim();
        const config = parseValeurConfig(typeRegle, valeur);

        const donnees = {
            entite: 'regle',
            action: 'modifier',
            id: id,
            modele_id: modeleId,
            type_regle: typeRegle,
            nom: inputNom.value.trim() || genererNomAuto(typeRegle, valeur),
            configuration_json: JSON.stringify(config),
            severite: selectSev.value,
            actif: inputActif.checked ? '1' : '0',
        };

        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }

        tr.classList.add('ligne-sauvegardee');
        setTimeout(() => tr.classList.remove('ligne-sauvegardee'), 800);

        inputValeur.placeholder = AIDE_VALEUR[typeRegle] || t('regle.placeholder_valeur', 'Valeur...');

        if (!inputNom.value.trim()) {
            inputNom.value = genererNomAuto(typeRegle, valeur);
        }
    } catch (e) {
        console.error('sauvegarderLigne:', e);
        afficherToast(t('regle.erreur_sauvegarde', 'Erreur lors de la sauvegarde'), 'danger');
    } finally {
        _sauvegardeEnCours.delete(id);
    }
}

// ---------------------------------------------------------------------------
// Ajout d'une regle
// ---------------------------------------------------------------------------

async function ajouterRegle(type, nom, valeur, severite) {
    const typeRegle = type || document.getElementById('ajoutType').value;
    const valeurTexte = valeur ?? document.getElementById('ajoutValeur').value.trim();
    const nomTexte = nom ?? document.getElementById('ajoutNom').value.trim();
    const sev = severite || document.getElementById('ajoutSeverite').value;

    if (!typeRegle) {
        afficherToast(t('regle.selectionnez_type', 'Selectionnez un type de regle'), 'warning');
        return;
    }

    const config = parseValeurConfig(typeRegle, valeurTexte);

    const donnees = {
        entite: 'regle',
        action: 'creer',
        modele_id: modeleId,
        type_regle: typeRegle,
        nom: nomTexte || genererNomAuto(typeRegle, valeurTexte),
        configuration_json: JSON.stringify(config),
        severite: sev,
        actif: '1',
    };

    try {
        const res = await apiPost(donnees);
        if (res.erreur) {
            afficherToast(res.erreur, 'danger');
            return;
        }
        afficherToast(res.message || t('regle.ajoutee', 'Regle ajoutee'), 'success');

        // Reinitialiser la ligne d'ajout
        document.getElementById('ajoutType').value = '';
        document.getElementById('ajoutNom').value = '';
        document.getElementById('ajoutValeur').value = '';
        document.getElementById('ajoutSeverite').value = 'erreur';
        document.getElementById('carteAideValeur').style.display = 'none';

        await chargerRegles();
    } catch (e) {
        console.error('ajouterRegle:', e);
        afficherToast(t('regle.erreur_ajout', 'Erreur lors de l\'ajout'), 'danger');
    }
}

// ---------------------------------------------------------------------------
// Suppression
// ---------------------------------------------------------------------------

function supprimerRegle(id) {
    ouvrirConfirmation(
        t('regle.confirmer_suppression', 'Supprimer cette regle ?'),
        async () => {
            try {
                const res = await apiPost({ entite: 'regle', action: 'supprimer', id: id });
                if (res.erreur) {
                    afficherToast(res.erreur, 'danger');
                    return;
                }
                afficherToast(res.message || t('regle.supprimee', 'Regle supprimee'), 'success');
                await chargerRegles();
            } catch (e) {
                console.error('supprimerRegle:', e);
                afficherToast(t('regle.erreur_suppression', 'Erreur lors de la suppression'), 'danger');
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Aide valeur
// ---------------------------------------------------------------------------

function afficherAideValeur(type) {
    const carte = document.getElementById('carteAideValeur');
    const texte = document.getElementById('aideValeurTexte');
    if (AIDE_VALEUR[type]) {
        texte.textContent = AIDE_VALEUR[type];
        carte.style.display = '';
    } else {
        carte.style.display = 'none';
    }
}

// ---------------------------------------------------------------------------
// Presets & Templates
// ---------------------------------------------------------------------------

function peuplerPresets() {
    const menu = document.getElementById('menuPresets');
    if (!menu) return;
    menu.innerHTML = '';

    // Presets individuels
    PRESETS_REGLES.forEach(preset => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.className = 'dropdown-item';
        a.href = '#';
        a.textContent = preset.nom;
        a.addEventListener('click', async (e) => {
            e.preventDefault();
            const valeur = configVersValeur(preset.type, preset.config);
            await ajouterRegle(preset.type, preset.nom, valeur, preset.severite);
        });
        li.appendChild(a);
        menu.appendChild(li);
    });

    // Separateur + "Tous les presets"
    menu.appendChild(_creerSeparateur());

    const liTous = document.createElement('li');
    const aTous = document.createElement('a');
    aTous.className = 'dropdown-item fw-semibold';
    aTous.href = '#';
    aTous.innerHTML = '<i class="bi bi-plus-circle me-1"></i>' + echapper(t('regle.tous_presets', 'Tous les presets'));
    aTous.addEventListener('click', async (e) => {
        e.preventDefault();
        for (const preset of PRESETS_REGLES) {
            const valeur = configVersValeur(preset.type, preset.config);
            await ajouterRegle(preset.type, preset.nom, valeur, preset.severite);
        }
    });
    liTous.appendChild(aTous);
    menu.appendChild(liTous);
}

function peuplerTemplates() {
    const menu = document.getElementById('menuTemplates');
    if (!menu || typeof TEMPLATES_REGLES === 'undefined') return;
    menu.innerHTML = '';

    TEMPLATES_REGLES.forEach(tpl => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.className = 'dropdown-item';
        a.href = '#';
        const nom = langueActuelle === 'en' && tpl.nomEn ? tpl.nomEn : tpl.nom;
        a.innerHTML = '<strong>' + echapper(nom) + '</strong> <span class="text-muted small">(' + tpl.regles.length + ')</span>';
        a.addEventListener('click', async (e) => {
            e.preventDefault();
            for (const r of tpl.regles) {
                await ajouterRegle(r.type, '', r.valeur, r.severite);
            }
        });
        li.appendChild(a);
        menu.appendChild(li);
    });
}

function _creerSeparateur() {
    const li = document.createElement('li');
    li.innerHTML = '<hr class="dropdown-divider">';
    return li;
}

// ---------------------------------------------------------------------------
// Chargement du nom du modele
// ---------------------------------------------------------------------------

async function chargerNomModele() {
    try {
        const res = await apiGet({ entite: 'modele', action: 'obtenir', id: modeleId });
        if (res.donnees) {
            document.getElementById('nomModele').textContent = '\u2014 ' + (res.donnees.nom || 'Modele #' + modeleId);
            document.title = 'Site Monitor \u2014 ' + t('regle.titre', 'Regles') + ' \u2014 ' + (res.donnees.nom || '');
        }
    } catch (e) {
        console.error('chargerNomModele:', e);
    }
}

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    modeleId = params.get('modele_id');

    if (!modeleId) {
        afficherToast(t('regle.parametre_modele_manquant', 'Parametre modele_id manquant'), 'danger');
        return;
    }

    // Peupler le select type de la ligne d'ajout
    peuplerSelectType(document.getElementById('ajoutType'));

    // Aide valeur dynamique sur le select d'ajout
    document.getElementById('ajoutType').addEventListener('change', (e) => {
        const type = e.target.value;
        const inputValeur = document.getElementById('ajoutValeur');
        inputValeur.placeholder = AIDE_VALEUR[type] || t('regle.placeholder_valeur', 'Valeur...');
        afficherAideValeur(type);
    });

    // Bouton ajouter
    document.getElementById('btnAjouterRegle').addEventListener('click', () => ajouterRegle());

    // Ajout rapide via Enter dans le champ valeur
    document.getElementById('ajoutValeur').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            ajouterRegle();
        }
    });

    // Confirmation modale
    document.getElementById('btnConfirmerSuppression').addEventListener('click', () => {
        if (confirmationCallback) {
            confirmationCallback();
            confirmationCallback = null;
        }
        bootstrap.Modal.getInstance(document.getElementById('modalConfirmation'))?.hide();
    });

    // Langue
    document.querySelectorAll('#langSelector .btn').forEach(btn => {
        btn.addEventListener('click', () => {
            langueActuelle = btn.getAttribute('data-lang');
            document.querySelectorAll('#langSelector .btn').forEach(b => b.classList.toggle('active', b === btn));
            traduirePage();
            chargerRegles();
        });
    });
    const langActif = document.querySelector(`#langSelector .btn[data-lang="${langueActuelle}"]`);
    if (langActif) langActif.classList.add('active');

    // Presets & Templates
    peuplerPresets();
    peuplerTemplates();

    traduirePage();
    chargerNomModele();
    chargerRegles();
});
