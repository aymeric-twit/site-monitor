/**
 * Site Monitor — Configuration dynamique des regles de verification.
 *
 * Registre de schemas, constructeur de formulaire, synchronisation
 * formulaire <-> JSON, presets et nommage automatique.
 *
 * Depend de app.js (baseUrl, t(), echapper, apiGet, apiPost)
 * et translations.js (TRANSLATIONS).
 */

'use strict';

// ---------------------------------------------------------------------------
// 1. SCHEMAS_REGLES — Registre complet des 23 types
// ---------------------------------------------------------------------------

const SCHEMAS_REGLES = {

    code_http: [
        { cle: 'code_attendu', type: 'number', libelle: 'Code attendu', libelleEn: 'Expected code', placeholder: '200', min: 100, max: 599 },
        { cle: 'plage_acceptee', type: 'select', libelle: 'Plage acceptee', libelleEn: 'Accepted range', options: [
            { valeur: '', libelle: '\u2014 Aucune \u2014', libelleEn: '\u2014 None \u2014' },
            { valeur: '2xx', libelle: '2xx (succes)', libelleEn: '2xx (success)' },
            { valeur: '3xx', libelle: '3xx (redirections)', libelleEn: '3xx (redirects)' },
            { valeur: '4xx', libelle: '4xx (erreurs client)', libelleEn: '4xx (client errors)' },
            { valeur: '5xx', libelle: '5xx (erreurs serveur)', libelleEn: '5xx (server errors)' },
        ]},
        { cle: 'max_redirections', type: 'number', libelle: 'Max redirections', libelleEn: 'Max redirects', defaut: 5, min: 0, max: 20 },
        { cle: 'verifier_redirection', type: 'checkbox', libelle: 'Verifier la redirection finale', libelleEn: 'Verify final redirect' },
        { cle: 'url_finale_attendue', type: 'text', libelle: 'URL finale attendue', libelleEn: 'Expected final URL', placeholder: 'https://...', dependDe: { champ: 'verifier_redirection', valeurs: [true] } },
    ],

    en_tete_http: [
        { cle: 'nom_entete', type: 'text', libelle: 'Nom de l\'en-tete', libelleEn: 'Header name', placeholder: 'X-Content-Type-Options', requis: true },
        { cle: 'operation', type: 'select', libelle: 'Operation', libelleEn: 'Operation', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'present', libelle: 'Present', libelleEn: 'Present' },
            { valeur: 'absent', libelle: 'Absent', libelleEn: 'Absent' },
            { valeur: 'egal', libelle: 'Egal a', libelleEn: 'Equals' },
            { valeur: 'contient', libelle: 'Contient', libelleEn: 'Contains' },
            { valeur: 'ne_contient_pas', libelle: 'Ne contient pas', libelleEn: 'Does not contain' },
            { valeur: 'regex', libelle: 'Expression reguliere', libelleEn: 'Regular expression' },
        ]},
        { cle: 'valeur_attendue', type: 'text', libelle: 'Valeur attendue', libelleEn: 'Expected value', placeholder: 'nosniff', dependDe: { champ: 'operation', valeurs: ['egal', 'contient', 'ne_contient_pas', 'regex'] } },
    ],

    performance: [
        { cle: 'metrique', type: 'select', libelle: 'Metrique', libelleEn: 'Metric', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'temps_total', libelle: 'Temps total', libelleEn: 'Total time' },
            { valeur: 'ttfb', libelle: 'TTFB', libelleEn: 'TTFB' },
            { valeur: 'dns', libelle: 'DNS', libelleEn: 'DNS' },
            { valeur: 'connexion', libelle: 'Connexion', libelleEn: 'Connection' },
            { valeur: 'ssl', libelle: 'SSL', libelleEn: 'SSL' },
            { valeur: 'taille', libelle: 'Taille', libelleEn: 'Size' },
        ]},
        { cle: 'seuil_max', type: 'number', libelle: 'Seuil maximum', libelleEn: 'Maximum threshold', placeholder: '800', requis: true },
        { cle: 'unite', type: 'select', libelle: 'Unite', libelleEn: 'Unit', defaut: 'ms', options: [
            { valeur: 'ms', libelle: 'ms', libelleEn: 'ms' },
            { valeur: 'octets', libelle: 'octets', libelleEn: 'bytes' },
        ]},
    ],

    ssl: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'validite', libelle: 'Validite', libelleEn: 'Validity' },
            { valeur: 'expiration', libelle: 'Expiration', libelleEn: 'Expiration' },
            { valeur: 'domaine', libelle: 'Domaine', libelleEn: 'Domain' },
            { valeur: 'auto_signe', libelle: 'Auto-signe', libelleEn: 'Self-signed' },
            { valeur: 'chaine_complete', libelle: 'Chaine complete', libelleEn: 'Full chain' },
        ]},
        { cle: 'jours_avant_expiration', type: 'number', libelle: 'Jours avant expiration', libelleEn: 'Days before expiration', defaut: 30, min: 1, max: 365, unite: 'jours', dependDe: { champ: 'verification', valeurs: ['expiration'] } },
    ],

    disponibilite: [
        { cle: 'pattern_maintenance', type: 'text', libelle: 'Pattern de maintenance', libelleEn: 'Maintenance pattern', placeholder: 'maintenance|en cours' },
        { cle: 'detecter_soft_404', type: 'checkbox', libelle: 'Detecter les soft 404', libelleEn: 'Detect soft 404' },
        { cle: 'pattern_erreur', type: 'text', libelle: 'Pattern d\'erreur', libelleEn: 'Error pattern', placeholder: 'erreur|error|500' },
        { cle: 'verifier_lang', type: 'checkbox', libelle: 'Verifier la langue', libelleEn: 'Check language' },
        { cle: 'lang_attendue', type: 'text', libelle: 'Langue attendue', libelleEn: 'Expected language', placeholder: 'fr', dependDe: { champ: 'verifier_lang', valeurs: [true] } },
    ],

    meta_seo: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'title', libelle: 'Title', libelleEn: 'Title' },
            { valeur: 'meta_description', libelle: 'Meta description', libelleEn: 'Meta description' },
            { valeur: 'meta_robots', libelle: 'Meta robots', libelleEn: 'Meta robots' },
            { valeur: 'canonical', libelle: 'Canonical', libelleEn: 'Canonical' },
            { valeur: 'viewport', libelle: 'Viewport', libelleEn: 'Viewport' },
            { valeur: 'charset', libelle: 'Charset', libelleEn: 'Charset' },
            { valeur: 'hreflang', libelle: 'Hreflang', libelleEn: 'Hreflang' },
            { valeur: 'meta_refresh', libelle: 'Meta refresh', libelleEn: 'Meta refresh' },
            { valeur: 'pagination', libelle: 'Pagination (rel next/prev)', libelleEn: 'Pagination (rel next/prev)' },
        ]},
        { cle: 'verifier_presence', type: 'checkbox', libelle: 'Verifier la presence', libelleEn: 'Check presence', defaut: true },
        { cle: 'longueur_min', type: 'number', libelle: 'Longueur minimale', libelleEn: 'Minimum length', min: 0, dependDe: { champ: 'verification', valeurs: ['title', 'meta_description'] } },
        { cle: 'longueur_max', type: 'number', libelle: 'Longueur maximale', libelleEn: 'Maximum length', min: 0, dependDe: { champ: 'verification', valeurs: ['title', 'meta_description'] } },
        { cle: 'contenu_attendu', type: 'text', libelle: 'Contenu attendu', libelleEn: 'Expected content', placeholder: 'mot-cle attendu' },
    ],

    open_graph: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'og_title', libelle: 'og:title', libelleEn: 'og:title' },
            { valeur: 'og_description', libelle: 'og:description', libelleEn: 'og:description' },
            { valeur: 'og_image', libelle: 'og:image', libelleEn: 'og:image' },
            { valeur: 'og_url', libelle: 'og:url', libelleEn: 'og:url' },
            { valeur: 'og_complet', libelle: 'OG complet', libelleEn: 'Complete OG' },
        ]},
        { cle: 'longueur_min', type: 'number', libelle: 'Longueur minimale', libelleEn: 'Minimum length', min: 0 },
        { cle: 'longueur_max', type: 'number', libelle: 'Longueur maximale', libelleEn: 'Maximum length', min: 0 },
        { cle: 'verifier_image_accessible', type: 'checkbox', libelle: 'Verifier que l\'image est accessible', libelleEn: 'Check image accessibility' },
    ],

    twitter_card: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'card_type', libelle: 'Type de carte', libelleEn: 'Card type' },
            { valeur: 'title', libelle: 'Title', libelleEn: 'Title' },
            { valeur: 'description', libelle: 'Description', libelleEn: 'Description' },
            { valeur: 'image', libelle: 'Image', libelleEn: 'Image' },
            { valeur: 'complet', libelle: 'Complet', libelleEn: 'Complete' },
        ]},
        { cle: 'longueur_max', type: 'number', libelle: 'Longueur maximale', libelleEn: 'Maximum length', min: 0 },
        { cle: 'contenu_attendu', type: 'text', libelle: 'Contenu attendu', libelleEn: 'Expected content', placeholder: 'summary_large_image' },
    ],

    structure_titres: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'h1_unique', libelle: 'H1 unique', libelleEn: 'Unique H1' },
            { valeur: 'hierarchie', libelle: 'Hierarchie', libelleEn: 'Hierarchy' },
            { valeur: 'comptage', libelle: 'Comptage', libelleEn: 'Count' },
            { valeur: 'contenu_h1', libelle: 'Contenu du H1', libelleEn: 'H1 content' },
        ]},
        { cle: 'contenu_attendu', type: 'text', libelle: 'Texte attendu dans le H1', libelleEn: 'Expected text in H1', placeholder: 'Texte attendu dans le H1', dependDe: { champ: 'verification', valeurs: ['contenu_h1'] } },
    ],

    donnees_structurees: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'presence_json_ld', libelle: 'Presence JSON-LD', libelleEn: 'JSON-LD presence' },
            { valeur: 'syntaxe_json', libelle: 'Syntaxe JSON', libelleEn: 'JSON syntax' },
            { valeur: 'type_schema', libelle: 'Type de schema', libelleEn: 'Schema type' },
            { valeur: 'champs_obligatoires', libelle: 'Champs obligatoires', libelleEn: 'Required fields' },
            { valeur: 'comptage', libelle: 'Comptage', libelleEn: 'Count' },
        ]},
        { cle: 'type_attendu', type: 'text', libelle: 'Type attendu', libelleEn: 'Expected type', placeholder: 'Product', dependDe: { champ: 'verification', valeurs: ['type_schema', 'champs_obligatoires'] } },
        { cle: 'champs', type: 'tags', libelle: 'Champs obligatoires', libelleEn: 'Required fields', placeholder: 'name, price, image', dependDe: { champ: 'verification', valeurs: ['champs_obligatoires'] } },
        { cle: 'nombre_min', type: 'number', libelle: 'Nombre minimum', libelleEn: 'Minimum count', min: 0, dependDe: { champ: 'verification', valeurs: ['comptage'] } },
        { cle: 'nombre_max', type: 'number', libelle: 'Nombre maximum', libelleEn: 'Maximum count', min: 0, dependDe: { champ: 'verification', valeurs: ['comptage'] } },
    ],

    liens_seo: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'comptage_internes', libelle: 'Comptage liens internes', libelleEn: 'Internal links count' },
            { valeur: 'comptage_externes', libelle: 'Comptage liens externes', libelleEn: 'External links count' },
            { valeur: 'nofollow', libelle: 'Liens nofollow', libelleEn: 'Nofollow links' },
            { valeur: 'ancres_vides', libelle: 'Ancres vides', libelleEn: 'Empty anchors' },
            { valeur: 'fil_ariane', libelle: 'Fil d\'Ariane', libelleEn: 'Breadcrumb' },
        ]},
        { cle: 'domaine_reference', type: 'text', libelle: 'Domaine de reference', libelleEn: 'Reference domain', placeholder: 'example.com' },
        { cle: 'nombre_min', type: 'number', libelle: 'Nombre minimum', libelleEn: 'Minimum count', min: 0 },
        { cle: 'nombre_max', type: 'number', libelle: 'Nombre maximum', libelleEn: 'Maximum count', min: 0 },
    ],

    images_seo: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'alt_manquant', libelle: 'Alt manquant', libelleEn: 'Missing alt' },
            { valeur: 'alt_vide', libelle: 'Alt vide', libelleEn: 'Empty alt' },
            { valeur: 'dimensions_manquantes', libelle: 'Dimensions manquantes', libelleEn: 'Missing dimensions' },
            { valeur: 'comptage', libelle: 'Comptage', libelleEn: 'Count' },
            { valeur: 'lazy_loading', libelle: 'Lazy loading', libelleEn: 'Lazy loading' },
        ]},
    ],

    performance_front: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'css_bloquants', libelle: 'CSS bloquants', libelleEn: 'Blocking CSS' },
            { valeur: 'js_bloquants', libelle: 'JS bloquants', libelleEn: 'Blocking JS' },
            { valeur: 'comptage_ressources', libelle: 'Comptage des ressources', libelleEn: 'Resource count' },
            { valeur: 'inline_excessif', libelle: 'Inline excessif', libelleEn: 'Excessive inline' },
            { valeur: 'preconnect', libelle: 'Preconnect', libelleEn: 'Preconnect' },
            { valeur: 'mixed_content', libelle: 'Mixed content', libelleEn: 'Mixed content' },
        ]},
        { cle: 'max_css', type: 'number', libelle: 'Maximum CSS bloquants', libelleEn: 'Max blocking CSS', min: 0, defaut: 3, dependDe: { champ: 'verification', valeurs: ['css_bloquants'] } },
        { cle: 'max_js', type: 'number', libelle: 'Maximum JS bloquants', libelleEn: 'Max blocking JS', min: 0, defaut: 5, dependDe: { champ: 'verification', valeurs: ['js_bloquants'] } },
        { cle: 'seuil_inline_octets', type: 'number', libelle: 'Seuil inline', libelleEn: 'Inline threshold', min: 0, defaut: 10000, unite: 'octets', dependDe: { champ: 'verification', valeurs: ['inline_excessif'] } },
    ],

    xpath: [
        { cle: 'expression', type: 'text', libelle: 'Expression XPath', libelleEn: 'XPath expression', placeholder: '//div[@class=\'content\']', requis: true },
        { cle: 'operation', type: 'select', libelle: 'Operation', libelleEn: 'Operation', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'existe', libelle: 'Existe', libelleEn: 'Exists' },
            { valeur: 'absent', libelle: 'Absent', libelleEn: 'Absent' },
            { valeur: 'compte_exact', libelle: 'Compte exact', libelleEn: 'Exact count' },
            { valeur: 'compte_min', libelle: 'Compte minimum', libelleEn: 'Minimum count' },
            { valeur: 'compte_max', libelle: 'Compte maximum', libelleEn: 'Maximum count' },
            { valeur: 'texte_egal', libelle: 'Texte egal', libelleEn: 'Text equals' },
            { valeur: 'texte_contient', libelle: 'Texte contient', libelleEn: 'Text contains' },
            { valeur: 'texte_regex', libelle: 'Texte regex', libelleEn: 'Text regex' },
            { valeur: 'attribut_egal', libelle: 'Attribut egal', libelleEn: 'Attribute equals' },
        ]},
        { cle: 'valeur_attendue', type: 'text', libelle: 'Valeur attendue', libelleEn: 'Expected value', dependDe: { champ: 'operation', valeurs: ['compte_exact', 'compte_min', 'compte_max', 'texte_egal', 'texte_contient', 'texte_regex', 'attribut_egal'] } },
        { cle: 'attribut', type: 'text', libelle: 'Attribut', libelleEn: 'Attribute', placeholder: 'class', dependDe: { champ: 'operation', valeurs: ['attribut_egal'] } },
    ],

    balise_html: [
        { cle: 'selecteur', type: 'text', libelle: 'Selecteur CSS', libelleEn: 'CSS selector', placeholder: 'h1.main-title', requis: true },
        { cle: 'operation', type: 'select', libelle: 'Operation', libelleEn: 'Operation', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'existe', libelle: 'Existe', libelleEn: 'Exists' },
            { valeur: 'absent', libelle: 'Absent', libelleEn: 'Absent' },
            { valeur: 'compte', libelle: 'Comptage', libelleEn: 'Count' },
            { valeur: 'contenu', libelle: 'Contenu', libelleEn: 'Content' },
            { valeur: 'attribut', libelle: 'Attribut', libelleEn: 'Attribute' },
        ]},
        { cle: 'valeur_attendue', type: 'text', libelle: 'Valeur attendue', libelleEn: 'Expected value' },
        { cle: 'nombre_min', type: 'number', libelle: 'Nombre minimum', libelleEn: 'Minimum count', min: 0, dependDe: { champ: 'operation', valeurs: ['compte'] } },
        { cle: 'nombre_max', type: 'number', libelle: 'Nombre maximum', libelleEn: 'Maximum count', min: 0, dependDe: { champ: 'operation', valeurs: ['compte'] } },
        { cle: 'attribut', type: 'text', libelle: 'Attribut', libelleEn: 'Attribute', placeholder: 'data-id', dependDe: { champ: 'operation', valeurs: ['attribut'] } },
    ],

    comptage_occurrences: [
        { cle: 'type_recherche', type: 'select', libelle: 'Type de recherche', libelleEn: 'Search type', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'texte', libelle: 'Texte', libelleEn: 'Text' },
            { valeur: 'regex', libelle: 'Expression reguliere', libelleEn: 'Regular expression' },
            { valeur: 'xpath', libelle: 'XPath', libelleEn: 'XPath' },
        ]},
        { cle: 'motif', type: 'text', libelle: 'Motif recherche', libelleEn: 'Search pattern', placeholder: 'mot-cle', requis: true },
        { cle: 'operateur', type: 'select', libelle: 'Operateur', libelleEn: 'Operator', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'egal', libelle: 'Egal a', libelleEn: 'Equals' },
            { valeur: 'superieur', libelle: 'Superieur a', libelleEn: 'Greater than' },
            { valeur: 'inferieur', libelle: 'Inferieur a', libelleEn: 'Less than' },
            { valeur: 'entre', libelle: 'Entre', libelleEn: 'Between' },
        ]},
        { cle: 'valeur_attendue', type: 'number', libelle: 'Valeur attendue', libelleEn: 'Expected value', dependDe: { champ: 'operateur', valeurs: ['egal', 'superieur', 'inferieur'] } },
        { cle: 'valeur_min', type: 'number', libelle: 'Valeur minimale', libelleEn: 'Minimum value', dependDe: { champ: 'operateur', valeurs: ['entre'] } },
        { cle: 'valeur_max', type: 'number', libelle: 'Valeur maximale', libelleEn: 'Maximum value', dependDe: { champ: 'operateur', valeurs: ['entre'] } },
    ],

    changement_contenu: [
        { cle: 'zone', type: 'select', libelle: 'Zone a surveiller', libelleEn: 'Zone to monitor', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'body', libelle: 'Body complet', libelleEn: 'Full body' },
            { valeur: 'head', libelle: 'Head', libelleEn: 'Head' },
            { valeur: 'xpath', libelle: 'Expression XPath', libelleEn: 'XPath expression' },
            { valeur: 'json_ld', libelle: 'JSON-LD', libelleEn: 'JSON-LD' },
            { valeur: 'texte_visible', libelle: 'Texte visible', libelleEn: 'Visible text' },
            { valeur: 'regex', libelle: 'Expression reguliere', libelleEn: 'Regular expression' },
        ]},
        { cle: 'expression_xpath', type: 'text', libelle: 'Expression XPath', libelleEn: 'XPath expression', placeholder: '//main', dependDe: { champ: 'zone', valeurs: ['xpath'] } },
        { cle: 'expression_regex', type: 'text', libelle: 'Expression reguliere', libelleEn: 'Regular expression', placeholder: 'prix:\\s*\\d+', dependDe: { champ: 'zone', valeurs: ['regex'] } },
        { cle: 'seuil_changement_pourcent', type: 'number', libelle: 'Seuil de changement', libelleEn: 'Change threshold', min: 0, max: 100, unite: '%' },
        { cle: 'alerter_si_identique_jours', type: 'number', libelle: 'Alerter si identique depuis', libelleEn: 'Alert if unchanged for', min: 1, unite: 'jours' },
    ],

    ressources_externes: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'presence_pattern', libelle: 'Presence d\'un pattern', libelleEn: 'Pattern presence' },
            { valeur: 'absence_pattern', libelle: 'Absence d\'un pattern', libelleEn: 'Pattern absence' },
            { valeur: 'scripts_inattendus', libelle: 'Scripts inattendus', libelleEn: 'Unexpected scripts' },
        ]},
        { cle: 'patterns', type: 'tags', libelle: 'Patterns', libelleEn: 'Patterns', placeholder: 'analytics.js, gtm.js' },
        { cle: 'patterns_exclus', type: 'tags', libelle: 'Patterns exclus', libelleEn: 'Excluded patterns', placeholder: 'cdn.example.com', dependDe: { champ: 'verification', valeurs: ['scripts_inattendus'] } },
    ],

    robots_txt: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'accessible', libelle: 'Accessible', libelleEn: 'Accessible' },
            { valeur: 'sitemap_present', libelle: 'Sitemap present', libelleEn: 'Sitemap present' },
            { valeur: 'disallow_total', libelle: 'Disallow total', libelleEn: 'Total disallow' },
            { valeur: 'urls_critiques', libelle: 'URLs critiques', libelleEn: 'Critical URLs' },
            { valeur: 'taille', libelle: 'Taille', libelleEn: 'Size' },
            { valeur: 'comparaison', libelle: 'Comparaison', libelleEn: 'Comparison' },
        ]},
        { cle: 'urls_critiques', type: 'tags', libelle: 'URLs critiques', libelleEn: 'Critical URLs', placeholder: '/page-importante, /produit', dependDe: { champ: 'verification', valeurs: ['urls_critiques'] } },
        { cle: 'taille_max_octets', type: 'number', libelle: 'Taille maximale', libelleEn: 'Maximum size', defaut: 512000, unite: 'octets', dependDe: { champ: 'verification', valeurs: ['taille'] } },
        { cle: 'contenu_reference', type: 'textarea', libelle: 'Contenu de reference', libelleEn: 'Reference content', dependDe: { champ: 'verification', valeurs: ['comparaison'] } },
    ],

    sitemap_xml: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'accessible', libelle: 'Accessible', libelleEn: 'Accessible' },
            { valeur: 'xml_valide', libelle: 'XML valide', libelleEn: 'Valid XML' },
            { valeur: 'comptage_urls', libelle: 'Comptage d\'URLs', libelleEn: 'URL count' },
            { valeur: 'lastmod_present', libelle: 'Lastmod present', libelleEn: 'Lastmod present' },
            { valeur: 'taille', libelle: 'Taille', libelleEn: 'Size' },
            { valeur: 'index', libelle: 'Index', libelleEn: 'Index' },
        ]},
        { cle: 'nombre_urls_min', type: 'number', libelle: 'Nombre d\'URLs minimum', libelleEn: 'Minimum URL count', min: 0, dependDe: { champ: 'verification', valeurs: ['comptage_urls'] } },
        { cle: 'nombre_urls_max', type: 'number', libelle: 'Nombre d\'URLs maximum', libelleEn: 'Maximum URL count', min: 0, dependDe: { champ: 'verification', valeurs: ['comptage_urls'] } },
        { cle: 'taille_max_octets', type: 'number', libelle: 'Taille maximale', libelleEn: 'Maximum size', defaut: 52428800, unite: 'octets', dependDe: { champ: 'verification', valeurs: ['taille'] } },
    ],

    security_txt: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'accessible', libelle: 'Accessible', libelleEn: 'Accessible' },
            { valeur: 'contact_present', libelle: 'Contact present', libelleEn: 'Contact present' },
            { valeur: 'expires_present', libelle: 'Expires present', libelleEn: 'Expires present' },
        ]},
    ],

    ads_txt: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'accessible', libelle: 'Accessible', libelleEn: 'Accessible' },
            { valeur: 'syntaxe_valide', libelle: 'Syntaxe valide', libelleEn: 'Valid syntax' },
        ]},
    ],

    favicon: [
        { cle: 'verification', type: 'select', libelle: 'Verification', libelleEn: 'Check', requis: true, options: [
            { valeur: '', libelle: '\u2014 Choisir \u2014', libelleEn: '\u2014 Choose \u2014' },
            { valeur: 'present', libelle: 'Present', libelleEn: 'Present' },
        ]},
    ],
};

