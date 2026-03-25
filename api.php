<?php

declare(strict_types=1);

/**
 * API AJAX unique — Dispatche les actions CRUD vers les depots.
 *
 * Toutes les operations CRUD passent par ce point d'entree.
 * Format : POST avec champs 'entite' et 'action', ou GET avec query params.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\TypeRegle;
use SiteMonitor\Core\Utilisateur;
use SiteMonitor\Entite\Client;
use SiteMonitor\Entite\Execution;
use SiteMonitor\Entite\GroupeUrls;
use SiteMonitor\Entite\Modele;
use SiteMonitor\Entite\Regle;
use SiteMonitor\Entite\Url;
use SiteMonitor\Core\StatutExecution;
use SiteMonitor\Stockage\DepotAlerte;
use SiteMonitor\Stockage\DepotAuditIndexation;
use SiteMonitor\Stockage\DepotClient;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotGroupeUrls;
use SiteMonitor\Stockage\DepotMetriqueHttp;
use SiteMonitor\Stockage\DepotModele;
use SiteMonitor\Stockage\DepotRegle;
use SiteMonitor\Stockage\DepotResultatIndexation;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotSnapshot;
use SiteMonitor\Stockage\DepotUrl;
use SiteMonitor\Moteur\RegistreTemplates;

try {
    $db = Connexion::obtenir();

    // S'assurer que les tables existent
    try {
        (new Migrateur($db))->migrer();
    } catch (\Throwable $eMigration) {
        // Ne pas bloquer l'API si une migration echoue (index trop grand, etc.)
        error_log('[Site Monitor] Migration non-bloquante : ' . $eMigration->getMessage());
    }

    $utilisateurId = Utilisateur::idCourant();

    $entite = $_POST['entite'] ?? $_GET['entite'] ?? '';
    $action = $_POST['action'] ?? $_GET['action'] ?? 'lister';

    $reponse = match ($entite) {
        'client' => gererClients($db, $action, $utilisateurId),
        'groupe' => gererGroupes($db, $action),
        'url' => gererUrls($db, $action),
        'modele' => gererModeles($db, $action),
        'regle' => gererRegles($db, $action),
        'execution' => gererExecutions($db, $action),
        'resultat' => gererResultats($db, $action),
        'alerte' => gererAlertes($db, $action),
        'snapshot' => gererSnapshots($db, $action),
        'metrique' => gererMetriques($db, $action),
        'dashboard' => genererDashboard($db, $action, $utilisateurId),
        'indexation' => gererIndexation($db, $action, $utilisateurId),
        'types_regles' => listerTypesRegles(),
        default => ['erreur' => "Entite inconnue : {$entite}"],
    };

    if (isset($reponse['erreur'])) {
        http_response_code(400);
    }

    echo json_encode($reponse, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// === CLIENTS ===

function gererClients(\PDO $db, string $action, ?int $utilisateurId): array
{
    $depot = new DepotClient($db);

    return match ($action) {
        'lister' => ['donnees' => $depot->statistiques($utilisateurId)],
        'obtenir' => obtenirClient($depot),
        'creer' => creerClient($depot, $utilisateurId),
        'modifier' => modifierClient($depot),
        'supprimer' => supprimerClient($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function obtenirClient(DepotClient $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $client = $depot->trouverParId($id);
    return $client ? ['donnees' => $client->versTableau()] : ['erreur' => 'Client introuvable'];
}

function creerClient(DepotClient $depot, ?int $utilisateurId): array
{
    $nom = trim($_POST['nom'] ?? '');
    $domaine = trim($_POST['domaine'] ?? '');
    if ($nom === '' || $domaine === '') {
        return ['erreur' => 'Nom et domaine requis'];
    }

    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($nom));
    $slug = preg_replace('/-+/', '-', trim($slug, '-'));

    $client = new Client(
        id: null,
        nom: $nom,
        slug: $slug,
        domaine: $domaine,
        emailContact: trim($_POST['email_contact'] ?? '') ?: null,
        actif: (bool) ($_POST['actif'] ?? true),
        configuration: null,
        utilisateurId: $utilisateurId,
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($client);
    return ['donnees' => ['id' => $id], 'message' => 'Client cree'];
}

function modifierClient(DepotClient $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existant = $depot->trouverParId($id);
    if (!$existant) {
        return ['erreur' => 'Client introuvable'];
    }

    $client = new Client(
        id: $id,
        nom: trim($_POST['nom'] ?? $existant->nom),
        slug: $existant->slug,
        domaine: trim($_POST['domaine'] ?? $existant->domaine),
        emailContact: trim($_POST['email_contact'] ?? $existant->emailContact ?? '') ?: null,
        actif: isset($_POST['actif']) ? (bool) $_POST['actif'] : $existant->actif,
        configuration: $existant->configuration,
        utilisateurId: $existant->utilisateurId,
        creeLe: $existant->creeLe,
        modifieLe: null,
    );

    $depot->modifier($client);
    return ['message' => 'Client modifie'];
}

function supprimerClient(DepotClient $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'Client supprime'];
}

// === GROUPES ===

function gererGroupes(\PDO $db, string $action): array
{
    $depot = new DepotGroupeUrls($db);

    return match ($action) {
        'lister' => listerGroupes($depot),
        'obtenir' => obtenirGroupe($depot),
        'creer' => creerGroupe($depot),
        'modifier' => modifierGroupe($depot),
        'supprimer' => supprimerGroupe($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerGroupes(DepotGroupeUrls $depot): array
{
    $clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }
    return ['donnees' => array_map(fn(GroupeUrls $g) => $g->versTableau(), $depot->trouverParClient($clientId))];
}

function obtenirGroupe(DepotGroupeUrls $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $groupe = $depot->trouverParId($id);
    return $groupe ? ['donnees' => $groupe->versTableau()] : ['erreur' => 'Groupe introuvable'];
}

function creerGroupe(DepotGroupeUrls $depot): array
{
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($clientId <= 0 || $nom === '') {
        return ['erreur' => 'client_id et nom requis'];
    }

    $groupe = new GroupeUrls(
        id: null,
        clientId: $clientId,
        nom: $nom,
        description: trim($_POST['description'] ?? '') ?: null,
        ordreTri: (int) ($_POST['ordre_tri'] ?? 0),
        actif: (bool) ($_POST['actif'] ?? true),
        planification: null,
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($groupe);
    return ['donnees' => ['id' => $id], 'message' => 'Groupe cree'];
}

function modifierGroupe(DepotGroupeUrls $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existant = $depot->trouverParId($id);
    if (!$existant) {
        return ['erreur' => 'Groupe introuvable'];
    }

    $groupe = new GroupeUrls(
        id: $id,
        clientId: $existant->clientId,
        nom: trim($_POST['nom'] ?? $existant->nom),
        description: trim($_POST['description'] ?? $existant->description ?? '') ?: null,
        ordreTri: (int) ($_POST['ordre_tri'] ?? $existant->ordreTri),
        actif: isset($_POST['actif']) ? (bool) $_POST['actif'] : $existant->actif,
        planification: $existant->planification,
        creeLe: $existant->creeLe,
        modifieLe: null,
    );

    $depot->modifier($groupe);
    return ['message' => 'Groupe modifie'];
}

function supprimerGroupe(DepotGroupeUrls $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'Groupe supprime'];
}

// === URLS ===

function gererUrls(\PDO $db, string $action): array
{
    $depot = new DepotUrl($db);

    return match ($action) {
        'lister' => listerUrls($depot),
        'obtenir' => obtenirUrl($depot),
        'creer' => creerUrl($depot),
        'creer_lot' => creerUrlsEnLot($depot),
        'modifier' => modifierUrl($depot),
        'supprimer' => supprimerUrl($depot),
        'associer_modele' => associerModeleUrl($depot),
        'dissocier_modele' => dissocierModeleUrl($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerUrls(DepotUrl $depot): array
{
    $groupeId = (int) ($_POST['groupe_id'] ?? $_GET['groupe_id'] ?? 0);
    if ($groupeId <= 0) {
        return ['erreur' => 'groupe_id requis'];
    }
    $urls = $depot->trouverParGroupe($groupeId);
    $donnees = [];
    foreach ($urls as $url) {
        $tableau = $url->versTableau();
        $tableau['modeles'] = $depot->modelesAssocies($url->id);
        $donnees[] = $tableau;
    }
    return ['donnees' => $donnees];
}

function obtenirUrl(DepotUrl $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $url = $depot->trouverParId($id);
    if (!$url) {
        return ['erreur' => 'URL introuvable'];
    }
    $donnees = $url->versTableau();
    $donnees['modeles'] = $depot->modelesAssocies($url->id);
    return ['donnees' => $donnees];
}

function creerUrl(DepotUrl $depot): array
{
    $groupeId = (int) ($_POST['groupe_id'] ?? 0);
    $urlStr = trim($_POST['url'] ?? '');
    if ($groupeId <= 0 || $urlStr === '') {
        return ['erreur' => 'groupe_id et url requis'];
    }

    $url = new Url(
        id: null,
        groupeId: $groupeId,
        url: $urlStr,
        libelle: trim($_POST['libelle'] ?? '') ?: null,
        actif: (bool) ($_POST['actif'] ?? true),
        derniereVerification: null,
        dernierStatut: null,
        notes: trim($_POST['notes'] ?? '') ?: null,
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($url);
    return ['donnees' => ['id' => $id], 'message' => 'URL creee'];
}

function creerUrlsEnLot(DepotUrl $depot): array
{
    $groupeId = (int) ($_POST['groupe_id'] ?? 0);
    $urlsTexte = trim($_POST['urls'] ?? '');
    if ($groupeId <= 0 || $urlsTexte === '') {
        return ['erreur' => 'groupe_id et urls requis'];
    }

    $lignes = array_filter(array_map('trim', explode("\n", $urlsTexte)), fn(string $l): bool => $l !== '');
    $dejaVues = [];
    $creees = 0;
    $ignorees = 0;

    foreach ($lignes as $ligne) {
        // Normaliser : retirer les espaces, tabulations
        $urlStr = preg_replace('/\s+/', '', $ligne);
        if ($urlStr === '' || isset($dejaVues[$urlStr])) {
            $ignorees++;
            continue;
        }
        $dejaVues[$urlStr] = true;

        $url = new Url(
            id: null,
            groupeId: $groupeId,
            url: $urlStr,
            libelle: null,
            actif: true,
            derniereVerification: null,
            dernierStatut: null,
            notes: null,
            creeLe: null,
            modifieLe: null,
        );
        $depot->creer($url);
        $creees++;
    }

    return [
        'donnees' => ['creees' => $creees, 'ignorees' => $ignorees],
        'message' => "{$creees} URL(s) ajoutee(s)",
    ];
}

function modifierUrl(DepotUrl $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existante = $depot->trouverParId($id);
    if (!$existante) {
        return ['erreur' => 'URL introuvable'];
    }

    $url = new Url(
        id: $id,
        groupeId: $existante->groupeId,
        url: trim($_POST['url'] ?? $existante->url),
        libelle: trim($_POST['libelle'] ?? $existante->libelle ?? '') ?: null,
        actif: isset($_POST['actif']) ? (bool) $_POST['actif'] : $existante->actif,
        derniereVerification: $existante->derniereVerification,
        dernierStatut: $existante->dernierStatut,
        notes: trim($_POST['notes'] ?? $existante->notes ?? '') ?: null,
        creeLe: $existante->creeLe,
        modifieLe: null,
    );

    $depot->modifier($url);
    return ['message' => 'URL modifiee'];
}

function supprimerUrl(DepotUrl $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'URL supprimee'];
}

function associerModeleUrl(DepotUrl $depot): array
{
    $urlId = (int) ($_POST['url_id'] ?? 0);
    $modeleId = (int) ($_POST['modele_id'] ?? 0);
    if ($urlId <= 0 || $modeleId <= 0) {
        return ['erreur' => 'url_id et modele_id requis'];
    }
    $depot->associerModele($urlId, $modeleId);
    return ['message' => 'Modele associe'];
}

function dissocierModeleUrl(DepotUrl $depot): array
{
    $urlId = (int) ($_POST['url_id'] ?? 0);
    $modeleId = (int) ($_POST['modele_id'] ?? 0);
    if ($urlId <= 0 || $modeleId <= 0) {
        return ['erreur' => 'url_id et modele_id requis'];
    }
    $depot->dissocierModele($urlId, $modeleId);
    return ['message' => 'Modele dissocie'];
}

// === MODELES ===

function gererModeles(\PDO $db, string $action): array
{
    $depot = new DepotModele($db);

    return match ($action) {
        'lister' => ['donnees' => $depot->statistiques()],
        'obtenir' => obtenirModele($depot),
        'creer' => creerModele($db, $depot),
        'modifier' => modifierModele($depot),
        'supprimer' => supprimerModele($depot),
        'templates' => ['donnees' => RegistreTemplates::lister()],
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function obtenirModele(DepotModele $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $modele = $depot->trouverParId($id);
    return $modele ? ['donnees' => $modele->versTableau()] : ['erreur' => 'Modele introuvable'];
}

function creerModele(\PDO $db, DepotModele $depot): array
{
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') {
        return ['erreur' => 'Nom requis'];
    }

    $modele = new Modele(
        id: null,
        clientId: ($_POST['client_id'] ?? '') !== '' ? (int) $_POST['client_id'] : null,
        nom: $nom,
        description: trim($_POST['description'] ?? '') ?: null,
        estGlobal: (bool) ($_POST['est_global'] ?? false),
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($modele);

    // Appliquer un template si demande
    $reglesCreees = 0;
    $templateCle = trim($_POST['template'] ?? '');
    if ($templateCle !== '') {
        $reglesTemplate = RegistreTemplates::obtenir($templateCle);
        if ($reglesTemplate !== null) {
            $depotRegle = new DepotRegle($db);
            $ordre = 1;
            foreach ($reglesTemplate as $def) {
                $regle = new Regle(
                    id: null,
                    modeleId: $id,
                    typeRegle: $def['type_regle'],
                    nom: $def['nom'],
                    configuration: $def['configuration'],
                    severite: $def['severite'],
                    ordreTri: $ordre++,
                    actif: true,
                    creeLe: null,
                    modifieLe: null,
                );
                $depotRegle->creer($regle);
                $reglesCreees++;
            }
        }
    }

    return ['donnees' => ['id' => $id, 'regles_creees' => $reglesCreees], 'message' => 'Modele cree'];
}

function modifierModele(DepotModele $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existant = $depot->trouverParId($id);
    if (!$existant) {
        return ['erreur' => 'Modele introuvable'];
    }

    $modele = new Modele(
        id: $id,
        clientId: isset($_POST['client_id']) ? ((int) $_POST['client_id'] ?: null) : $existant->clientId,
        nom: trim($_POST['nom'] ?? $existant->nom),
        description: trim($_POST['description'] ?? $existant->description ?? '') ?: null,
        estGlobal: isset($_POST['est_global']) ? (bool) $_POST['est_global'] : $existant->estGlobal,
        creeLe: $existant->creeLe,
        modifieLe: null,
    );

    $depot->modifier($modele);
    return ['message' => 'Modele modifie'];
}

function supprimerModele(DepotModele $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'Modele supprime'];
}

// === REGLES ===

function gererRegles(\PDO $db, string $action): array
{
    $depot = new DepotRegle($db);

    return match ($action) {
        'lister' => listerRegles($depot),
        'obtenir' => obtenirRegle($depot),
        'creer' => creerRegle($depot),
        'modifier' => modifierRegle($depot),
        'supprimer' => supprimerRegle($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerRegles(DepotRegle $depot): array
{
    $modeleId = (int) ($_POST['modele_id'] ?? $_GET['modele_id'] ?? 0);
    if ($modeleId <= 0) {
        return ['erreur' => 'modele_id requis'];
    }
    return ['donnees' => array_map(fn(Regle $r) => $r->versTableau(), $depot->trouverParModele($modeleId))];
}

function obtenirRegle(DepotRegle $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $regle = $depot->trouverParId($id);
    return $regle ? ['donnees' => $regle->versTableau()] : ['erreur' => 'Regle introuvable'];
}

function creerRegle(DepotRegle $depot): array
{
    $modeleId = (int) ($_POST['modele_id'] ?? 0);
    $typeRegle = $_POST['type_regle'] ?? '';
    $nom = trim($_POST['nom'] ?? '');

    if ($modeleId <= 0 || $typeRegle === '' || $nom === '') {
        return ['erreur' => 'modele_id, type_regle et nom requis'];
    }

    $typeEnum = TypeRegle::tryFrom($typeRegle);
    if ($typeEnum === null) {
        return ['erreur' => "Type de regle inconnu : {$typeRegle}"];
    }

    $config = isset($_POST['configuration_json'])
        ? json_decode($_POST['configuration_json'], true) ?? []
        : [];

    $regle = new Regle(
        id: null,
        modeleId: $modeleId,
        typeRegle: $typeEnum,
        nom: $nom,
        configuration: $config,
        severite: NiveauSeverite::tryFrom($_POST['severite'] ?? 'erreur') ?? NiveauSeverite::Erreur,
        ordreTri: (int) ($_POST['ordre_tri'] ?? 0),
        actif: (bool) ($_POST['actif'] ?? true),
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($regle);
    return ['donnees' => ['id' => $id], 'message' => 'Regle creee'];
}

function modifierRegle(DepotRegle $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existante = $depot->trouverParId($id);
    if (!$existante) {
        return ['erreur' => 'Regle introuvable'];
    }

    $config = isset($_POST['configuration_json'])
        ? json_decode($_POST['configuration_json'], true) ?? $existante->configuration
        : $existante->configuration;

    $regle = new Regle(
        id: $id,
        modeleId: $existante->modeleId,
        typeRegle: TypeRegle::tryFrom($_POST['type_regle'] ?? $existante->typeRegle->value) ?? $existante->typeRegle,
        nom: trim($_POST['nom'] ?? $existante->nom),
        configuration: $config,
        severite: NiveauSeverite::tryFrom($_POST['severite'] ?? $existante->severite->value) ?? $existante->severite,
        ordreTri: (int) ($_POST['ordre_tri'] ?? $existante->ordreTri),
        actif: isset($_POST['actif']) ? (bool) $_POST['actif'] : $existante->actif,
        creeLe: $existante->creeLe,
        modifieLe: null,
    );

    $depot->modifier($regle);
    return ['message' => 'Regle modifiee'];
}

function supprimerRegle(DepotRegle $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'Regle supprimee'];
}

// === EXECUTIONS ===

function gererExecutions(\PDO $db, string $action): array
{
    $depot = new DepotExecution($db);

    return match ($action) {
        'lister' => listerExecutions($db, $depot),
        'obtenir' => obtenirExecution($depot),
        'lancer' => lancerExecution($db, $depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerExecutions(\PDO $db, DepotExecution $depot): array
{
    $clientId = isset($_POST['client_id']) ? (int) $_POST['client_id'] : null;
    $statut = trim($_POST['statut'] ?? $_GET['statut'] ?? '');
    $limite = (int) ($_POST['limite'] ?? $_GET['limite'] ?? 50);

    $executions = $clientId
        ? $depot->trouverParClient($clientId, $limite)
        : $depot->trouverRecentes($limite);

    // Enrichir avec le nom du client
    $depotClient = new DepotClient($db);
    $cacheNoms = [];
    $donnees = [];
    foreach ($executions as $e) {
        $tab = $e->versTableau();
        if ($e->clientId) {
            if (!isset($cacheNoms[$e->clientId])) {
                $c = $depotClient->trouverParId($e->clientId);
                $cacheNoms[$e->clientId] = $c?->nom ?? '';
            }
            $tab['client_nom'] = $cacheNoms[$e->clientId];
        }
        // Filtrer par statut si demande
        if ($statut !== '' && $tab['statut'] !== $statut) {
            continue;
        }
        $donnees[] = $tab;
    }

    return ['donnees' => $donnees];
}

function obtenirExecution(DepotExecution $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $execution = $depot->trouverParId($id);
    return $execution ? ['donnees' => $execution->versTableau()] : ['erreur' => 'Execution introuvable'];
}

function lancerExecution(\PDO $db, DepotExecution $depot): array
{
    // Vérification quota
    if (class_exists('\\Platform\\Module\\Quota')) {
        if (!\Platform\Module\Quota::creditsDisponibles('site-monitor')) {
            http_response_code(429);
            return ['erreur' => 'Crédits épuisés'];
        }
    }

    $clientId = (int) ($_POST['client_id'] ?? 0);
    $groupeId = isset($_POST['groupe_id']) ? (int) $_POST['groupe_id'] : null;

    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }

    // Creer le job
    $jobId = bin2hex(random_bytes(8));
    $dossierJob = __DIR__ . '/data/jobs/' . $jobId;
    if (!is_dir($dossierJob)) {
        mkdir($dossierJob, 0755, true);
    }

    // Sauver la config du job avec les infos de connexion DB
    // Le worker est lance en CLI sans contexte plateforme :
    // il a besoin des credentials DB pour se connecter a MySQL
    $configJob = [
        'client_id' => $clientId,
        'groupe_id' => $groupeId,
        'user_agent' => $_POST['user_agent'] ?? null,
        'timeout' => (int) ($_POST['timeout'] ?? 30),
        'delai_entre_requetes_ms' => (int) ($_POST['delai_ms'] ?? 1000),
    ];

    // Lire le crawler_mode depuis module.json
    $moduleJson = __DIR__ . '/module.json';
    if (file_exists($moduleJson)) {
        $moduleConfig = json_decode(file_get_contents($moduleJson), true);
        if (!empty($moduleConfig['crawler_mode'])) {
            $configJob['crawler_mode'] = $moduleConfig['crawler_mode'];
        }
    }

    // Propager les infos DB de la plateforme vers le worker CLI
    $pilote = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($pilote === 'mysql') {
        // Extraire host/dbname depuis le DSN
        $dsn = '';
        try {
            $dsn = $db->getAttribute(\PDO::ATTR_CONNECTION_STATUS) ?: '';
        } catch (\Throwable) {}
        $configJob['db'] = [
            'type' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
            'name' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '',
            'user' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
        ];
    }

    file_put_contents(
        $dossierJob . '/config.json',
        json_encode($configJob, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    // Initialiser la progression
    file_put_contents(
        $dossierJob . '/progress.json',
        json_encode(['status' => 'starting', 'percent' => 0, 'step' => 'Demarrage...'])
    );

    // Lancer le worker en arriere-plan (stderr vers error.log pour debug)
    $phpBin = PHP_BINARY;
    $workerPath = __DIR__ . '/worker.php';
    $errorLog = $dossierJob . '/error.log';
    $cmd = sprintf(
        '%s %s --job=%s > %s 2>&1 &',
        $phpBin,
        escapeshellarg($workerPath),
        $jobId,
        escapeshellarg($errorLog)
    );
    exec($cmd);

    // Décompter les crédits
    if (class_exists(\Platform\Module\Quota::class)) {
        \Platform\Module\Quota::track('site-monitor');
    }

    return [
        'donnees' => ['job_id' => $jobId],
        'message' => 'Verification lancee',
    ];
}

// === RESULTATS ===

function gererResultats(\PDO $db, string $action): array
{
    $depot = new DepotResultatRegle($db);

    return match ($action) {
        'par_execution' => ['donnees' => array_map(
            fn($r) => $r->versTableau(),
            $depot->trouverParExecution((int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0))
        )],
        'par_url' => ['donnees' => array_map(
            fn($r) => $r->versTableau(),
            $depot->trouverParUrl(
                (int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0),
                (int) ($_POST['url_id'] ?? $_GET['url_id'] ?? 0)
            )
        )],
        'echecs' => ['donnees' => array_map(
            fn($r) => $r->versTableau(),
            $depot->trouverEchecsParExecution((int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0))
        )],
        'resume_urls' => ['donnees' => $depot->resumeParUrl(
            (int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0)
        )],
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

// === DASHBOARD ===

function genererDashboard(\PDO $db, string $action, ?int $utilisateurId): array
{
    return match ($action) {
        'lister', '' => genererDashboardPrincipal($db, $utilisateurId),
        'stats_par_client' => genererStatsParClient($db, $utilisateurId),
        'urls_a_risque' => genererUrlsARisque($db),
        'changements_recents' => genererChangementsRecents($db),
        'tendances' => genererTendances($db),
        default => ['erreur' => "Action dashboard inconnue : {$action}"],
    };
}

function genererDashboardPrincipal(\PDO $db, ?int $utilisateurId): array
{
    $depotClient = new DepotClient($db);
    $depotExecution = new DepotExecution($db);
    $depotAlerte = new DepotAlerte($db);
    $depotResultat = new DepotResultatRegle($db);

    $statsExec = $depotExecution->statistiquesGlobales();

    // Compter les URLs actives surveillees
    $urlsSurveillees = (int) $db->query(
        'SELECT COUNT(*) FROM sm_urls WHERE actif = 1'
    )->fetchColumn();
    $statsExec['urls_surveillees'] = $urlsSurveillees;

    // Alertes recentes
    $alertesRecentes = array_map(
        fn($a) => $a->versTableau(),
        $depotAlerte->trouverRecentes(5),
    );

    // Score de sante global : moyenne des derniers taux par client actif
    $statsAvancees = $depotClient->statistiquesAvancees($utilisateurId);
    $tauxClients = array_filter(
        array_column($statsAvancees, 'taux_reussite_dernier'),
        fn($v) => $v !== null,
    );
    $scoreSante = count($tauxClients) > 0
        ? round(array_sum($tauxClients) / count($tauxClients), 1)
        : null;

    // Changements detectes (7 jours)
    $changementsDetectes = (int) $db->query("
        SELECT COUNT(*) FROM sm_resultats r
        JOIN sm_regles reg ON reg.id = r.regle_id
        JOIN sm_executions e ON e.id = r.execution_id
        WHERE reg.type_regle = 'changement_contenu' AND r.succes = 0
          AND e.cree_le >= " . \SiteMonitor\Core\Connexion::ilYA('-7 days') . "
    ")->fetchColumn();

    // Alertes critiques non envoyees
    $alertesCritiques = (int) $db->query("
        SELECT COUNT(*) FROM sm_alertes
        WHERE severite = 'critique' AND envoyee = 0
    ")->fetchColumn();

    return [
        'donnees' => [
            'clients' => $depotClient->statistiques($utilisateurId),
            'stats_clients' => [
                'total' => $depotClient->compter($utilisateurId),
                'actifs' => $depotClient->compterActifs($utilisateurId),
            ],
            'stats_executions' => $statsExec,
            'alertes_recentes' => $alertesRecentes,
            'alertes_non_lues' => $depotAlerte->compterNonLues(),
            'score_sante_global' => $scoreSante,
            'changements_detectes' => $changementsDetectes,
            'alertes_critiques' => $alertesCritiques,
        ],
    ];
}

function genererStatsParClient(\PDO $db, ?int $utilisateurId): array
{
    $depotClient = new DepotClient($db);
    $depotExecution = new DepotExecution($db);

    $stats = $depotClient->statistiquesAvancees($utilisateurId);

    // Enrichir chaque client avec les sparkline data
    foreach ($stats as &$client) {
        $client['sparkline_data'] = $depotExecution->historiqueTauxParClient(
            (int) $client['client_id'],
            10,
        );
    }
    unset($client);

    return ['donnees' => $stats];
}

function genererUrlsARisque(\PDO $db): array
{
    $depot = new DepotResultatRegle($db);
    return ['donnees' => $depot->urlsARisque()];
}

function genererChangementsRecents(\PDO $db): array
{
    $depot = new DepotResultatRegle($db);
    return ['donnees' => $depot->changementsRecents()];
}

function genererTendances(\PDO $db): array
{
    $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== ''
        ? (int) $_POST['client_id']
        : (isset($_GET['client_id']) && $_GET['client_id'] !== ''
            ? (int) $_GET['client_id']
            : null);

    $depotExecution = new DepotExecution($db);
    $depotMetrique = new DepotMetriqueHttp($db);

    return ['donnees' => [
        'executions_par_jour' => $depotExecution->tendances30Jours($clientId),
        'metriques_par_jour' => $depotMetrique->moyennesParJour($clientId),
    ]];
}

// === TYPES DE REGLES ===

function listerTypesRegles(): array
{
    $types = [];
    foreach (TypeRegle::cases() as $type) {
        $types[] = [
            'valeur' => $type->value,
            'libelle' => $type->libelle(),
            'icone' => $type->icone(),
            'categorie' => $type->categorie(),
        ];
    }
    return ['donnees' => $types];
}

// === ALERTES ===

function gererAlertes(\PDO $db, string $action): array
{
    $depot = new DepotAlerte($db);

    return match ($action) {
        'lister' => listerAlertes($depot),
        'par_execution' => ['donnees' => array_map(
            fn($a) => $a->versTableau(),
            $depot->trouverParExecution((int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0))
        )],
        'compter_non_lues' => ['donnees' => ['total' => $depot->compterNonLues()]],
        'marquer_envoyee' => marquerAlerteEnvoyee($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerAlertes(DepotAlerte $depot): array
{
    $clientId = isset($_POST['client_id']) || isset($_GET['client_id'])
        ? (int) ($_POST['client_id'] ?? $_GET['client_id'])
        : null;
    $limite = (int) ($_POST['limite'] ?? $_GET['limite'] ?? 50);

    if ($clientId !== null) {
        $alertes = $depot->trouverParClient($clientId, $limite);
    } else {
        $alertes = $depot->trouverRecentes($limite);
    }

    return ['donnees' => array_map(fn($a) => $a->versTableau(), $alertes)];
}

function marquerAlerteEnvoyee(DepotAlerte $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        return ['erreur' => 'id requis'];
    }
    $depot->marquerEnvoyee($id);
    return ['message' => 'Alerte marquee comme envoyee'];
}

// === SNAPSHOTS ===

function gererSnapshots(\PDO $db, string $action): array
{
    $depot = new DepotSnapshot($db);

    return match ($action) {
        'lister' => listerSnapshots($depot),
        'comparer' => comparerSnapshots($depot),
        'definir_baseline' => definirSnapshotBaseline($depot),
        'contenu' => obtenirContenuSnapshot($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerSnapshots(DepotSnapshot $depot): array
{
    $urlId = (int) ($_POST['url_id'] ?? $_GET['url_id'] ?? 0);
    if ($urlId <= 0) {
        return ['erreur' => 'url_id requis'];
    }

    // Lister les snapshots pour cette URL (sans le contenu compresse)
    $stmt = $depot->listerParUrl($urlId);
    return ['donnees' => $stmt];
}

function comparerSnapshots(DepotSnapshot $depot): array
{
    $urlId = (int) ($_POST['url_id'] ?? $_GET['url_id'] ?? 0);
    $snapshotId = (int) ($_POST['snapshot_id'] ?? $_GET['snapshot_id'] ?? 0);

    if ($urlId <= 0) {
        return ['erreur' => 'url_id requis'];
    }

    // Recuperer la baseline
    $baseline = $depot->trouverBaseline($urlId, 'body');
    if ($baseline === null) {
        return ['erreur' => 'Aucune baseline trouvee'];
    }

    // Recuperer le snapshot courant (ou le dernier)
    $courant = $snapshotId > 0
        ? $depot->trouverDernier($urlId, 'body')
        : $depot->trouverDernier($urlId, 'body');

    if ($courant === null) {
        return ['erreur' => 'Aucun snapshot courant'];
    }

    $contenuBaseline = $depot->lireContenu($baseline->id);
    $contenuCourant = $depot->lireContenu($courant->id);

    return ['donnees' => [
        'baseline' => [
            'id' => $baseline->id,
            'hash' => $baseline->hashContenu,
            'taille' => $baseline->tailleOctets,
            'date' => $baseline->creeLe,
            'contenu' => $contenuBaseline,
        ],
        'courant' => [
            'id' => $courant->id,
            'hash' => $courant->hashContenu,
            'taille' => $courant->tailleOctets,
            'date' => $courant->creeLe,
            'contenu' => $contenuCourant,
        ],
    ]];
}

function definirSnapshotBaseline(DepotSnapshot $depot): array
{
    $snapshotId = (int) ($_POST['id'] ?? 0);
    if ($snapshotId <= 0) {
        return ['erreur' => 'id requis'];
    }
    $depot->definirBaseline($snapshotId);
    return ['message' => 'Baseline mise a jour'];
}

function obtenirContenuSnapshot(DepotSnapshot $depot): array
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        return ['erreur' => 'id requis'];
    }
    $contenu = $depot->lireContenu($id);
    if ($contenu === null) {
        return ['erreur' => 'Snapshot introuvable ou vide'];
    }
    return ['donnees' => ['contenu' => $contenu]];
}

// === METRIQUES HTTP ===

function gererMetriques(\PDO $db, string $action): array
{
    $depot = new DepotMetriqueHttp($db);

    return match ($action) {
        'par_url' => ['donnees' => array_map(
            fn($m) => $m->versTableau(),
            $depot->trouverParUrl(
                (int) ($_POST['url_id'] ?? $_GET['url_id'] ?? 0),
                (int) ($_POST['limite'] ?? $_GET['limite'] ?? 100),
            )
        )],
        'par_execution' => ['donnees' => array_map(
            fn($m) => $m->versTableau(),
            $depot->trouverParExecution(
                (int) ($_POST['execution_id'] ?? $_GET['execution_id'] ?? 0)
            )
        )],
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

// === INDEXATION ===

function gererIndexation(\PDO $db, string $action, ?int $utilisateurId): array
{
    $depotAudit = new DepotAuditIndexation($db);
    $depotResultat = new DepotResultatIndexation($db);

    return match ($action) {
        'lancer' => lancerAuditIndexation($db, $depotAudit, $utilisateurId),
        'lister' => listerAuditsIndexation($depotAudit, $utilisateurId),
        'obtenir' => obtenirAuditIndexation($depotAudit, $depotResultat),
        'resultats' => obtenirResultatsIndexation($depotResultat),
        'stats' => obtenirStatsIndexation($depotResultat),
        'urls_client' => obtenirUrlsClient($db),
        default => ['erreur' => "Action indexation inconnue : {$action}"],
    };
}

function lancerAuditIndexation(\PDO $db, DepotAuditIndexation $depotAudit, ?int $utilisateurId): array
{
    // Vérification quota
    if (class_exists('\\Platform\\Module\\Quota')) {
        if (!\Platform\Module\Quota::creditsDisponibles('site-monitor')) {
            http_response_code(429);
            return ['erreur' => 'Crédits épuisés'];
        }
    }

    $domaine = trim($_POST['domaine'] ?? '');
    $urlsTexte = trim($_POST['urls'] ?? '');
    $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== ''
        ? (int) $_POST['client_id']
        : null;

    if ($domaine === '' || $urlsTexte === '') {
        return ['erreur' => 'Domaine et URLs requis'];
    }

    // Parser les URLs (une par ligne)
    $urls = array_values(array_filter(
        array_map('trim', explode("\n", $urlsTexte)),
        fn(string $url) => $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false,
    ));

    if ($urls === []) {
        return ['erreur' => 'Aucune URL valide fournie'];
    }

    // S'assurer que le domaine a un schema
    if (!str_starts_with($domaine, 'http')) {
        $domaine = 'https://' . $domaine;
    }

    // Creer l'audit en base
    $auditId = $depotAudit->creer([
        'client_id' => $clientId,
        'utilisateur_id' => $utilisateurId,
        'domaine' => $domaine,
        'urls_total' => count($urls),
    ]);

    // Creer le job
    $jobId = bin2hex(random_bytes(8));
    $dossierJob = __DIR__ . '/data/jobs/' . $jobId;
    if (!is_dir($dossierJob)) {
        mkdir($dossierJob, 0755, true);
    }

    $configJob = [
        'audit_id' => $auditId,
        'domaine' => $domaine,
        'urls' => $urls,
        'client_id' => $clientId,
        'delai_ms' => (int) ($_POST['delai_ms'] ?? 500),
        'timeout' => (int) ($_POST['timeout'] ?? 30),
    ];

    file_put_contents(
        $dossierJob . '/config.json',
        json_encode($configJob, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    );

    file_put_contents(
        $dossierJob . '/progress.json',
        json_encode(['status' => 'starting', 'percent' => 0, 'step' => 'Demarrage...']),
    );

    // Lancer le worker en arriere-plan
    $phpBin = PHP_BINARY;
    $workerPath = __DIR__ . '/worker-indexation.php';
    $cmd = sprintf('%s %s --job=%s > /dev/null 2>&1 &', $phpBin, escapeshellarg($workerPath), $jobId);
    exec($cmd);

    return [
        'donnees' => ['job_id' => $jobId, 'audit_id' => $auditId],
        'message' => 'Audit d\'indexation lance',
    ];
}

function listerAuditsIndexation(DepotAuditIndexation $depotAudit, ?int $utilisateurId): array
{
    $limite = (int) ($_POST['limite'] ?? $_GET['limite'] ?? 50);
    $audits = $depotAudit->listerParUtilisateur($utilisateurId, $limite);
    return ['donnees' => array_map(fn($a) => $a->versTableau(), $audits)];
}

function obtenirAuditIndexation(DepotAuditIndexation $depotAudit, DepotResultatIndexation $depotResultat): array
{
    $id = (int) ($_POST['audit_id'] ?? $_GET['audit_id'] ?? 0);
    $audit = $depotAudit->trouverParId($id);
    if ($audit === null) {
        return ['erreur' => 'Audit introuvable'];
    }

    $statsStatut = $depotResultat->compterParStatut($id);
    $statsContradictions = $depotResultat->compterParContradiction($id);

    return ['donnees' => [
        'audit' => $audit->versTableau(),
        'stats_statut' => $statsStatut,
        'stats_contradictions' => $statsContradictions,
    ]];
}

function obtenirResultatsIndexation(DepotResultatIndexation $depotResultat): array
{
    $auditId = (int) ($_POST['audit_id'] ?? $_GET['audit_id'] ?? 0);
    if ($auditId <= 0) {
        return ['erreur' => 'audit_id requis'];
    }

    $statutFiltre = $_POST['statut'] ?? $_GET['statut'] ?? null;
    $severiteFiltre = $_POST['severite'] ?? $_GET['severite'] ?? null;

    $resultats = $depotResultat->listerParAudit($auditId, $statutFiltre, $severiteFiltre);
    return ['donnees' => array_map(fn($r) => $r->versTableau(), $resultats)];
}

function obtenirStatsIndexation(DepotResultatIndexation $depotResultat): array
{
    $auditId = (int) ($_POST['audit_id'] ?? $_GET['audit_id'] ?? 0);
    if ($auditId <= 0) {
        return ['erreur' => 'audit_id requis'];
    }

    return ['donnees' => [
        'par_statut' => $depotResultat->compterParStatut($auditId),
        'par_contradiction' => $depotResultat->compterParContradiction($auditId),
    ]];
}

function obtenirUrlsClient(\PDO $db): array
{
    $clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }

    $depotUrl = new DepotUrl($db);
    $urls = $depotUrl->trouverActivesParClient($clientId);

    return ['donnees' => array_map(fn($u) => $u->url, $urls)];
}
