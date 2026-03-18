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
 * Genere une alerte apres une execution en comparant avec l'execution precedente.
 *
 * Detecte les regressions (nouvelles defaillances), les recuperations
 * (problemes resolus), et les defaillances persistantes.
 */
final class GenerateurAlertes
{
    private readonly ComparateurExecutions $comparateur;

    public function __construct(
        private readonly DepotResultatRegle $depotResultat,
        private readonly DepotExecution $depotExecution,
        private readonly DepotClient $depotClient,
        private readonly DepotAlerte $depotAlerte,
        private readonly DepotUrl $depotUrl,
    ) {
        $this->comparateur = new ComparateurExecutions();
    }

    /**
     * Genere une alerte pour une execution donnee.
     * Compare avec l'execution precedente pour detecter regressions et recuperations.
     * Retourne null si aucun changement notable.
     */
    public function generer(int $executionId): ?Alerte
    {
        $execution = $this->depotExecution->trouverParId($executionId);
        if ($execution === null) {
            return null;
        }

        $client = $execution->clientId !== null
            ? $this->depotClient->trouverParId($execution->clientId)
            : null;
        $nomClient = $client !== null ? $client->nom : 'Client inconnu';

        // Charger les resultats actuels
        $resultatsActuels = $this->depotResultat->trouverParExecution($executionId);
        $echecsActuels = array_filter($resultatsActuels, fn(ResultatRegle $r): bool => !$r->succes);

        // Charger les resultats precedents pour comparaison
        $executionPrecedente = $this->depotExecution->trouverPrecedente($executionId);
        $rapport = null;

        if ($executionPrecedente !== null) {
            $resultatsPrecedents = $this->depotResultat->trouverParExecution($executionPrecedente->id);
            $rapport = $this->comparateur->comparer($resultatsActuels, $resultatsPrecedents);
        }

        // Pas d'echec et pas de recuperation notable → pas d'alerte
        $nbEchecs = count($echecsActuels);
        $nbRecuperations = $rapport !== null ? count($rapport['recuperations']) : 0;
        $nbNouvellesDefaillances = $rapport !== null ? count($rapport['nouvelles_defaillances']) : 0;

        if ($nbEchecs === 0 && $nbRecuperations === 0) {
            return null;
        }

        // Determiner le type d'alerte
        $typeAlerte = 'echec';
        if ($nbEchecs === 0 && $nbRecuperations > 0) {
            $typeAlerte = 'recuperation';
        } elseif ($nbNouvellesDefaillances > 0) {
            $typeAlerte = 'regression';
        }

        // Severite
        $severite = $nbEchecs > 0
            ? $this->calculerSeveriteMax($echecsActuels)
            : NiveauSeverite::Info;

        // Sujet enrichi
        $sujet = $this->construireSujet($nomClient, $nbEchecs, $nbNouvellesDefaillances, $nbRecuperations, $typeAlerte);

        // Corps enrichi
        $corps = $this->construireCorps($echecsActuels, $nomClient, $rapport);

        $destinataires = '';
        if ($client !== null && !empty($client->emailContact)) {
            $destinataires = $client->emailContact;
        }

        $alerte = new Alerte(
            id: null,
            executionId: $executionId,
            clientId: $execution->clientId ?? 0,
            severite: $severite,
            sujet: $sujet,
            corpsTexte: $corps,
            destinataires: $destinataires,
            envoyee: false,
            envoyeeLe: null,
            creeLe: null,
            typeAlerte: $typeAlerte,
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
            typeAlerte: $alerte->typeAlerte,
        );
    }

    private function construireSujet(
        string $nomClient,
        int $nbEchecs,
        int $nbNouvelles,
        int $nbRecuperations,
        string $typeAlerte,
    ): string {
        if ($typeAlerte === 'recuperation') {
            return sprintf('[Site Monitor] %s — Tous les problemes resolus', $nomClient);
        }

        $parties = [];
        $parties[] = sprintf('%d echec(s)', $nbEchecs);

        if ($nbNouvelles > 0) {
            $parties[] = sprintf('+%d nouveau(x)', $nbNouvelles);
        }
        if ($nbRecuperations > 0) {
            $parties[] = sprintf('%d resolu(s)', $nbRecuperations);
        }

        return sprintf('[Site Monitor] %s — %s', $nomClient, implode(', ', $parties));
    }

    /**
     * @param ResultatRegle[] $echecs
     * @param array|null $rapport
     */
    private function construireCorps(array $echecs, string $nomClient, ?array $rapport): string
    {
        $lignes = [];
        $lignes[] = "Rapport de verification — {$nomClient}";
        $lignes[] = str_repeat('=', 50);
        $lignes[] = '';

        // Section comparaison si disponible
        if ($rapport !== null) {
            $tendanceLabel = match ($rapport['tendance']) {
                'amelioration' => 'AMELIORATION',
                'degradation' => 'DEGRADATION',
                default => 'STABLE',
            };
            $lignes[] = "Tendance : {$tendanceLabel}";
            $lignes[] = '';

            if (!empty($rapport['nouvelles_defaillances'])) {
                $lignes[] = '--- NOUVEAUX PROBLEMES ---';
                foreach ($rapport['nouvelles_defaillances'] as $r) {
                    $lignes[] = sprintf('  [%s] %s', strtoupper($r->severite->value), $r->message ?? 'Echec');
                    $url = $this->depotUrl->trouverParId($r->urlId);
                    if ($url !== null) {
                        $lignes[] = "    URL : {$url->url}";
                    }
                }
                $lignes[] = '';
            }

            if (!empty($rapport['recuperations'])) {
                $lignes[] = '--- PROBLEMES RESOLUS ---';
                foreach ($rapport['recuperations'] as $r) {
                    $url = $this->depotUrl->trouverParId($r->urlId);
                    $urlTexte = $url !== null ? $url->url : "URL #{$r->urlId}";
                    $lignes[] = "  [OK] {$urlTexte}";
                }
                $lignes[] = '';
            }
        }

        // Section echecs (existant)
        if (!empty($echecs)) {
            $parUrl = [];
            foreach ($echecs as $echec) {
                $parUrl[$echec->urlId][] = $echec;
            }

            $lignes[] = '--- ECHECS DETAILS ---';
            foreach ($parUrl as $urlId => $echecsUrl) {
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
        }

        return implode("\n", $lignes);
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
}