// ---------------------------------------------------------------------------
// 2. CATEGORIES_REGLES — Groupement par optgroup
// ---------------------------------------------------------------------------

const CATEGORIES_REGLES = {
    technique: {
        libelle: 'Technique',
        libelleEn: 'Technical',
        types: ['code_http', 'en_tete_http', 'performance', 'ssl', 'disponibilite'],
    },
    seo: {
        libelle: 'SEO',
        libelleEn: 'SEO',
        types: ['meta_seo', 'open_graph', 'twitter_card', 'structure_titres', 'donnees_structurees', 'liens_seo', 'images_seo', 'performance_front'],
    },
    contenu: {
        libelle: 'Contenu',
        libelleEn: 'Content',
        types: ['xpath', 'balise_html', 'comptage_occurrences', 'changement_contenu', 'ressources_externes'],
    },
    fichiers: {
        libelle: 'Fichiers',
        libelleEn: 'Files',
        types: ['robots_txt', 'sitemap_xml', 'security_txt', 'ads_txt', 'favicon'],
    },
};

// ---------------------------------------------------------------------------
// 3. META_TYPES_REGLES — Libelles pour le dropdown
// ---------------------------------------------------------------------------

const META_TYPES_REGLES = {
    code_http:              { libelle: 'Code de reponse HTTP',            libelleEn: 'HTTP Response Code' },
    en_tete_http:           { libelle: 'En-tete HTTP',                    libelleEn: 'HTTP Header' },
    performance:            { libelle: 'Performance (temps de reponse)',  libelleEn: 'Performance (response time)' },
    ssl:                    { libelle: 'Certificat SSL',                  libelleEn: 'SSL Certificate' },
    disponibilite:          { libelle: 'Disponibilite',                   libelleEn: 'Availability' },
    meta_seo:               { libelle: 'Meta SEO (title, description...)', libelleEn: 'Meta SEO (title, description...)' },
    open_graph:             { libelle: 'Open Graph',                      libelleEn: 'Open Graph' },
    twitter_card:           { libelle: 'Twitter Card',                    libelleEn: 'Twitter Card' },
    structure_titres:       { libelle: 'Structure des titres (Hn)',       libelleEn: 'Heading Structure (Hn)' },
    donnees_structurees:    { libelle: 'Donnees structurees (JSON-LD)',   libelleEn: 'Structured Data (JSON-LD)' },
    liens_seo:              { libelle: 'Liens SEO (internes, externes)',  libelleEn: 'SEO Links (internal, external)' },
    images_seo:             { libelle: 'Images SEO (alt, dimensions)',    libelleEn: 'SEO Images (alt, dimensions)' },
    performance_front:      { libelle: 'Performance front-end',           libelleEn: 'Front-end Performance' },
    xpath:                  { libelle: 'Expression XPath',                libelleEn: 'XPath Expression' },
    balise_html:            { libelle: 'Selecteur HTML / CSS',            libelleEn: 'HTML / CSS Selector' },
    comptage_occurrences:   { libelle: 'Comptage d\'occurrences',         libelleEn: 'Occurrence Count' },
    changement_contenu:     { libelle: 'Detection de changement',         libelleEn: 'Change Detection' },
    ressources_externes:    { libelle: 'Ressources externes',             libelleEn: 'External Resources' },
    robots_txt:             { libelle: 'Robots.txt',                      libelleEn: 'Robots.txt' },
    sitemap_xml:            { libelle: 'Sitemap XML',                     libelleEn: 'Sitemap XML' },
    security_txt:           { libelle: 'Security.txt',                    libelleEn: 'Security.txt' },
    ads_txt:                { libelle: 'Ads.txt',                         libelleEn: 'Ads.txt' },
    favicon:                { libelle: 'Favicon',                         libelleEn: 'Favicon' },
};

