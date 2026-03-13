<?php

declare(strict_types=1);

/**
 * Point d'entree CLI standalone — Execute les verifications sans la plateforme.
 *
 * Usage :
 *   php monitor.php --config=config.json --run
 *   php monitor.php --config=config.json --client=acme-corp
 *   php monitor.php --config=config.json --group=1
 *   php monitor.php --config=config.json --url=https://example.com
 *   php monitor.php --config=config.json --import
 *   php monitor.php --config=config.json --report
 *   php monitor.php --config=config.json --check-ssl
 *   php monitor.php --config=config.json --check-sitemap
 *   php monitor.php --config=config.json --check-robots
 *   php monitor.php --config=config.json --init-baseline
 *   php monitor.php --config=config.json --purge --jours=90
 *
 * Exit codes : 0 = OK, 1 = warnings, 2 = erreurs critiques
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\StatutExecution;
use SiteMonitor\Core\TypeRegle;
use SiteMonitor\Entite\Client;
use SiteMonitor\Entite\Execution;
use SiteMonitor\Entite\GroupeUrls;
use SiteMonitor\Entite\Modele;
use SiteMonitor\Entite\Regle;
use SiteMonitor\Entite\Url;
use SiteMonitor\Moteur\ClientHttp;
use SiteMonitor\Moteur\MoteurVerification;
use SiteMonitor\Moteur\RegistreVerificateurs;
use SiteMonitor\Stockage\DepotClient;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotGroupeUrls;
use SiteMonitor\Stockage\DepotModele;
use SiteMonitor\Stockage\DepotRegle;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotUrl;

// --- Arguments CLI ---
$options = getopt('', [
    'config:',
    'run',
    'client:',
    'group:',
    'url:',
    'import',
    'report',
    'check-ssl',
    'check-sitemap',
    'check-robots',
    'init-baseline',
    'purge',
    'jours:',
    'verbose',
    'quiet',
]);

$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);

function logCli(string $message, string $niveau = 'INFO'): void
{
    global $quiet;
    if ($quiet) {
        return;
    }
    $horodatage = date('Y-m-d H:i:s');
    $couleurs = [
        'INFO' => "\033[0m",
        'OK' => "\033[32m",
        'WARN' => "\033[33m",
        'ERROR' => "\033[31m",
        'CRIT' => "\033[1;31m",
    ];
    $c = $couleurs[$niveau] ?? "\033[0m";
    $reset = "\033[0m";
    fwrite(STDERR, "{$c}[{$horodatage}] [{$niveau}] {$message}{$reset}" . PHP_EOL);
}

// --- Initialisation ---
$configChemin = $options['config'] ?? 'config.json';
if (!file_exists($configChemin)) {
    logCli("Fichier de configuration introuvable : {$configChemin}", 'ERROR');
    logCli('Usage : php monitor.php --config=config.json --run', 'INFO');
    exit(2);
}

$config = json_decode(file_get_contents($configChemin), true);
if (!$config) {
    logCli("Erreur de syntaxe JSON dans : {$configChemin}", 'ERROR');
    exit(2);
}

// Configurer la base de donnees depuis le fichier config
if (isset($config['base_de_donnees'])) {
    $dbConf = $config['base_de_donnees'];
    $_ENV['SITE_MONITOR_DB_TYPE'] = $dbConf['type'] ?? 'sqlite';
    if (isset($dbConf['chemin'])) {
        $_ENV['SITE_MONITOR_DB_PATH'] = $dbConf['chemin'];
    }
    if (isset($dbConf['hote'])) {
        $_ENV['SITE_MONITOR_DB_HOST'] = $dbConf['hote'];
    }
    if (isset($dbConf['nom'])) {
        $_ENV['SITE_MONITOR_DB_NAME'] = $dbConf['nom'];
    }
    if (isset($dbConf['utilisateur'])) {
        $_ENV['SITE_MONITOR_DB_USER'] = $dbConf['utilisateur'];
    }
    if (isset($dbConf['mot_de_passe'])) {
        $_ENV['SITE_MONITOR_DB_PASS'] = $dbConf['mot_de_passe'];
    }
}

$db = Connexion::obtenir();
$migrateur = new Migrateur($db);
$migrateur->migrer();

logCli('Base de donnees initialisee (' . Connexion::pilote() . ')', 'OK');

// --- Import depuis config.json ---
if (isset($options['import'])) {
    logCli('Import des clients depuis la configuration...', 'INFO');
    importerDepuisConfig($db, $config);
    logCli('Import termine.', 'OK');
    exit(0);
}

// --- Purge ---
if (isset($options['purge'])) {
    $jours = (int) ($options['jours'] ?? 90);
    $depot = new DepotExecution($db);
    $nb = $depot->purger($jours);
    logCli("Purge : {$nb} executions supprimees (>{$jours} jours).", 'OK');
    exit(0);
}

// --- Verification ---
if (isset($options['run']) || isset($options['client']) || isset($options['url'])) {
    $codeRetour = executerVerifications($db, $config, $options);
    exit($codeRetour);
}

// --- Report ---
if (isset($options['report'])) {
    genererRapport($db);
    exit(0);
}

logCli('Aucune action specifiee. Utilisez --run, --import, --report ou --purge.', 'WARN');
exit(1);

// === FONCTIONS ===

function importerDepuisConfig(\PDO $db, array $config): void
{
    $depotClient = new DepotClient($db);
    $depotGroupe = new DepotGroupeUrls($db);
    $depotUrl = new DepotUrl($db);
    $depotModele = new DepotModele($db);
    $depotRegle = new DepotRegle($db);

    foreach ($config['clients'] ?? [] as $cc) {
        $existant = $depotClient->trouverParSlug($cc['slug'] ?? '');
        if ($existant) {
            logCli("Client '{$cc['nom']}' deja present, ignore.", 'WARN');
            $clientId = $existant->id;
        } else {
            $client = new Client(
                id: null,
                nom: $cc['nom'],
                slug: $cc['slug'] ?? preg_replace('/[^a-z0-9-]/', '-', strtolower($cc['nom'])),
                domaine: $cc['domaine'] ?? '',
                emailContact: $cc['email_contact'] ?? null,
                actif: true,
                configuration: null,
                creeLe: null,
                modifieLe: null,
            );
            $clientId = $depotClient->creer($client);
            logCli("Client '{$cc['nom']}' cree (ID: {$clientId}).", 'OK');
        }

        // Groupes
        foreach ($cc['groupes'] ?? [] as $gc) {
            $groupe = new GroupeUrls(
                id: null,
                clientId: $clientId,
                nom: $gc['nom'],
                description: $gc['description'] ?? null,
                ordreTri: 0,
                actif: true,
                planification: $gc['planification'] ?? null,
                creeLe: null,
                modifieLe: null,
            );
            $groupeId = $depotGroupe->creer($groupe);
            logCli("  Groupe '{$gc['nom']}' cree (ID: {$groupeId}).", 'OK');

            foreach ($gc['urls'] ?? [] as $uc) {
                $url = new Url(
                    id: null,
                    groupeId: $groupeId,
                    url: $uc['url'],
                    libelle: $uc['libelle'] ?? null,
                    actif: true,
                    derniereVerification: null,
                    dernierStatut: null,
                    notes: null,
                    creeLe: null,
                    modifieLe: null,
                );
                $urlId = $depotUrl->creer($url);
                logCli("    URL '{$uc['url']}' creee (ID: {$urlId}).", 'OK');
            }
        }

        // Modeles
        foreach ($cc['modeles'] ?? [] as $mc) {
            $modele = new Modele(
                id: null,
                clientId: $clientId,
                nom: $mc['nom'],
                description: $mc['description'] ?? null,
                estGlobal: false,
                creeLe: null,
                modifieLe: null,
            );
            $modeleId = $depotModele->creer($modele);
            logCli("  Modele '{$mc['nom']}' cree (ID: {$modeleId}).", 'OK');

            foreach ($mc['regles'] ?? [] as $ordre => $rc) {
                $regle = new Regle(
                    id: null,
                    modeleId: $modeleId,
                    typeRegle: TypeRegle::from($rc['type_regle']),
                    nom: $rc['nom'],
                    configuration: $rc['configuration'] ?? [],
                    severite: NiveauSeverite::from($rc['severite'] ?? 'erreur'),
                    ordreTri: $ordre,
                    actif: true,
                    creeLe: null,
                    modifieLe: null,
                );
                $depotRegle->creer($regle);
            }
            logCli("    " . count($mc['regles'] ?? []) . " regles creees.", 'OK');

            // Associer le modele a toutes les URLs du client
            $urls = $depotUrl->trouverActivesParClient($clientId);
            foreach ($urls as $url) {
                $depotUrl->associerModele($url->id, $modeleId);
            }
            logCli("    Modele associe a " . count($urls) . " URLs.", 'OK');
        }
    }
}

function executerVerifications(\PDO $db, array $config, array $options): int
{
    $depotClient = new DepotClient($db);
    $depotUrl = new DepotUrl($db);
    $depotRegle = new DepotRegle($db);
    $depotExecution = new DepotExecution($db);
    $depotResultat = new DepotResultatRegle($db);

    // Determiner quelles URLs verifier
    $urls = [];

    if (isset($options['client'])) {
        $client = $depotClient->trouverParSlug($options['client']);
        if (!$client) {
            logCli("Client introuvable : {$options['client']}", 'ERROR');
            return 2;
        }
        $urls = $depotUrl->trouverActivesParClient($client->id);
        logCli("Client '{$client->nom}' : " . count($urls) . " URLs a verifier.", 'INFO');
    } elseif (isset($options['run'])) {
        // Tous les clients actifs
        $clients = $depotClient->trouverTous(actifsUniquement: true);
        foreach ($clients as $client) {
            $urlsClient = $depotUrl->trouverActivesParClient($client->id);
            $urls = array_merge($urls, $urlsClient);
        }
        logCli(count($urls) . " URLs a verifier sur " . count($clients) . " clients.", 'INFO');
    }

    if (empty($urls)) {
        logCli('Aucune URL a verifier. Utilisez --import pour importer les donnees.', 'WARN');
        return 1;
    }

    // Creer l'execution
    $executionId = $depotExecution->creer(new Execution(
        id: null,
        clientId: null,
        groupeId: null,
        typeDeclencheur: 'cli',
        statut: StatutExecution::EnCours,
        urlsTotal: count($urls),
        urlsTraitees: 0,
        reglesTotal: 0,
        succes: 0,
        echecs: 0,
        avertissements: 0,
        dureeMs: null,
        demarreeLe: date('Y-m-d H:i:s'),
        termineeLe: null,
        creeLe: null,
    ));

    logCli("Execution #{$executionId} demarree.", 'INFO');

    // Configurer le moteur
    $clientHttp = new ClientHttp();
    $registre = RegistreVerificateurs::parDefaut();
    $moteur = new MoteurVerification(
        registre: $registre,
        clientHttp: $clientHttp,
        depotUrl: $depotUrl,
        depotRegle: $depotRegle,
        depotExecution: $depotExecution,
        depotResultat: $depotResultat,
    );

    global $verbose;
    $moteur->surProgression(function (int $traitees, int $total, string $url) use ($verbose) {
        if ($verbose) {
            logCli("  [{$traitees}/{$total}] {$url}", 'INFO');
        }
    });

    $moteur->executer($urls, $executionId, 1000);

    // Bilan
    $execution = $depotExecution->trouverParId($executionId);
    if ($execution) {
        logCli("Termine : {$execution->succes} succes, {$execution->echecs} echecs, {$execution->avertissements} avertissements", 'INFO');
        logCli("Taux de reussite : {$execution->tauxReussite()}%", $execution->echecs > 0 ? 'WARN' : 'OK');
        logCli("Duree : " . number_format(($execution->dureeMs ?? 0) / 1000, 1) . "s", 'INFO');

        if ($execution->echecs > 0) {
            // Afficher les echecs
            $echecs = $depotResultat->trouverEchecsParExecution($executionId);
            logCli("--- Echecs ---", 'ERROR');
            foreach ($echecs as $r) {
                $url = $depotUrl->trouverParId($r->urlId);
                logCli("  [{$r->severite->value}] {$url?->url} : {$r->message}", 'ERROR');
            }

            return $execution->avertissements > 0 && $execution->echecs === 0 ? 1 : 2;
        }
    }

    return 0;
}

function genererRapport(\PDO $db): void
{
    $depotClient = new DepotClient($db);
    $depotExecution = new DepotExecution($db);

    logCli('=== RAPPORT SITE MONITOR ===', 'INFO');

    $stats = $depotExecution->statistiquesGlobales();
    logCli("Executions totales : {$stats['total']}", 'INFO');
    logCli("En cours : {$stats['en_cours']}", 'INFO');
    logCli("Dernieres 24h : {$stats['dernieres_24h']}", 'INFO');
    logCli("Taux de reussite moyen : {$stats['taux_reussite']}%", 'INFO');

    $clients = $depotClient->statistiques();
    logCli('', 'INFO');
    logCli('--- Clients ---', 'INFO');
    foreach ($clients as $c) {
        $statut = $c['actif'] ? 'actif' : 'inactif';
        logCli("  {$c['nom']} ({$c['domaine']}) - {$c['nb_groupes']} groupes, {$c['nb_urls']} URLs [{$statut}]", 'INFO');
    }
}
