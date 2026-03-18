<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Entite\ResultatRegle;

/**
 * Compare les resultats de deux executions consecutives pour detecter
 * les regressions (nouvelles defaillances) et recuperations (problemes resolus).
 */
final class ComparateurExecutions
{
    /**
     * Compare les resultats actuels avec ceux de l'execution precedente.
     *
     * @param ResultatRegle[] $resultatsActuels
     * @param ResultatRegle[] $resultatsPrecedents
     * @return array{
     *     nouvelles_defaillances: ResultatRegle[],
     *     recuperations: ResultatRegle[],
     *     defaillances_persistantes: ResultatRegle[],
     *     tendance: string
     * }
     */
    public function comparer(array $resultatsActuels, array $resultatsPrecedents): array
    {
        $indexPrecedents = $this->indexer($resultatsPrecedents);
        $indexActuels = $this->indexer($resultatsActuels);

        $nouvellesDefaillances = [];
        $defaillancesPersistantes = [];
        $recuperations = [];

        // Analyser les resultats actuels
        foreach ($resultatsActuels as $resultat) {
            $cle = $this->cle($resultat);
            $precedent = $indexPrecedents[$cle] ?? null;

            if (!$resultat->succes) {
                if ($precedent === null || $precedent->succes) {
                    // Passait avant (ou n'existait pas), echoue maintenant
                    $nouvellesDefaillances[] = $resultat;
                } else {
                    // Echouait deja avant
                    $defaillancesPersistantes[] = $resultat;
                }
            }
        }

        // Detecter les recuperations (echouait avant, passe maintenant)
        foreach ($resultatsPrecedents as $precedent) {
            if (!$precedent->succes) {
                $cle = $this->cle($precedent);
                $actuel = $indexActuels[$cle] ?? null;
                if ($actuel !== null && $actuel->succes) {
                    $recuperations[] = $actuel;
                }
            }
        }

        // Determiner la tendance
        $echecsActuels = count(array_filter($resultatsActuels, fn(ResultatRegle $r): bool => !$r->succes));
        $echecsPrecedents = count(array_filter($resultatsPrecedents, fn(ResultatRegle $r): bool => !$r->succes));

        if ($echecsActuels < $echecsPrecedents) {
            $tendance = 'amelioration';
        } elseif ($echecsActuels > $echecsPrecedents) {
            $tendance = 'degradation';
        } else {
            $tendance = 'stable';
        }

        return [
            'nouvelles_defaillances' => $nouvellesDefaillances,
            'recuperations' => $recuperations,
            'defaillances_persistantes' => $defaillancesPersistantes,
            'tendance' => $tendance,
        ];
    }

    /**
     * @param ResultatRegle[] $resultats
     * @return array<string, ResultatRegle>
     */
    private function indexer(array $resultats): array
    {
        $index = [];
        foreach ($resultats as $r) {
            $index[$this->cle($r)] = $r;
        }
        return $index;
    }

    private function cle(ResultatRegle $r): string
    {
        return $r->regleId . '_' . $r->urlId;
    }
}