// ---------------------------------------------------------------------------
// 4. PRESETS_REGLES — 13 presets rapides
// ---------------------------------------------------------------------------

const PRESETS_REGLES = [
    { nom: 'HTTP 200', type: 'code_http', severite: 'critique', config: { code_attendu: 200 } },
    { nom: 'TTFB < 800ms', type: 'performance', severite: 'avertissement', config: { metrique: 'ttfb', seuil_max: 800, unite: 'ms' } },
    { nom: 'Title present (30-60 car.)', type: 'meta_seo', severite: 'erreur', config: { verification: 'title', verifier_presence: true, longueur_min: 30, longueur_max: 60 } },
    { nom: 'Meta description (120-160 car.)', type: 'meta_seo', severite: 'erreur', config: { verification: 'meta_description', verifier_presence: true, longueur_min: 120, longueur_max: 160 } },
    { nom: 'Canonical presente', type: 'meta_seo', severite: 'erreur', config: { verification: 'canonical', verifier_presence: true } },
    { nom: 'H1 unique', type: 'structure_titres', severite: 'erreur', config: { verification: 'h1_unique' } },
    { nom: 'SSL valide', type: 'ssl', severite: 'critique', config: { verification: 'validite' } },
    { nom: 'SSL expire dans +30j', type: 'ssl', severite: 'erreur', config: { verification: 'expiration', jours_avant_expiration: 30 } },
    { nom: 'Images avec alt', type: 'images_seo', severite: 'avertissement', config: { verification: 'alt_manquant' } },
    { nom: 'Robots.txt accessible', type: 'robots_txt', severite: 'erreur', config: { verification: 'accessible' } },
    { nom: 'Sitemap XML accessible', type: 'sitemap_xml', severite: 'erreur', config: { verification: 'accessible' } },
    { nom: 'OG complet', type: 'open_graph', severite: 'avertissement', config: { verification: 'og_complet' } },
    { nom: 'JSON-LD present', type: 'donnees_structurees', severite: 'erreur', config: { verification: 'presence_json_ld' } },
];

