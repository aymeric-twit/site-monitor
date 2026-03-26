<?php

declare(strict_types=1);

/**
 * Cron — Execute les planifications de verification dues.
 *
 * A appeler via le cron systeme toutes les 5 minutes :
 *   */5 * * * * php /chemin/vers/site-monitor/cron.php
 *
 * Ou via la plateforme si elle supporte les taches planifiees.
 */

set_time_limit(120);

require_once __DIR__ . '/boot.php';

use SiteMonitor\Core\Connexion;
use SiteMonitor\Core\Migrateur;
use SiteMonitor\Moteur\LanceurVerification;
use SiteMonitor\Stockage\DepotPlanification;

$horodatage = date('Y-m-d H:i:s');

try {
    // Si lance en CLI sans contexte plateforme, tenter de charger la config DB
    // depuis le .env ou les variables d'environnement
    $db = Connexion::obtenir();

    // Migrations (non-bloquantes)
    try {
        (new Migrateur($db))->migrer();
    } catch (\Throwable $e) {
        // Continuer meme si une migration echoue
    }

    $depot = new DepotPlanification($db);
    $lanceur = new LanceurVerification($db, __DIR__);

    // Trouver les planifications dues
    $dues = $depot->trouverDues();

    if (empty($dues)) {
        fwrite(STDERR, "[{$horodatage}] Aucune planification due.\n");
        exit(0);
    }

    $nbLancees = 0;

    foreach ($dues as $planif) {
        // Verifier la fenetre horaire si definie
        if ($planif->heureDebut !== null && $planif->heureFin !== null) {
            $maintenant = date('H:i');
            if ($maintenant < $planif->heureDebut || $maintenant > $planif->heureFin) {
                // Hors fenetre — reporter a la prochaine fenetre
                continue;
            }
        }

        // Verifier le jour de la semaine si defini
        if ($planif->joursSemaine !== null) {
            $jourActuel = (int) date('N'); // 1=lundi, 7=dimanche
            $joursAutorises = array_map('intval', explode(',', $planif->joursSemaine));
            if (!in_array($jourActuel, $joursAutorises, true)) {
                continue;
            }
        }

        try {
            $jobId = $lanceur->lancer([
                'client_id' => $planif->clientId,
                'groupe_id' => $planif->groupeId,
                'user_agent' => $planif->userAgent,
                'timeout' => $planif->timeoutSecondes,
                'delai_ms' => $planif->delaiEntreRequetesMs,
                'type_declencheur' => 'planification',
            ]);

            // Mettre a jour la planification
            $depot->marquerExecutee($planif->id, $planif->frequenceMinutes);

            fwrite(STDERR, "[{$horodatage}] Planification #{$planif->id} (client #{$planif->clientId}) lancee → job {$jobId}\n");
            $nbLancees++;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[{$horodatage}] ERREUR planification #{$planif->id} : {$e->getMessage()}\n");
        }
    }

    fwrite(STDERR, "[{$horodatage}] {$nbLancees} verification(s) planifiee(s) lancee(s).\n");
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[{$horodatage}] ERREUR CRON : {$e->getMessage()}\n");
    exit(1);
}
