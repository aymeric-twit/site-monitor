<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Entite\Alerte;
use SiteMonitor\Entite\ResultatRegle;
use SiteMonitor\Stockage\DepotAlerte;
use SiteMonitor\Stockage\DepotClient;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotUrl;

/**
 * Genere une alerte apres une execution contenant des echecs.
 *
 * Analyse les resultats, calcule la severite maximale,
 * construit un resume et persiste l'alerte en BDD.
 */
final class GenerateurAlertes
{
    public function __construct(
        private readonly DepotResultatRegle $depotResultat,
        private readonly DepotExecution $depotExecution,
        private readonly DepotClient $depotClient,
        private readonly DepotAlerte $depotAlerte,
        private readonly DepotUrl $depotUrl,
    ) {}

    /**
     * Genere une alerte pour une execution donnee.
     * Retourne null si aucun echec detecte.
     */
    public function generer(int $executionId): ?Alerte
    {
        $execution = $this->depotExecution->trouverParId($executionId);
        if ($execution === null || $execution->echecs === 0) {
            return null;
        }

        $client = $execution->clientId !== null
            ? $this->depotClient->trouverParId($execution->clientId)
            : null;

        $echecs = $this->depotResultat->trouverEchecsParExecution($executionId);
        if (empty($echecs)) {
            return null;
        }

        $severiteMax = $this->calculerSeveriteMax($echecs);
        $nomClient = $client !== null ? $client->nom : 'Client inconnu';
        $sujet = sprintf(
            '[Site Monitor] %s — %d echec(s) detecte(s)',
            $nomClient,
            count($echecs),
        );

        $corps = $this->construireCorps($echecs, $nomClient);

        $destinataires = '';
        if ($client !== null && !empty($client->emailContact)) {
            $destinataires = $client->emailContact;
        }

        $alerte = new Alerte(
            id: null,
            executionId: $executionId,
            clientId: $execution->clientId ?? 0,
            severite: $severiteMax,
            sujet: $sujet,
            corpsTexte: $corps,
            destinataires: $destinataires,
            envoyee: false,
            envoyeeLe: null,
            creeLe: null,
        );

        $alerteId = $this->depotAlerte->creer($alerte);

        return new Alerte(
            id: $alerteId,
            executionId: $alerte->executionId,
            clientId: $alerte->clientId,
            severite: $alerte->severite,
            sujet: $alerte->sujet,
            corpsTexte: $alerte->corpsTexte,
            destinataires: $alerte->destinataires,
            envoyee: $alerte->envoyee,
            envoyeeLe: $alerte->envoyeeLe,
            creeLe: $alerte->creeLe,
        );
    }

    /**
     * @param ResultatRegle[] $echecs
     */
    private function calculerSeveriteMax(array $echecs): NiveauSeverite
    {
        $priorites = [
            NiveauSeverite::Critique->value => 4,
            NiveauSeverite::Erreur->value => 3,
            NiveauSeverite::Avertissement->value => 2,
            NiveauSeverite::Info->value => 1,
        ];

        $max = NiveauSeverite::Info;
        $maxPriorite = 0;

        foreach ($echecs as $echec) {
            $priorite = $priorites[$echec->severite->value] ?? 0;
            if ($priorite > $maxPriorite) {
                $maxPriorite = $priorite;
                $max = $echec->severite;
            }
        }

        return $max;
    }

    /**
     * @param ResultatRegle[] $echecs
     */
    private function construireCorps(array $echecs, string $nomClient): string
    {
        // Grouper par URL
        $parUrl = [];
        foreach ($echecs as $echec) {
            $parUrl[$echec->urlId][] = $echec;
        }

        $lignes = [];
        $lignes[] = "Rapport de verification — {$nomClient}";
        $lignes[] = str_repeat('=', 50);
        $lignes[] = '';

        foreach ($parUrl as $urlId => $echecsUrl) {
            // Recuperer l'URL via le depot
            $url = $this->depotUrl->trouverParId($urlId);
            $urlTexte = $url !== null ? $url->url : "URL #{$urlId}";

            $lignes[] = "URL : {$urlTexte}";
            $lignes[] = str_repeat('-', 40);

            foreach ($echecsUrl as $echec) {
                $lignes[] = sprintf(
                    '  [%s] %s',
                    strtoupper($echec->severite->value),
                    $echec->message ?? 'Echec sans message',
                );
                if ($echec->valeurAttendue !== null) {
                    $lignes[] = "    Attendu : {$echec->valeurAttendue}";
                }
                if ($echec->valeurObtenue !== null) {
                    $lignes[] = "    Obtenu  : {$echec->valeurObtenue}";
                }
            }

            $lignes[] = '';
        }

        return implode("\n", $lignes);
    }
}
