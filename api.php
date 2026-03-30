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
use SiteMonitor\Moteur\ComparateurExecutions;
use SiteMonitor\Moteur\LanceurVerification;
use SiteMonitor\Entite\Planification;
use SiteMonitor\Stockage\DepotPlanification;
use SiteMonitor\Indexation\ExtractionSitemap;
use SiteMonitor\Moteur\GenerateurDiff;

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
        'planification' => gererPlanifications($db, $action),
        'types_regles' => listerTypesRegles(),
        'diagnostic' => diagnosticWorker(),
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
        'creer_lot' => creerGroupesEnLot($db, $depot),
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

function creerGroupesEnLot(\PDO $db, DepotGroupeUrls $depotGroupe): array
{
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $groupesJson = $_POST['groupes'] ?? '';

    if ($clientId <= 0 || $groupesJson === '') {
        return ['erreur' => 'client_id et groupes requis'];
    }

    $groupes = json_decode($groupesJson, true);
    if (!is_array($groupes) || empty($groupes)) {
        return ['erreur' => 'Format groupes invalide'];
    }

    $depotUrl = new DepotUrl($db);
    $groupesCrees = 0;
    $urlsCreees = 0;

    foreach ($groupes as $g) {
        $nom = trim($g['nom'] ?? '');
        if ($nom === '') {
            continue;
        }

        $groupe = new GroupeUrls(
            id: null,
            clientId: $clientId,
            nom: $nom,
            description: null,
            ordreTri: $groupesCrees,
            actif: true,
            planification: null,
            creeLe: null,
            modifieLe: null,
        );
        $groupeId = $depotGroupe->creer($groupe);
        $groupesCrees++;

        // Creer les URLs du groupe
        $urlsTexte = $g['urls'] ?? [];
        if (is_string($urlsTexte)) {
            $urlsTexte = array_filter(array_map('trim', explode("\n", $urlsTexte)), fn(string $l): bool => $l !== '');
        }

        foreach ($urlsTexte as $urlStr) {
            $urlStr = trim($urlStr);
            if ($urlStr === '') {
                continue;
            }
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
            $depotUrl->creer($url);
            $urlsCreees++;
        }
    }

    // Creer un modele avec template si demande
    $modeleId = null;
    $reglesCreees = 0;
    $templateCle = trim($_POST['template_modele'] ?? '');
    if ($templateCle !== '' && $urlsCreees > 0) {
        $reglesTemplate = RegistreTemplates::obtenir($templateCle);
        if ($reglesTemplate !== null) {
            $depotModele = new DepotModele($db);
            $depotRegle = new DepotRegle($db);

            // Creer le modele
            $nomTemplate = RegistreTemplates::lister()[$templateCle]['nom'] ?? $templateCle;
            $modele = new Modele(
                id: null,
                clientId: $clientId,
                nom: $nomTemplate,
                description: null,
                estGlobal: false,
                creeLe: null,
                modifieLe: null,
            );
            $modeleId = $depotModele->creer($modele);

            // Creer les regles
            $ordre = 1;
            foreach ($reglesTemplate as $def) {
                $regle = new Regle(
                    id: null,
                    modeleId: $modeleId,
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

            // Associer le modele a toutes les URLs creees
            $toutesUrls = $depotUrl->trouverActivesParClient($clientId);
            foreach ($toutesUrls as $u) {
                $depotUrl->associerModele($u->id, $modeleId);
            }
        }
    }

    // Lancer la verification si demande
    $jobId = null;
    if (($_POST['lancer'] ?? '') === '1' && $urlsCreees > 0) {
        $resultatLancement = lancerExecution($db, new DepotExecution($db));
        $jobId = $resultatLancement['donnees']['job_id'] ?? null;
    }

    return [
        'donnees' => [
            'groupes_crees' => $groupesCrees,
            'urls_creees' => $urlsCreees,
            'regles_creees' => $reglesCreees,
            'modele_id' => $modeleId,
            'job_id' => $jobId,
        ],
        'message' => "{$groupesCrees} groupe(s), {$urlsCreees} URL(s)" . ($reglesCreees > 0 ? ", {$reglesCreees} regles" : '') . ' crees',
    ];
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
        'decouvrir_sitemap' => decouvrirSitemap($db),
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

function decouvrirSitemap(\PDO $db): array
{
    $clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
    $domaine = trim($_POST['domaine'] ?? $_GET['domaine'] ?? '');

    // Si pas de domaine fourni, le chercher via le client
    if ($domaine === '' && $clientId > 0) {
        $depotClient = new DepotClient($db);
        $client = $depotClient->trouverParId($clientId);
        if ($client !== null) {
            $domaine = $client->domaine;
        }
    }

    if ($domaine === '') {
        return ['erreur' => 'domaine requis'];
    }

    // Normaliser le domaine
    if (!str_starts_with($domaine, 'http')) {
        $domaine = 'https://' . $domaine;
    }

    try {
        $extracteur = new ExtractionSitemap();
        $urls = $extracteur->extraireDepuisRobots($domaine);

        // Grouper par premier segment de chemin pour suggestion
        $groupes = [];
        foreach ($urls as $url) {
            $parsed = parse_url($url);
            $chemin = $parsed['path'] ?? '/';
            $segments = array_filter(explode('/', trim($chemin, '/')));
            $premierSegment = !empty($segments) ? reset($segments) : 'racine';
            $groupes[$premierSegment][] = $url;
        }

        // Trier par nombre d'URLs decroissant
        uasort($groupes, fn(array $a, array $b) => count($b) - count($a));

        // Limiter a 500 URLs max pour eviter les gros sitemaps
        $urlsLimitees = array_slice($urls, 0, 500);

        return ['donnees' => [
            'urls' => $urlsLimitees,
            'total' => count($urls),
            'affiche' => count($urlsLimitees),
            'groupes_suggeres' => array_map(fn(array $g) => [
                'urls' => array_slice($g, 0, 50),
                'total' => count($g),
            ], array_slice($groupes, 0, 20, true)),
        ]];
    } catch (\Throwable $e) {
        return ['erreur' => 'Erreur sitemap : ' . $e->getMessage()];
    }
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
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }

    $lanceur = new LanceurVerification($db, __DIR__);
    $jobId = $lanceur->lancer([
        'client_id' => $clientId,
        'groupe_id' => isset($_POST['groupe_id']) ? (int) $_POST['groupe_id'] : null,
        'user_agent' => $_POST['user_agent'] ?? null,
        'timeout' => (int) ($_POST['timeout'] ?? 30),
        'delai_ms' => (int) ($_POST['delai_ms'] ?? 1000),
        'type_declencheur' => 'manuel',
    ]);

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
        'changements_feed' => genererChangementsFeed($db, $utilisateurId),
        'tendances' => genererTendances($db),
        'urls_par_groupe' => genererUrlsParGroupe($db),
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

function genererUrlsParGroupe(\PDO $db): array
{
    $clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }
    $groupeId = (int) ($_POST['groupe_id'] ?? $_GET['groupe_id'] ?? 0);

    $filtreGroupe = $groupeId > 0 ? 'AND u.groupe_id = :groupe_id' : '';

    $sql = "
        SELECT
            u.id,
            u.url,
            u.libelle,
            u.groupe_id,
            g.nom AS groupe_nom,
            u.actif,
            u.derniere_verification,
            u.dernier_statut,
            (SELECT COUNT(*) FROM sm_url_modele um
             JOIN sm_regles r ON r.modele_id = um.modele_id AND r.actif = 1
             WHERE um.url_id = u.id) AS nb_regles,
            (SELECT COUNT(*) FROM sm_resultats res
             JOIN sm_executions ex ON ex.id = res.execution_id
             WHERE res.url_id = u.id AND res.succes = 0
               AND ex.id = (
                   SELECT MAX(e2.id) FROM sm_executions e2
                   WHERE e2.client_id = :client_id_sub AND e2.statut = 'termine'
               )
            ) AS nb_echecs_derniere
        FROM sm_urls u
        JOIN sm_groupes_urls g ON g.id = u.groupe_id
        WHERE g.client_id = :client_id AND u.actif = 1
        {$filtreGroupe}
        ORDER BY g.ordre_tri ASC, g.nom ASC, u.url ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':client_id', $clientId, \PDO::PARAM_INT);
    $stmt->bindValue(':client_id_sub', $clientId, \PDO::PARAM_INT);
    if ($groupeId > 0) {
        $stmt->bindValue(':groupe_id', $groupeId, \PDO::PARAM_INT);
    }
    $stmt->execute();

    return ['donnees' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
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

function genererChangementsFeed(\PDO $db, ?int $utilisateurId): array
{
    $depotClient = new DepotClient($db);
    $depotExecution = new DepotExecution($db);
    $depotResultat = new DepotResultatRegle($db);
    $depotUrl = new DepotUrl($db);
    $depotRegle = new DepotRegle($db);
    $comparateur = new ComparateurExecutions();

    $clients = array_map(
        fn($c) => $c->versTableau(),
        $depotClient->trouverTous(actifsUniquement: true, utilisateurId: $utilisateurId)
    );

    $nouvelles = [];
    $recuperations = [];
    $persistantes = [];

    foreach ($clients as $client) {
        $clientId = (int) ($client['id'] ?? $client['client_id'] ?? 0);
        $clientNom = $client['nom'] ?? 'Client';

        // Trouver les 2 dernieres executions terminees pour ce client
        $executions = $depotExecution->trouverParClient($clientId, 2);
        $execTerminees = array_filter($executions, fn($e) => $e->statut === StatutExecution::Termine);
        $execTerminees = array_values($execTerminees);

        if (count($execTerminees) === 0) {
            continue;
        }

        // 1 seule execution : afficher l'etat initial (baseline)
        if (count($execTerminees) === 1) {
            $actuelle = $execTerminees[0];
            $resultats = $depotResultat->trouverParExecution($actuelle->id);
            $echecs = array_filter($resultats, fn($r) => !$r->succes);
            if (!empty($echecs)) {
                $enrichisBaseline = $enrichir(array_values($echecs), $clientNom, $clientId);
                // Marquer comme baseline
                foreach ($enrichisBaseline as &$item) {
                    $item['type'] = 'baseline';
                }
                unset($item);
                $persistantes = array_merge($persistantes, $enrichisBaseline);
            }
            continue;
        }

        $actuelle = $execTerminees[0];
        $precedente = $execTerminees[1];

        $resultatsActuels = $depotResultat->trouverParExecution($actuelle->id);
        $resultatsPrecedents = $depotResultat->trouverParExecution($precedente->id);

        $rapport = $comparateur->comparer($resultatsActuels, $resultatsPrecedents);

        // Enrichir chaque resultat avec url, client, regle
        $enrichir = function (array $resultats, string $clientNom, int $clientId) use ($depotUrl, $depotRegle): array {
            $enrichis = [];
            foreach ($resultats as $r) {
                $url = $depotUrl->trouverParId($r->urlId);
                $regle = $depotRegle->trouverParId($r->regleId);
                $enrichis[] = [
                    'url' => $url?->url ?? '',
                    'url_id' => $r->urlId,
                    'url_libelle' => $url?->libelle ?? '',
                    'client_nom' => $clientNom,
                    'client_id' => $clientId,
                    'regle_type' => $regle?->typeRegle->libelle() ?? '',
                    'regle_nom' => $regle?->nom ?? '',
                    'severite' => $r->severite->value,
                    'message' => $r->message ?? '',
                    'valeur_attendue' => $r->valeurAttendue,
                    'valeur_obtenue' => $r->valeurObtenue,
                ];
            }
            return $enrichis;
        };

        $nouvelles = array_merge($nouvelles, $enrichir($rapport['nouvelles_defaillances'], $clientNom, $clientId));
        $recuperations = array_merge($recuperations, $enrichir($rapport['recuperations'], $clientNom, $clientId));
        $persistantes = array_merge($persistantes, $enrichir($rapport['defaillances_persistantes'], $clientNom, $clientId));
    }

    return ['donnees' => [
        'nouvelles_defaillances' => $nouvelles,
        'recuperations' => $recuperations,
        'defaillances_persistantes' => $persistantes,
        'resume' => [
            'nb_nouvelles' => count($nouvelles),
            'nb_recuperations' => count($recuperations),
            'nb_persistantes' => count($persistantes),
        ],
    ]];
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
        'diff' => genererDiffSnapshot($depot),
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

function genererDiffSnapshot(DepotSnapshot $depot): array
{
    $urlId = (int) ($_POST['url_id'] ?? $_GET['url_id'] ?? 0);
    if ($urlId <= 0) {
        return ['erreur' => 'url_id requis'];
    }

    $modeHtml = ($_POST['mode'] ?? $_GET['mode'] ?? 'texte') === 'html';

    // Charger baseline et dernier snapshot
    $baseline = $depot->trouverBaseline($urlId, 'body');
    if ($baseline === null) {
        return ['erreur' => 'Aucune baseline trouvee pour cette URL'];
    }

    $dernier = $depot->trouverDernier($urlId, 'body');
    if ($dernier === null || $dernier->id === $baseline->id) {
        return ['erreur' => 'Pas assez de snapshots pour comparer'];
    }

    $contenuBaseline = $depot->lireContenu($baseline->id);
    $contenuDernier = $depot->lireContenu($dernier->id);

    if ($contenuBaseline === null || $contenuDernier === null) {
        return ['erreur' => 'Contenu des snapshots introuvable'];
    }

    $generateur = new GenerateurDiff(lignesContexte: 3);
    $diff = $generateur->comparer($contenuBaseline, $contenuDernier, $modeHtml);

    $diff['baseline_date'] = $baseline->creeLe;
    $diff['courant_date'] = $dernier->creeLe;

    return ['donnees' => $diff];
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

// === PLANIFICATIONS ===

function gererPlanifications(\PDO $db, string $action): array
{
    $depot = new DepotPlanification($db);

    return match ($action) {
        'lister' => listerPlanifications($depot),
        'creer' => creerPlanification($depot),
        'modifier' => modifierPlanification($depot),
        'supprimer' => supprimerPlanification($depot),
        default => ['erreur' => "Action inconnue : {$action}"],
    };
}

function listerPlanifications(DepotPlanification $depot): array
{
    $clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }
    return ['donnees' => array_map(fn($p) => $p->versTableau(), $depot->trouverParClient($clientId))];
}

function creerPlanification(DepotPlanification $depot): array
{
    $clientId = (int) ($_POST['client_id'] ?? 0);
    if ($clientId <= 0) {
        return ['erreur' => 'client_id requis'];
    }

    $frequence = (int) ($_POST['frequence_minutes'] ?? 1440);

    // Calculer la prochaine execution
    $prochaine = date('Y-m-d H:i:s', time() + $frequence * 60);

    $planif = new Planification(
        id: null,
        clientId: $clientId,
        groupeId: isset($_POST['groupe_id']) && $_POST['groupe_id'] !== '' ? (int) $_POST['groupe_id'] : null,
        frequenceMinutes: $frequence,
        heureDebut: $_POST['heure_debut'] ?? null,
        heureFin: $_POST['heure_fin'] ?? null,
        joursSemaine: $_POST['jours_semaine'] ?? null,
        userAgent: null,
        headersJson: null,
        timeoutSecondes: (int) ($_POST['timeout'] ?? 30),
        delaiEntreRequetesMs: (int) ($_POST['delai_ms'] ?? 1000),
        actif: true,
        derniereExecution: null,
        prochaineExecution: $prochaine,
        creeLe: null,
        modifieLe: null,
    );

    $id = $depot->creer($planif);
    return ['donnees' => ['id' => $id], 'message' => 'Planification creee'];
}

function modifierPlanification(DepotPlanification $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $existante = $depot->trouverParId($id);
    if (!$existante) {
        return ['erreur' => 'Planification introuvable'];
    }

    $frequence = (int) ($_POST['frequence_minutes'] ?? $existante->frequenceMinutes);
    $actif = isset($_POST['actif']) ? (bool) $_POST['actif'] : $existante->actif;

    $planif = new Planification(
        id: $id,
        clientId: $existante->clientId,
        groupeId: $existante->groupeId,
        frequenceMinutes: $frequence,
        heureDebut: $_POST['heure_debut'] ?? $existante->heureDebut,
        heureFin: $_POST['heure_fin'] ?? $existante->heureFin,
        joursSemaine: $_POST['jours_semaine'] ?? $existante->joursSemaine,
        userAgent: $existante->userAgent,
        headersJson: $existante->headersJson,
        timeoutSecondes: (int) ($_POST['timeout'] ?? $existante->timeoutSecondes),
        delaiEntreRequetesMs: (int) ($_POST['delai_ms'] ?? $existante->delaiEntreRequetesMs),
        actif: $actif,
        derniereExecution: $existante->derniereExecution,
        prochaineExecution: $existante->prochaineExecution,
        creeLe: $existante->creeLe,
        modifieLe: null,
    );

    $depot->modifier($planif);
    return ['message' => 'Planification modifiee'];
}

function supprimerPlanification(DepotPlanification $depot): array
{
    $id = (int) ($_POST['id'] ?? 0);
    $depot->supprimer($id);
    return ['message' => 'Planification supprimee'];
}

// === PHP CLI RESOLUTION ===

/**
 * Resout le chemin vers le binaire PHP CLI.
 * PHP_BINARY peut pointer vers php-fpm en contexte FastCGI.
 */
function resoudrePhpCli(): string
{
    // Si PHP_BINARY est deja le CLI (pas fpm, pas cgi)
    $binary = PHP_BINARY;
    if (!str_contains($binary, 'fpm') && !str_contains($binary, 'cgi')) {
        return $binary;
    }

    // Chercher php CLI a cote de php-fpm (meme prefixe de version)
    // Ex: /usr/sbin/php-fpm8.3 → /usr/bin/php8.3
    if (preg_match('/php-fpm(\d+\.\d+)/', $binary, $m)) {
        $candidates = [
            '/usr/bin/php' . $m[1],
            '/usr/local/bin/php' . $m[1],
            '/usr/bin/php',
        ];
    } else {
        $candidates = ['/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1', '/usr/bin/php'];
    }

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    // Dernier recours : which php
    $which = trim(shell_exec('which php 2>/dev/null') ?? '');
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    // Fallback : PHP_BINARY meme si c'est fpm (mieux que rien)
    return $binary;
}

// === DIAGNOSTIC (temporaire) ===

function diagnosticWorker(): array
{
    $phpBin = PHP_BINARY;
    $workerPath = __DIR__ . '/worker.php';
    $dataDir = __DIR__ . '/data';
    $jobsDir = $dataDir . '/jobs';

    // Lister les derniers jobs et leurs fichiers
    $jobs = [];
    if (is_dir($jobsDir)) {
        $dirs = scandir($jobsDir, SCANDIR_SORT_DESCENDING);
        $count = 0;
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') continue;
            if ($count >= 3) break;
            $jobDir = $jobsDir . '/' . $d;
            $job = ['id' => $d, 'fichiers' => []];
            foreach (['config.json', 'progress.json', 'error.log'] as $f) {
                $chemin = $jobDir . '/' . $f;
                if (file_exists($chemin)) {
                    $contenu = file_get_contents($chemin);
                    $job['fichiers'][$f] = mb_substr($contenu, 0, 2000);
                }
            }
            $jobs[] = $job;
            $count++;
        }
    }

    // Tester si exec() fonctionne
    $execTest = null;
    if (function_exists('exec')) {
        $output = [];
        $returnCode = -1;
        exec($phpBin . ' -v 2>&1', $output, $returnCode);
        $execTest = [
            'disponible' => true,
            'retour' => $returnCode,
            'sortie' => implode("\n", array_slice($output, 0, 3)),
        ];
    } else {
        $execTest = ['disponible' => false];
    }

    // Tester un mini worker
    $testCmd = sprintf('%s -r "echo json_encode([\"ok\"=>true]);" 2>&1', $phpBin);
    $testOutput = [];
    $testReturn = -1;
    exec($testCmd, $testOutput, $testReturn);

    return ['donnees' => [
        'php_binary' => $phpBin,
        'worker_path' => $workerPath,
        'worker_exists' => file_exists($workerPath),
        'data_dir_exists' => is_dir($dataDir),
        'data_dir_writable' => is_writable($dataDir),
        'jobs_dir_exists' => is_dir($jobsDir),
        'jobs_dir_writable' => is_writable($jobsDir),
        'exec_test' => $execTest,
        'mini_worker_test' => ['retour' => $testReturn, 'sortie' => implode("\n", $testOutput)],
        'cwd' => getcwd(),
        'dir' => __DIR__,
        'platform_embedded' => defined('PLATFORM_EMBEDDED'),
        'platform_domain' => defined('PLATFORM_DOMAIN'),
        'derniers_jobs' => $jobs,
    ]];
}
