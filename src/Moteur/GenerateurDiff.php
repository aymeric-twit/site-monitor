<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

/**
 * Genere un diff ligne par ligne entre deux contenus HTML.
 *
 * Supporte deux modes : texte visible (strip tags) et HTML brut.
 * Retourne un tableau de lignes typees (contexte/ajoute/supprime)
 * avec des statistiques de changement.
 */
final class GenerateurDiff
{
    private int $lignesContexte;

    public function __construct(int $lignesContexte = 3)
    {
        $this->lignesContexte = $lignesContexte;
    }

    /**
     * Compare deux contenus et retourne le diff.
     *
     * @return array{lignes_ajoutees: int, lignes_supprimees: int, pourcentage_changement: float, diff: array}
     */
    public function comparer(string $ancien, string $nouveau, bool $modeHtml = false): array
    {
        $lignesA = $this->preparerLignes($ancien, $modeHtml);
        $lignesB = $this->preparerLignes($nouveau, $modeHtml);

        $operations = $this->calculerDiff($lignesA, $lignesB);

        // Filtrer pour ne garder que les zones avec des changements + contexte
        $diffFiltre = $this->filtrerAvecContexte($operations);

        $ajoutees = 0;
        $supprimees = 0;
        foreach ($operations as $op) {
            if ($op['type'] === 'ajoute') $ajoutees++;
            if ($op['type'] === 'supprime') $supprimees++;
        }

        $totalLignes = max(count($lignesA), count($lignesB), 1);
        $pourcentage = round(($ajoutees + $supprimees) * 100.0 / $totalLignes, 1);

        return [
            'lignes_ajoutees' => $ajoutees,
            'lignes_supprimees' => $supprimees,
            'pourcentage_changement' => $pourcentage,
            'total_lignes_ancien' => count($lignesA),
            'total_lignes_nouveau' => count($lignesB),
            'diff' => $diffFiltre,
        ];
    }

    /**
     * Prepare les lignes pour la comparaison.
     *
     * @return string[]
     */
    private function preparerLignes(string $contenu, bool $modeHtml): array
    {
        if (!$modeHtml) {
            // Mode texte visible : supprimer les tags, scripts, styles
            $contenu = preg_replace('#<script[^>]*>.*?</script>#si', '', $contenu);
            $contenu = preg_replace('#<style[^>]*>.*?</style>#si', '', $contenu);
            // Ajouter des sauts de ligne avant les balises de bloc
            $contenu = preg_replace('#<(br|p|div|li|tr|h[1-6]|section|article|header|footer|nav|main)[^>]*>#i', "\n", $contenu);
            $contenu = strip_tags($contenu);
            $contenu = html_entity_decode($contenu, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Normaliser les espaces et decouper en lignes
        $lignes = explode("\n", $contenu);
        $lignes = array_map(fn(string $l) => trim($l), $lignes);
        $lignes = array_values(array_filter($lignes, fn(string $l) => $l !== ''));

        return $lignes;
    }

    /**
     * Calcule le diff entre deux tableaux de lignes (algorithme LCS simplifie).
     *
     * @param string[] $a
     * @param string[] $b
     * @return array<int, array{type: string, ligne: string}>
     */
    private function calculerDiff(array $a, array $b): array
    {
        $na = count($a);
        $nb = count($b);

        // Pour les gros fichiers, utiliser une approche simplifiee
        if ($na > 2000 || $nb > 2000) {
            return $this->diffSimple($a, $b);
        }

        // Table LCS
        $lcs = [];
        for ($i = 0; $i <= $na; $i++) {
            for ($j = 0; $j <= $nb; $j++) {
                if ($i === 0 || $j === 0) {
                    $lcs[$i][$j] = 0;
                } elseif ($a[$i - 1] === $b[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Reconstruire le diff depuis la table LCS
        $result = [];
        $i = $na;
        $j = $nb;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                array_unshift($result, ['type' => 'contexte', 'ligne' => $a[$i - 1]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($result, ['type' => 'ajoute', 'ligne' => $b[$j - 1]]);
                $j--;
            } else {
                array_unshift($result, ['type' => 'supprime', 'ligne' => $a[$i - 1]]);
                $i--;
            }
        }

        return $result;
    }

    /**
     * Diff simplifie pour les gros fichiers (sans LCS complet).
     */
    private function diffSimple(array $a, array $b): array
    {
        $setB = array_flip($b);
        $setA = array_flip($a);
        $result = [];

        foreach ($a as $ligne) {
            if (isset($setB[$ligne])) {
                $result[] = ['type' => 'contexte', 'ligne' => $ligne];
            } else {
                $result[] = ['type' => 'supprime', 'ligne' => $ligne];
            }
        }

        foreach ($b as $ligne) {
            if (!isset($setA[$ligne])) {
                $result[] = ['type' => 'ajoute', 'ligne' => $ligne];
            }
        }

        return $result;
    }

    /**
     * Filtre le diff pour ne garder que les zones avec changements + N lignes de contexte.
     */
    private function filtrerAvecContexte(array $operations): array
    {
        $n = count($operations);
        if ($n === 0) return [];

        // Marquer les lignes proches des changements
        $garder = array_fill(0, $n, false);

        for ($i = 0; $i < $n; $i++) {
            if ($operations[$i]['type'] !== 'contexte') {
                // Marquer cette ligne et les lignes de contexte autour
                for ($j = max(0, $i - $this->lignesContexte); $j <= min($n - 1, $i + $this->lignesContexte); $j++) {
                    $garder[$j] = true;
                }
            }
        }

        $resultat = [];
        $dernierGarde = false;

        for ($i = 0; $i < $n; $i++) {
            if ($garder[$i]) {
                if (!$dernierGarde && !empty($resultat)) {
                    $resultat[] = ['type' => 'separateur', 'ligne' => '...'];
                }
                $resultat[] = $operations[$i];
                $dernierGarde = true;
            } else {
                $dernierGarde = false;
            }
        }

        return $resultat;
    }
}
