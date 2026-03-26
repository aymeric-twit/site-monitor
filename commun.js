/**
 * Site Monitor — Fonctions communes partagees entre les pages.
 *
 * Inclus avant app.js, regles-page.js et regles-config.js.
 * Fournit : baseUrl, i18n, toast, confirmation, CSRF, API.
 */

'use strict';

const baseUrl = window.MODULE_BASE_URL || '.';

// ---------------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------------

let langueActuelle = window.PLATFORM_LANG || 'fr';

/**
 * Traduit une cle i18n dans la langue courante.
 * @param {string} cle
 * @param {string} [defaut]
 * @returns {string}
 */
function t(cle, defaut) {
    if (typeof TRANSLATIONS === 'undefined') return defaut || cle;
    return (TRANSLATIONS[langueActuelle] && TRANSLATIONS[langueActuelle][cle])
        || (TRANSLATIONS['fr'] && TRANSLATIONS['fr'][cle])
        || defaut
        || cle;
}

/** Applique les traductions a tous les elements [data-i18n]. */
function traduirePage() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const cle = el.getAttribute('data-i18n');
        const texte = t(cle);
        if (texte) {
            if (el.tagName === 'INPUT' && el.type !== 'hidden' && el.type !== 'checkbox') {
                if (el.placeholder) el.placeholder = texte;
            } else if (el.tagName === 'OPTION') {
                el.textContent = texte;
            } else {
                el.textContent = texte;
            }
        }
    });
}

// ---------------------------------------------------------------------------
// Toast
// ---------------------------------------------------------------------------

/**
 * Affiche un toast Bootstrap.
 * @param {string} message
 * @param {'success'|'danger'|'warning'|'info'} type
 */
function afficherToast(message, type = 'success') {
    const conteneur = document.getElementById('toastContainer');
    const tpl = document.getElementById('tplToast');
    if (!conteneur || !tpl) return;

    const clone = tpl.content.cloneNode(true);
    const toastEl = clone.querySelector('.toast');

    const couleurs = {
        success: 'bg-success text-white',
        danger: 'bg-danger text-white',
        warning: 'bg-warning text-dark',
        info: 'bg-info text-white',
    };
    toastEl.classList.add(...(couleurs[type] || couleurs.success).split(' '));
    toastEl.querySelector('.toast-body').textContent = message;

    conteneur.appendChild(clone);
    const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

// ---------------------------------------------------------------------------
// Confirmation modale
// ---------------------------------------------------------------------------

let confirmationCallback = null;

function ouvrirConfirmation(message, onConfirm) {
    const el = document.getElementById('modalConfirmation');
    document.getElementById('confirmationMessage').textContent = message;
    confirmationCallback = onConfirm;
    const modal = bootstrap.Modal.getOrCreateInstance(el);
    modal.show();
}

// ---------------------------------------------------------------------------
// Utilitaires
// ---------------------------------------------------------------------------

/**
 * Echappe le HTML.
 * @param {string} str
 * @returns {string}
 */
function echapper(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Verifie si une chaine est du JSON valide.
 * @param {string} str
 * @returns {boolean}
 */
function estJsonValide(str) {
    try {
        JSON.parse(str);
        return true;
    } catch {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Utilitaires API
// ---------------------------------------------------------------------------

/**
 * Recupere le token CSRF injecte par la plateforme ou present dans le DOM.
 * @returns {string}
 */
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    const input = document.querySelector('input[name="_csrf_token"]');
    if (input) return input.value;
    return '';
}

/**
 * Requete GET vers l'API.
 * @param {Object} params
 * @returns {Promise<Object>}
 */
async function apiGet(params) {
    const query = new URLSearchParams(params).toString();
    const response = await fetch(baseUrl + '/api.php?' + query, {
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
    });
    if (response.status === 429) {
        afficherToast(t('message.quota_epuise'), 'warning');
        throw new Error('Quota epuise');
    }
    const data = await response.json();
    if (!response.ok && data.erreur) {
        console.error('[API GET ' + response.status + ']', data.erreur);
    }
    return data;
}

/**
 * Requete POST vers l'API.
 * @param {Object} donnees
 * @returns {Promise<Object>}
 */
async function apiPost(donnees) {
    const fd = new FormData();
    const csrfToken = getCsrfToken();
    if (csrfToken) fd.append('_csrf_token', csrfToken);
    for (const [cle, val] of Object.entries(donnees)) {
        if (val !== null && val !== undefined) {
            fd.append(cle, String(val));
        }
    }
    const response = await fetch(baseUrl + '/api.php', { method: 'POST', body: fd });
    if (response.status === 429) {
        afficherToast(t('message.quota_epuise'), 'warning');
        throw new Error('Quota epuise');
    }
    const data = await response.json();
    if (!response.ok && data.erreur) {
        console.error('[API 500]', data.erreur);
        afficherToast(data.erreur, 'danger');
    }
    return data;
}
