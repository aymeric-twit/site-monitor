<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de ressources externes.
 *
 * Recherche des patterns dans le code source de la page (analytics, tag manager,
 * pixels de tracking, etc.) et detecte les scripts inattendus.
 */
final class VerificateurRessourcesExternes implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'ressources_externes';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'presence_pattern';

        return match ($verification) {
            'presence_pattern' => $this->verifierPresencePattern($contexte, $config, $severite),
            'absence_pattern' => $this->verifierAbsencePattern($contexte, $config, $severite),
            'scripts_inattendus' => $this->verifierScriptsInattendus($contexte, $config, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification ressources externes inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie que tous les patterns requis sont presents dans le code source.
     * Utile pour s'assurer que les trackers (GA, GTM, pixels) sont bien en place.
     *
     * @param array<string, mixed> $config
     */
    private function verifierPresencePattern(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $patterns = $config['patterns'] ?? [];

        if ($patterns === []) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: 'Aucun pattern configure pour la verification de presence',
            );
        }

        $source = $contexte->corpsReponse;
        $patternsTrouves = [];
        $patternsManquants = [];

        foreach ($patterns as $pattern) {
            if ($this->patternPresent($source, $pattern)) {
                $patternsTrouves[] = $pattern;
            } else {
                $patternsManquants[] = $pattern;
            }
        }

        if ($patternsManquants !== []) {
            $nombreManquants = count($patternsManquants);
            $total = count($patterns);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nombreManquants}/{$total} pattern(s) manquant(s) dans le code source",
                valeurAttendue: "{$total} pattern(s) present(s)",
                valeurObtenue: count($patternsTrouves) . " trouve(s)",
                details: [
                    'patterns_trouves' => $patternsTrouves,
                    'patterns_manquants' => $patternsManquants,
                ],
            );
        }

        $total = count($patterns);

        return ResultatVerification::succes(
            message: "Tous les {$total} pattern(s) sont presents dans le code source",
            valeurObtenue: "{$total}/{$total}",
            details: ['patterns_trouves' => $patternsTrouves],
        );
    }

    /**
     * Verifie qu'aucun des patterns exclus n'est present dans le code source.
     * Utile pour s'assurer que certains scripts ont bien ete retires.
     *
     * @param array<string, mixed> $config
     */
    private function verifierAbsencePattern(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $patterns = $config['patterns'] ?? [];

        if ($patterns === []) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: 'Aucun pattern configure pour la verification d\'absence',
            );
        }

        $source = $contexte->corpsReponse;
        $patternsTrouves = [];

        foreach ($patterns as $pattern) {
            if ($this->patternPresent($source, $pattern)) {
                $patternsTrouves[] = $pattern;
            }
        }

        if ($patternsTrouves !== []) {
            $nombre = count($patternsTrouves);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nombre} pattern(s) interdit(s) detecte(s) dans le code source",
                valeurAttendue: '0 pattern interdit',
                valeurObtenue: "{$nombre} detecte(s)",
                details: ['patterns_detectes' => $patternsTrouves],
            );
        }

        return ResultatVerification::succes(
            message: 'Aucun pattern interdit detecte dans le code source',
            details: ['patterns_verifies' => $patterns],
        );
    }

    /**
     * Detecte les scripts externes inattendus (non repertories dans les patterns connus).
     * Extrait toutes les URLs de scripts du DOM et signale ceux qui ne correspondent
     * pas aux patterns exclus (connus/attendus).
     *
     * @param array<string, mixed> $config
     */
    private function verifierScriptsInattendus(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $patternsExclus = $config['patterns_exclus'] ?? [];

        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le DOM pour analyser les scripts',
            );
        }

        // Extraire toutes les sources de scripts externes
        $scripts = $xpath->query('//script[@src]');
        if ($scripts === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Erreur lors de la recherche des scripts dans le DOM',
            );
        }

        $scriptsExternes = [];
        foreach ($scripts as $script) {
            $src = $script->getAttribute('src');
            if ($src !== '') {
                $scriptsExternes[] = $src;
            }
        }

        if ($scriptsExternes === []) {
            return ResultatVerification::succes(
                message: 'Aucun script externe detecte sur la page',
            );
        }

        // Filtrer les scripts connus
        $scriptsInattendus = [];
        $scriptsConnus = [];

        foreach ($scriptsExternes as $scriptSrc) {
            $estConnu = false;

            foreach ($patternsExclus as $patternConnu) {
                if (str_contains($scriptSrc, $patternConnu)) {
                    $estConnu = true;
                    $scriptsConnus[] = $scriptSrc;
                    break;
                }
            }

            if (!$estConnu) {
                $scriptsInattendus[] = $scriptSrc;
            }
        }

        if ($scriptsInattendus !== []) {
            $nombre = count($scriptsInattendus);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nombre} script(s) externe(s) inattendu(s) detecte(s)",
                valeurAttendue: '0 script inattendu',
                valeurObtenue: "{$nombre} inattendu(s)",
                details: [
                    'scripts_inattendus' => $scriptsInattendus,
                    'scripts_connus' => $scriptsConnus,
                    'total_scripts_externes' => count($scriptsExternes),
                ],
            );
        }

        $totalConnus = count($scriptsConnus);

        return ResultatVerification::succes(
            message: "Tous les {$totalConnus} script(s) externe(s) sont connus/attendus",
            valeurObtenue: "{$totalConnus} script(s)",
            details: [
                'scripts_connus' => $scriptsConnus,
                'total_scripts_externes' => count($scriptsExternes),
            ],
        );
    }

    /**
     * Verifie si un pattern est present dans le code source.
     * Gere la recherche insensible a la casse pour les domaines
     * et sensible a la casse pour les identifiants.
     */
    private function patternPresent(string $source, string $pattern): bool
    {
        // Si le pattern ressemble a une regex (commence et finit par un delimiteur)
        if (preg_match('#^[/~#].*[/~#][gimsux]*$#', $pattern)) {
            return @preg_match($pattern, $source) === 1;
        }

        // Recherche simple insensible a la casse
        return str_contains(strtolower($source), strtolower($pattern));
    }
}