// ---------------------------------------------------------------------------
// Variable de suivi du nommage automatique
// ---------------------------------------------------------------------------

let _nomAutoGenere = false;

// ---------------------------------------------------------------------------
// Utilitaires internes
// ---------------------------------------------------------------------------

/**
 * Retourne le libelle localise d'un objet {libelle, libelleEn}.
 * @param {object} obj
 * @returns {string}
 */
function _libelle(obj) {
    if (!obj) return '';
    const lang = (typeof langueActuelle !== 'undefined') ? langueActuelle : 'fr';
    if (lang === 'en' && obj.libelleEn) return obj.libelleEn;
    return obj.libelle || obj.libelleEn || '';
}

/**
 * Echappe le HTML si la fonction echapper() est disponible, sinon fallback simple.
 * @param {string} str
 * @returns {string}
 */
function _esc(str) {
    if (typeof echapper === 'function') return echapper(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ---------------------------------------------------------------------------
// 5. peupleSelectTypes() — Remplir le dropdown type avec optgroups
// ---------------------------------------------------------------------------

function peupleSelectTypes() {
    const select = document.getElementById('regleTypeRegle');
    if (!select) return;

    // Conserver la premiere option (placeholder)
    const placeholder = select.querySelector('option[value=""]');
    select.innerHTML = '';
    if (placeholder) {
        select.appendChild(placeholder);
    } else {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = t('modal.regle.choisirType', '\u2014 Choisir un type \u2014');
        select.appendChild(opt);
    }

    // Creer les optgroups par categorie
    for (const [, categorie] of Object.entries(CATEGORIES_REGLES)) {
        const optgroup = document.createElement('optgroup');
        optgroup.label = _libelle(categorie);

        for (const typeRegle of categorie.types) {
            const meta = META_TYPES_REGLES[typeRegle];
            if (!meta) continue;
            const option = document.createElement('option');
            option.value = typeRegle;
            option.textContent = _libelle(meta);
            optgroup.appendChild(option);
        }

        select.appendChild(optgroup);
    }
}

// ---------------------------------------------------------------------------
// 6. construireFormulaire() — Constructeur de formulaire dynamique
// ---------------------------------------------------------------------------

/**
 * Construit dynamiquement les champs de configuration pour un type de regle.
 * @param {string} typeRegle — Cle du type (ex: 'code_http')
 * @param {object|null} configExistante — Valeurs existantes a pre-remplir
 */
function construireFormulaire(typeRegle, configExistante) {
    const conteneur = document.getElementById('conteneurChampsDynamiques');
    if (!conteneur) return;

    conteneur.innerHTML = '';

    const schema = SCHEMAS_REGLES[typeRegle];
    if (!schema) {
        conteneur.innerHTML = `<div class="text-muted small fst-italic">${_esc(t('regle.config.type_non_reconnu', 'Type non reconnu. Utilisez le mode expert.'))}</div>`;
        // Afficher le textarea expert
        _afficherModeExpert(true);
        return;
    }

    _afficherModeExpert(false);

    // Map des wrappers pour gerer les dependances
    const wrappers = {};

    for (const champ of schema) {
        const wrapper = document.createElement('div');
        wrapper.className = 'champ-config-regle mb-2';
        wrapper.setAttribute('data-cfg-wrapper', champ.cle);

        const html = _genererChampHtml(champ, configExistante);
        wrapper.innerHTML = html;

        conteneur.appendChild(wrapper);
        wrappers[champ.cle] = wrapper;
    }

    // Initialiser les valeurs depuis configExistante
    if (configExistante) {
        _peupleChamps(configExistante);
    }

    // Initialiser les dependances conditionnelles
    _initialiserDependances(schema, wrappers);

    // Initialiser les champs tags
    _initialiserChampsTags(conteneur, configExistante);

    // Ajouter les listeners pour synchronisation vers le JSON expert
    _ajouterListenersSynchronisation();
}

/**
 * Genere le HTML pour un champ individuel.
 * @param {object} champ — Descripteur du champ
 * @param {object|null} configExistante
 * @returns {string}
 */
function _genererChampHtml(champ, configExistante) {
    const id = `cfg_${champ.cle}`;
    const libelle = _libelle(champ);
    const requis = champ.requis ? ' <span class="text-danger">*</span>' : '';
    const requiredAttr = champ.requis ? ' required' : '';
    const valeurExistante = configExistante ? configExistante[champ.cle] : undefined;

    switch (champ.type) {
        case 'text': {
            const val = valeurExistante !== undefined ? _esc(String(valeurExistante)) : '';
            const ph = champ.placeholder ? ` placeholder="${_esc(champ.placeholder)}"` : '';
            return `
                <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                <input type="text" class="form-control form-control-sm" id="${id}" data-cfg-cle="${champ.cle}" value="${val}"${ph}${requiredAttr}>
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        case 'number': {
            const val = valeurExistante !== undefined ? valeurExistante : (champ.defaut !== undefined ? champ.defaut : '');
            const minAttr = champ.min !== undefined ? ` min="${champ.min}"` : '';
            const maxAttr = champ.max !== undefined ? ` max="${champ.max}"` : '';
            const ph = champ.placeholder ? ` placeholder="${_esc(champ.placeholder)}"` : '';
            const inputHtml = `<input type="number" class="form-control form-control-sm" id="${id}" data-cfg-cle="${champ.cle}" value="${val !== '' ? val : ''}"${minAttr}${maxAttr}${ph}${requiredAttr}>`;

            if (champ.unite) {
                return `
                    <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                    <div class="input-group input-group-sm">
                        ${inputHtml}
                        <span class="input-group-text">${_esc(champ.unite)}</span>
                    </div>
                    ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
                `;
            }
            return `
                <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                ${inputHtml}
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        case 'checkbox': {
            const checked = valeurExistante !== undefined ? (valeurExistante ? ' checked' : '') : (champ.defaut ? ' checked' : '');
            return `
                <div class="form-check form-switch mt-1">
                    <input class="form-check-input" type="checkbox" id="${id}" data-cfg-cle="${champ.cle}"${checked}>
                    <label class="form-check-label small" for="${id}">${_esc(libelle)}${requis}</label>
                </div>
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        case 'select': {
            const valActuelle = valeurExistante !== undefined ? String(valeurExistante) : '';
            let optionsHtml = '';
            if (champ.options) {
                for (const opt of champ.options) {
                    const selected = opt.valeur === valActuelle ? ' selected' : '';
                    optionsHtml += `<option value="${_esc(opt.valeur)}"${selected}>${_esc(_libelle(opt))}</option>`;
                }
            }
            return `
                <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                <select class="form-select form-select-sm" id="${id}" data-cfg-cle="${champ.cle}"${requiredAttr}>
                    ${optionsHtml}
                </select>
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        case 'tags': {
            const ph = champ.placeholder ? ` placeholder="${_esc(champ.placeholder)}"` : '';
            return `
                <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                <div class="champ-tags" data-cfg-cle="${champ.cle}" data-cfg-tags="[]" id="${id}_conteneur">
                    <div class="d-flex flex-wrap gap-1 mb-1" id="${id}_badges"></div>
                    <input type="text" class="form-control form-control-sm" id="${id}"${ph} data-cfg-tags-input="${champ.cle}">
                    <div class="form-text small text-muted">${t('regle.config.tags_aide', 'Appuyez sur Entree ou virgule pour ajouter')}</div>
                </div>
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        case 'textarea': {
            const val = valeurExistante !== undefined ? _esc(String(valeurExistante)) : '';
            return `
                <label for="${id}" class="form-label small fw-semibold mb-1">${_esc(libelle)}${requis}</label>
                <textarea class="form-control form-control-sm" id="${id}" data-cfg-cle="${champ.cle}" rows="4"${requiredAttr}>${val}</textarea>
                ${champ.aide ? `<div class="form-text small text-muted">${_esc(champ.aide)}</div>` : ''}
            `;
        }

        default:
            return `<div class="text-muted small">Type de champ inconnu : ${_esc(champ.type)}</div>`;
    }
}

/**
 * Peuple les champs du formulaire avec les valeurs existantes.
 * @param {object} config
 */
function _peupleChamps(config) {
    if (!config || typeof config !== 'object') return;

    for (const [cle, valeur] of Object.entries(config)) {
        const el = document.querySelector(`[data-cfg-cle="${cle}"]`);
        if (!el) continue;

        if (el.type === 'checkbox') {
            el.checked = !!valeur;
        } else if (el.classList.contains('champ-tags')) {
            // Les tags sont geres separement dans _initialiserChampsTags
            continue;
        } else {
            el.value = valeur !== null && valeur !== undefined ? valeur : '';
        }
    }
}

/**
 * Initialise les dependances conditionnelles entre champs.
 * @param {Array} schema
 * @param {object} wrappers — Map cle -> element DOM wrapper
 */
function _initialiserDependances(schema, wrappers) {
    for (const champ of schema) {
        if (!champ.dependDe) continue;

        const champParent = document.querySelector(`[data-cfg-cle="${champ.dependDe.champ}"]`);
        const wrapper = wrappers[champ.cle];
        if (!champParent || !wrapper) continue;

        const valeursAutorisees = champ.dependDe.valeurs;

        // Fonction de mise a jour de la visibilite
        const mettreAJourVisibilite = () => {
            let valeurParent;
            if (champParent.type === 'checkbox') {
                valeurParent = champParent.checked;
            } else {
                valeurParent = champParent.value;
            }

            const visible = valeursAutorisees.includes(valeurParent);
            wrapper.classList.toggle('d-none', !visible);
        };

        // Ecouter les changements du parent
        champParent.addEventListener('change', mettreAJourVisibilite);
        champParent.addEventListener('input', mettreAJourVisibilite);

        // Etat initial
        mettreAJourVisibilite();
    }
}

/**
 * Initialise les champs de type tags (saisie par badges).
 * @param {HTMLElement} conteneur
 * @param {object|null} configExistante
 */
function _initialiserChampsTags(conteneur, configExistante) {
    const champsTags = conteneur.querySelectorAll('.champ-tags');

    for (const champTag of champsTags) {
        const cle = champTag.getAttribute('data-cfg-cle');
        const input = champTag.querySelector(`[data-cfg-tags-input="${cle}"]`);
        const conteneurBadges = champTag.querySelector(`[id$="_badges"]`);
        if (!input || !conteneurBadges) continue;

        // Valeurs initiales
        let valeurs = [];
        if (configExistante && Array.isArray(configExistante[cle])) {
            valeurs = [...configExistante[cle]];
        }

        // Stocker les valeurs dans le data attribute
        champTag.setAttribute('data-cfg-tags', JSON.stringify(valeurs));

        // Rendu initial des badges
        _rendreTagsBadges(conteneurBadges, valeurs, champTag);

        // Ecouter la saisie
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const texte = input.value.trim().replace(/,$/g, '');
                if (texte) {
                    _ajouterTag(champTag, conteneurBadges, texte);
                    input.value = '';
                    _synchroniserFormVersJson();
                }
            }
        });

        // Ajout sur blur aussi
        input.addEventListener('blur', () => {
            const texte = input.value.trim().replace(/,$/g, '');
            if (texte) {
                _ajouterTag(champTag, conteneurBadges, texte);
                input.value = '';
                _synchroniserFormVersJson();
            }
        });
    }
}

/**
 * Ajoute un tag a un champ tags.
 * @param {HTMLElement} champTag
 * @param {HTMLElement} conteneurBadges
 * @param {string} texte
 */
function _ajouterTag(champTag, conteneurBadges, texte) {
    let valeurs = JSON.parse(champTag.getAttribute('data-cfg-tags') || '[]');
    if (valeurs.includes(texte)) return; // Pas de doublons
    valeurs.push(texte);
    champTag.setAttribute('data-cfg-tags', JSON.stringify(valeurs));
    _rendreTagsBadges(conteneurBadges, valeurs, champTag);
}

/**
 * Supprime un tag d'un champ tags.
 * @param {HTMLElement} champTag
 * @param {HTMLElement} conteneurBadges
 * @param {number} index
 */
function _supprimerTag(champTag, conteneurBadges, index) {
    let valeurs = JSON.parse(champTag.getAttribute('data-cfg-tags') || '[]');
    valeurs.splice(index, 1);
    champTag.setAttribute('data-cfg-tags', JSON.stringify(valeurs));
    _rendreTagsBadges(conteneurBadges, valeurs, champTag);
    _synchroniserFormVersJson();
}

/**
 * Genere les badges HTML pour un champ tags.
 * @param {HTMLElement} conteneurBadges
 * @param {Array<string>} valeurs
 * @param {HTMLElement} champTag
 */
function _rendreTagsBadges(conteneurBadges, valeurs, champTag) {
    conteneurBadges.innerHTML = '';
    valeurs.forEach((val, idx) => {
        const badge = document.createElement('span');
        badge.className = 'badge bg-secondary d-inline-flex align-items-center gap-1';
        badge.innerHTML = `${_esc(val)} <button type="button" class="btn-close btn-close-white" style="font-size:0.55em;" aria-label="Supprimer" data-tag-index="${idx}"></button>`;
        badge.querySelector('button').addEventListener('click', () => {
            _supprimerTag(champTag, conteneurBadges, idx);
        });
        conteneurBadges.appendChild(badge);
    });
}

/**
 * Affiche ou masque le mode expert (textarea JSON brut).
 * @param {boolean} forcer — Forcer l'affichage
 */
function _afficherModeExpert(forcer) {
    const textarea = document.getElementById('regleConfiguration');
    const conteneur = document.getElementById('conteneurChampsDynamiques');
    const toggle = document.getElementById('modeExpertRegle');
    if (!textarea) return;

    if (forcer) {
        // Pas de schema : afficher uniquement le textarea
        textarea.closest('.mb-3')?.classList.remove('d-none');
        if (conteneur) conteneur.classList.add('d-none');
        if (toggle) toggle.closest('.form-check')?.classList.add('d-none');
    } else {
        // Schema disponible : masquer le textarea par defaut, afficher les champs
        const estExpert = toggle && toggle.checked;
        textarea.closest('.mb-3')?.classList.toggle('d-none', !estExpert);
        if (conteneur) conteneur.classList.remove('d-none');
        if (toggle) toggle.closest('.form-check')?.classList.remove('d-none');
    }
}

/**
 * Ajoute les listeners de synchronisation sur tous les champs dynamiques.
 */
function _ajouterListenersSynchronisation() {
    const conteneur = document.getElementById('conteneurChampsDynamiques');
    if (!conteneur) return;

    conteneur.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.hasAttribute('data-cfg-tags-input')) return; // Les tags ont leur propre listener
        el.addEventListener('change', _synchroniserFormVersJson);
        el.addEventListener('input', _debounce(_synchroniserFormVersJson, 300));
    });
}

/**
 * Synchronise le formulaire vers le textarea JSON (si visible).
 */
function _synchroniserFormVersJson() {
    const config = formulaireVersJson();
    const textarea = document.getElementById('regleConfiguration');
    if (textarea) {
        textarea.value = Object.keys(config).length > 0 ? JSON.stringify(config, null, 2) : '';
    }

    // Mettre a jour le nom automatique si applicable
    _mettreAJourNomAuto();
}

/**
 * Debounce simple.
 * @param {Function} fn
 * @param {number} delai
 * @returns {Function}
 */
function _debounce(fn, delai) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delai);
    };
}

// ---------------------------------------------------------------------------
// 7. formulaireVersJson() — Formulaire vers JSON
// ---------------------------------------------------------------------------

/**
 * Lit tous les champs dynamiques et construit un objet de configuration.
 * @returns {object}
 */
function formulaireVersJson() {
    const config = {};
    const conteneur = document.getElementById('conteneurChampsDynamiques');
    if (!conteneur) return config;

    // Champs standards (input, select, textarea)
    conteneur.querySelectorAll('[data-cfg-cle]').forEach(el => {
        const cle = el.getAttribute('data-cfg-cle');

        // Ignorer les champs masques par dependance
        const wrapper = el.closest('.champ-config-regle');
        if (wrapper && wrapper.classList.contains('d-none')) return;

        // Champ tags
        if (el.classList.contains('champ-tags')) {
            const valeurs = JSON.parse(el.getAttribute('data-cfg-tags') || '[]');
            if (valeurs.length > 0) {
                config[cle] = valeurs;
            }
            return;
        }

        // Checkbox
        if (el.type === 'checkbox') {
            if (el.checked) {
                config[cle] = true;
            }
            return;
        }

        // Number
        if (el.type === 'number') {
            const val = el.value.trim();
            if (val === '') return;
            const nombre = parseFloat(val);
            if (!isNaN(nombre)) {
                config[cle] = nombre;
            }
            return;
        }

        // Select
        if (el.tagName === 'SELECT') {
            const val = el.value;
            if (val !== '') {
                config[cle] = val;
            }
            return;
        }

        // Textarea
        if (el.tagName === 'TEXTAREA') {
            const val = el.value.trim();
            if (val !== '') {
                config[cle] = val;
            }
            return;
        }

        // Text input (par defaut)
        const val = el.value.trim();
        if (val !== '') {
            config[cle] = val;
        }
    });

    return config;
}

// ---------------------------------------------------------------------------
// 8. jsonVersFormulaire() — JSON vers formulaire
// ---------------------------------------------------------------------------

/**
 * Peuple le formulaire dynamique depuis un objet (ou une chaine JSON).
 * @param {object|string} config
 */
function jsonVersFormulaire(config) {
    if (typeof config === 'string') {
        try {
            config = JSON.parse(config);
        } catch {
            return;
        }
    }

    if (!config || typeof config !== 'object') return;

    const conteneur = document.getElementById('conteneurChampsDynamiques');
    if (!conteneur) return;

    for (const [cle, valeur] of Object.entries(config)) {
        // Champs tags
        const champTag = conteneur.querySelector(`.champ-tags[data-cfg-cle="${cle}"]`);
        if (champTag) {
            const valeursTag = Array.isArray(valeur) ? valeur : [valeur];
            champTag.setAttribute('data-cfg-tags', JSON.stringify(valeursTag));
            const conteneurBadges = champTag.querySelector(`[id$="_badges"]`);
            if (conteneurBadges) {
                _rendreTagsBadges(conteneurBadges, valeursTag, champTag);
            }
            continue;
        }

        // Champs standards
        const el = conteneur.querySelector(`[data-cfg-cle="${cle}"]`);
        if (!el) continue;

        if (el.type === 'checkbox') {
            el.checked = !!valeur;
        } else {
            el.value = valeur !== null && valeur !== undefined ? valeur : '';
        }
    }

    // Declencher les events change sur les selects pour mettre a jour les dependances
    conteneur.querySelectorAll('select[data-cfg-cle]').forEach(select => {
        select.dispatchEvent(new Event('change'));
    });

    // Declencher aussi sur les checkboxes
    conteneur.querySelectorAll('input[type="checkbox"][data-cfg-cle]').forEach(cb => {
        cb.dispatchEvent(new Event('change'));
    });
}

// ---------------------------------------------------------------------------
// 9. genererNomRegle() — Nommage automatique
// ---------------------------------------------------------------------------

/**
 * Genere un nom de regle depuis le type et la configuration actuelle.
 * @param {string} typeRegle
 * @returns {string}
 */
function genererNomRegle(typeRegle) {
    const meta = META_TYPES_REGLES[typeRegle];
    if (!meta) return '';

    const base = _libelle(meta);
    const config = formulaireVersJson();

    // Extraire les informations cles selon le type
    const parties = [base];

    switch (typeRegle) {
        case 'code_http':
            if (config.code_attendu) parties.push(String(config.code_attendu));
            if (config.plage_acceptee) parties.push(config.plage_acceptee);
            break;

        case 'en_tete_http':
            if (config.nom_entete) {
                let detail = config.nom_entete;
                if (config.operation && config.operation === 'egal' && config.valeur_attendue) {
                    detail += ' = ' + config.valeur_attendue;
                } else if (config.operation) {
                    detail += ' (' + config.operation + ')';
                }
                parties.push(detail);
            }
            break;

        case 'performance':
            if (config.metrique) {
                let detail = config.metrique.toUpperCase();
                if (config.seuil_max) {
                    detail += ' < ' + config.seuil_max + (config.unite || 'ms');
                }
                parties.push(detail);
            }
            break;

        case 'ssl':
            if (config.verification) {
                let detail = config.verification;
                if (config.verification === 'expiration' && config.jours_avant_expiration) {
                    detail += ' ' + config.jours_avant_expiration + 'j';
                }
                parties.push(detail);
            }
            break;

        case 'meta_seo':
            if (config.verification) {
                let detail = config.verification;
                if (config.longueur_min || config.longueur_max) {
                    const min = config.longueur_min || 0;
                    const max = config.longueur_max || '\u221E';
                    detail += ` (${min}-${max} car.)`;
                }
                parties.push(detail);
            }
            break;

        case 'open_graph':
        case 'twitter_card':
        case 'structure_titres':
        case 'images_seo':
        case 'performance_front':
        case 'security_txt':
        case 'ads_txt':
        case 'favicon':
            if (config.verification) parties.push(config.verification);
            break;

        case 'donnees_structurees':
            if (config.verification) {
                let detail = config.verification;
                if (config.type_attendu) detail += ' : ' + config.type_attendu;
                parties.push(detail);
            }
            break;

        case 'liens_seo':
            if (config.verification) parties.push(config.verification);
            break;

        case 'xpath':
            if (config.expression) {
                const expr = config.expression.length > 30 ? config.expression.substring(0, 30) + '...' : config.expression;
                parties.push(expr);
            }
            break;

        case 'balise_html':
            if (config.selecteur) {
                const sel = config.selecteur.length > 30 ? config.selecteur.substring(0, 30) + '...' : config.selecteur;
                parties.push(sel);
            }
            break;

        case 'comptage_occurrences':
            if (config.motif) parties.push('"' + config.motif + '"');
            break;

        case 'changement_contenu':
            if (config.zone) parties.push(config.zone);
            break;

        case 'ressources_externes':
            if (config.verification) parties.push(config.verification);
            break;

        case 'robots_txt':
            if (config.verification) parties.push(config.verification);
            break;

        case 'sitemap_xml':
            if (config.verification) parties.push(config.verification);
            break;
    }

    return parties.join(' \u2014 ');
}

/**
 * Met a jour le champ nom si le nommage automatique est actif.
 */
function _mettreAJourNomAuto() {
    const champNom = document.getElementById('regleNom');
    if (!champNom) return;

    // Ne pas ecraser si l'utilisateur a saisi manuellement
    if (!_nomAutoGenere && champNom.value.trim() !== '') return;

    const typeRegle = document.getElementById('regleTypeRegle')?.value;
    if (!typeRegle) return;

    const nomGenere = genererNomRegle(typeRegle);
    if (nomGenere) {
        champNom.value = nomGenere;
        _nomAutoGenere = true;
    }
}

// ---------------------------------------------------------------------------
// 10. appliquerPreset() — Appliquer un preset
// ---------------------------------------------------------------------------

/**
 * Applique un preset de regle.
 * @param {number} index — Index dans PRESETS_REGLES
 */
function appliquerPreset(index) {
    const preset = PRESETS_REGLES[index];
    if (!preset) return;

    const selectType = document.getElementById('regleTypeRegle');
    const selectSeverite = document.getElementById('regleSeverite');
    const champNom = document.getElementById('regleNom');

    // Definir le type
    if (selectType) {
        selectType.value = preset.type;
        // Construire le formulaire pour ce type
        construireFormulaire(preset.type, preset.config);
    }

    // Definir la severite
    if (selectSeverite) {
        selectSeverite.value = preset.severite;
    }

    // Definir le nom
    if (champNom) {
        champNom.value = preset.nom;
        _nomAutoGenere = true;
    }

    // Peupler la configuration dans les champs dynamiques
    if (preset.config) {
        jsonVersFormulaire(preset.config);
    }

    // Synchroniser vers le textarea JSON
    _synchroniserFormVersJson();
}

// ---------------------------------------------------------------------------
// 11. initReglesConfig() — Initialisation
// ---------------------------------------------------------------------------

/**
 * Initialise le systeme de configuration des regles.
 * Doit etre appele depuis DOMContentLoaded (dans app.js).
 */
function initReglesConfig() {
    // Injecter le conteneur de champs dynamiques et le toggle expert dans le modal
    _injecterElementsDom();

    // Remplir le dropdown des types avec optgroups
    peupleSelectTypes();

    // Listener sur le changement de type
    const selectType = document.getElementById('regleTypeRegle');
    if (selectType) {
        selectType.addEventListener('change', () => {
            const typeRegle = selectType.value;
            if (typeRegle) {
                construireFormulaire(typeRegle, null);
                _nomAutoGenere = true;
                _mettreAJourNomAuto();
            } else {
                // Aucun type selectionne : vider les champs dynamiques
                const conteneur = document.getElementById('conteneurChampsDynamiques');
                if (conteneur) conteneur.innerHTML = '';
                _afficherModeExpert(true);
            }
        });
    }

    // Listener sur le toggle mode expert
    const toggleExpert = document.getElementById('modeExpertRegle');
    if (toggleExpert) {
        toggleExpert.addEventListener('change', () => {
            const textarea = document.getElementById('regleConfiguration');
            const conteneur = document.getElementById('conteneurChampsDynamiques');
            if (!textarea) return;

            if (toggleExpert.checked) {
                // Mode expert : afficher le textarea, synchroniser depuis le formulaire
                textarea.closest('.mb-3')?.classList.remove('d-none');
                const config = formulaireVersJson();
                textarea.value = Object.keys(config).length > 0 ? JSON.stringify(config, null, 2) : '';
            } else {
                // Mode formulaire : masquer le textarea, synchroniser vers le formulaire
                textarea.closest('.mb-3')?.classList.add('d-none');
                try {
                    const config = JSON.parse(textarea.value || '{}');
                    jsonVersFormulaire(config);
                } catch {
                    // JSON invalide : on ne synchronise pas
                }
            }
        });
    }

    // Listener sur le champ nom pour detecter la saisie manuelle
    const champNom = document.getElementById('regleNom');
    if (champNom) {
        champNom.addEventListener('input', () => {
            // Si l'utilisateur modifie manuellement, desactiver le nommage auto
            _nomAutoGenere = false;
        });
        champNom.addEventListener('focus', () => {
            // Au focus, si le nom est auto-genere, selectionner tout pour faciliter l'edition
            if (_nomAutoGenere) {
                champNom.select();
            }
        });
    }

    // Construire le dropdown de presets
    _construireMenuPresets();

    // Ecouter la reinitialisation du formulaire (quand le modal s'ouvre pour creation)
    const modal = document.getElementById('modalRegle');
    if (modal) {
        modal.addEventListener('show.bs.modal', () => {
            _nomAutoGenere = false;
        });
    }
}

/**
 * Injecte les elements DOM necessaires dans le modal regle.
 * Ajoute le conteneur de champs dynamiques, le toggle expert et le dropdown de presets.
 */
function _injecterElementsDom() {
    const modalBody = document.querySelector('#formRegle .modal-body');
    if (!modalBody) return;

    // Trouver le bloc du textarea de configuration
    const blocConfiguration = document.getElementById('regleConfiguration')?.closest('.mb-3');
    if (!blocConfiguration) return;

    // 1. Ajouter le dropdown de presets avant le bloc type/severite
    const ligneTypeSeverite = modalBody.querySelector('.row.g-3');
    if (ligneTypeSeverite) {
        const divPresets = document.createElement('div');
        divPresets.className = 'mb-3';
        divPresets.id = 'blocPresetsRegle';
        divPresets.innerHTML = `
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" id="btnPresetsRegle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-lightning-charge me-1"></i>${t('regle.presets', 'Presets rapides')}
                </button>
                <ul class="dropdown-menu" id="menuPresetsRegle" aria-labelledby="btnPresetsRegle"></ul>
            </div>
        `;
        ligneTypeSeverite.parentNode.insertBefore(divPresets, ligneTypeSeverite);
    }

    // 2. Ajouter le conteneur de champs dynamiques avant le textarea
    const divChamps = document.createElement('div');
    divChamps.className = 'mb-3';
    divChamps.id = 'conteneurChampsDynamiques';
    blocConfiguration.parentNode.insertBefore(divChamps, blocConfiguration);

    // 3. Ajouter le toggle mode expert avant le textarea
    const divToggle = document.createElement('div');
    divToggle.className = 'form-check form-switch mb-2';
    divToggle.innerHTML = `
        <input class="form-check-input" type="checkbox" id="modeExpertRegle">
        <label class="form-check-label small text-muted" for="modeExpertRegle">
            <i class="bi bi-code-slash me-1"></i>${t('regle.mode_expert', 'Mode expert (JSON brut)')}
        </label>
    `;
    blocConfiguration.parentNode.insertBefore(divToggle, blocConfiguration);

    // 4. Masquer le textarea par defaut (mode formulaire actif)
    blocConfiguration.classList.add('d-none');
}

/**
 * Construit les items du menu dropdown de presets.
 */
function _construireMenuPresets() {
    const menu = document.getElementById('menuPresetsRegle');
    if (!menu) return;

    PRESETS_REGLES.forEach((preset, index) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.className = 'dropdown-item small';
        a.href = '#';
        a.textContent = preset.nom;
        a.addEventListener('click', (e) => {
            e.preventDefault();
            appliquerPreset(index);
        });
        li.appendChild(a);
        menu.appendChild(li);
    });
}

// ---------------------------------------------------------------------------
// 12. TEMPLATES_REGLES — Modeles fictifs couvrant les 23 types
// ---------------------------------------------------------------------------

const TEMPLATES_REGLES = [
    {
        nom: 'Audit SEO standard',
        nomEn: 'Standard SEO audit',
        description: '12 regles couvrant les fondamentaux SEO',
        regles: [
            { type: 'code_http', valeur: '200', severite: 'erreur' },
            { type: 'performance', valeur: 'ttfb | 800', severite: 'avertissement' },
            { type: 'ssl', valeur: 'validite', severite: 'critique' },
            { type: 'meta_seo', valeur: 'title | 30-60', severite: 'erreur' },
            { type: 'meta_seo', valeur: 'description | 120-160', severite: 'avertissement' },
            { type: 'meta_seo', valeur: 'canonical', severite: 'erreur' },
            { type: 'structure_titres', valeur: 'h1_unique', severite: 'erreur' },
            { type: 'open_graph', valeur: 'og_complet', severite: 'avertissement' },
            { type: 'images_seo', valeur: 'alt_manquant', severite: 'avertissement' },
            { type: 'robots_txt', valeur: 'accessible', severite: 'erreur' },
            { type: 'sitemap_xml', valeur: 'accessible', severite: 'erreur' },
            { type: 'donnees_structurees', valeur: 'presence_json_ld', severite: 'avertissement' },
        ]
    },
    {
        nom: 'Securite & infrastructure',
        nomEn: 'Security & infrastructure',
        description: '8 regles securite et infrastructure',
        regles: [
            { type: 'code_http', valeur: '200', severite: 'erreur' },
            { type: 'ssl', valeur: 'validite', severite: 'critique' },
            { type: 'ssl', valeur: 'expiration | 30', severite: 'avertissement' },
            { type: 'en_tete_http', valeur: 'X-Frame-Options | present', severite: 'erreur' },
            { type: 'en_tete_http', valeur: 'Content-Security-Policy | present', severite: 'avertissement' },
            { type: 'en_tete_http', valeur: 'X-Content-Type-Options | egal | nosniff', severite: 'erreur' },
            { type: 'security_txt', valeur: 'accessible', severite: 'info' },
            { type: 'disponibilite', valeur: 'soft_404', severite: 'erreur' },
        ]
    },
    {
        nom: 'Contenu & changements',
        nomEn: 'Content & changes',
        description: '7 regles suivi de contenu et changements',
        regles: [
            { type: 'changement_contenu', valeur: 'body | 20', severite: 'avertissement' },
            { type: 'changement_contenu', valeur: '//main | 10', severite: 'erreur' },
            { type: 'comptage_occurrences', valeur: 'texte | mot-cle | superieur | 3', severite: 'info' },
            { type: 'balise_html', valeur: '.breadcrumb | existe', severite: 'avertissement' },
            { type: 'xpath', valeur: '//h1 | existe', severite: 'erreur' },
            { type: 'ressources_externes', valeur: 'presence_pattern | analytics.js,gtm.js', severite: 'avertissement' },
            { type: 'liens_seo', valeur: 'comptage_internes | 5-100', severite: 'info' },
        ]
    },
    {
        nom: 'E-commerce complet',
        nomEn: 'Full e-commerce',
        description: '10 regles pour sites e-commerce',
        regles: [
            { type: 'donnees_structurees', valeur: 'type_schema | Product', severite: 'erreur' },
            { type: 'donnees_structurees', valeur: 'champs_obligatoires | Product | name,price,image', severite: 'erreur' },
            { type: 'meta_seo', valeur: 'title | 30-70', severite: 'erreur' },
            { type: 'open_graph', valeur: 'og_image', severite: 'avertissement' },
            { type: 'performance', valeur: 'temps_total | 3000', severite: 'erreur' },
            { type: 'balise_html', valeur: '.product-price | existe', severite: 'erreur' },
            { type: 'ads_txt', valeur: 'accessible', severite: 'info' },
            { type: 'favicon', valeur: 'present', severite: 'avertissement' },
            { type: 'twitter_card', valeur: 'card_type | summary_large_image', severite: 'info' },
            { type: 'performance_front', valeur: 'css_bloquants | 3', severite: 'avertissement' },
        ]
    },
    {
        nom: 'Fichiers techniques',
        nomEn: 'Technical files',
        description: '6 regles fichiers techniques (robots, sitemap, favicon)',
        regles: [
            { type: 'robots_txt', valeur: 'accessible', severite: 'erreur' },
            { type: 'robots_txt', valeur: 'sitemap_present', severite: 'avertissement' },
            { type: 'robots_txt', valeur: 'urls_critiques | /page,/produit', severite: 'info' },
            { type: 'sitemap_xml', valeur: 'accessible', severite: 'erreur' },
            { type: 'sitemap_xml', valeur: 'comptage_urls | 100-50000', severite: 'info' },
            { type: 'favicon', valeur: 'present', severite: 'avertissement' },
        ]
    },
];

// ---------------------------------------------------------------------------
// 13. Exports globaux pour app.js
// ---------------------------------------------------------------------------

window.initReglesConfig = initReglesConfig;
window.construireFormulaire = construireFormulaire;
window.formulaireVersJson = formulaireVersJson;
window.jsonVersFormulaire = jsonVersFormulaire;
window.appliquerPreset = appliquerPreset;
window.genererNomRegle = genererNomRegle;
window.peupleSelectTypes = peupleSelectTypes;
window.SCHEMAS_REGLES = SCHEMAS_REGLES;
window.CATEGORIES_REGLES = CATEGORIES_REGLES;
window.META_TYPES_REGLES = META_TYPES_REGLES;
window.PRESETS_REGLES = PRESETS_REGLES;
window.TEMPLATES_REGLES = TEMPLATES_REGLES;
