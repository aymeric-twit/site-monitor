<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des en-tetes HTTP de la reponse.
 *
 * Supporte les operations : present, absent, egal, contient, ne_contient_pas, regex.
 */
final class VerificateurEnTeteHttp implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'en_tete_http';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;

        $nomEnTete = $config['nom_entete'] ?? '';
        $operation = $config['operation'] ?? 'present';
        $valeurAttendue = $config['valeur_attendue'] ?? '';

        if (empty($nomEnTete)) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Configuration invalide : nom d'en-tete manquant",
            );
        }

        $enTetePresent = $contexte->aEnTete($nomEnTete);
        $valeurObtenue = $contexte->enTete($nomEnTete);

        return match ($operation) {
            'present' => $this->verifierPresent($nomEnTete, $enTetePresent, $valeurObtenue, $severite),
            'absent' => $this->verifierAbsent($nomEnTete, $enTetePresent, $severite),
            'egal' => $this->verifierEgal($nomEnTete, $enTetePresent, $valeurObtenue, $valeurAttendue, $severite),
            'contient' => $this->verifierContient($nomEnTete, $enTetePresent, $valeurObtenue, $valeurAttendue, $severite),
            'ne_contient_pas' => $this->verifierNeContientPas($nomEnTete, $enTetePresent, $valeurObtenue, $valeurAttendue, $severite),
            'regex' => $this->verifierRegex($nomEnTete, $enTetePresent, $valeurObtenue, $valeurAttendue, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Operation inconnue : '{$operation}'",
            ),
        };
    }

    private function verifierPresent(
        string $nomEnTete,
        bool $present,
        ?string $valeurObtenue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if (!$present) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' absent de la reponse",
                valeurAttendue: 'present',
                valeurObtenue: 'absent',
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' present",
            valeurObtenue: $valeurObtenue,
        );
    }

    private function verifierAbsent(
        string $nomEnTete,
        bool $present,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($present) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' present alors qu'il devrait etre absent",
                valeurAttendue: 'absent',
                valeurObtenue: 'present',
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' correctement absent",
        );
    }

    private function verifierEgal(
        string $nomEnTete,
        bool $present,
        ?string $valeurObtenue,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if (!$present) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' absent, impossible de comparer la valeur",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: '(absent)',
            );
        }

        if ($valeurObtenue !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' : valeur differente de celle attendue",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $valeurObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' correspond a la valeur attendue",
            valeurObtenue: $valeurObtenue,
        );
    }

    private function verifierContient(
        string $nomEnTete,
        bool $present,
        ?string $valeurObtenue,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if (!$present) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' absent, impossible de verifier le contenu",
                valeurAttendue: "contient '{$valeurAttendue}'",
                valeurObtenue: '(absent)',
            );
        }

        if (str_contains((string) $valeurObtenue, $valeurAttendue) === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' ne contient pas '{$valeurAttendue}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $valeurObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' contient '{$valeurAttendue}'",
            valeurObtenue: $valeurObtenue,
        );
    }

    private function verifierNeContientPas(
        string $nomEnTete,
        bool $present,
        ?string $valeurObtenue,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        // Si l'en-tete est absent, il ne contient evidemment pas la valeur
        if (!$present) {
            return ResultatVerification::succes(
                message: "En-tete '{$nomEnTete}' absent (ne contient donc pas '{$valeurAttendue}')",
            );
        }

        if (str_contains((string) $valeurObtenue, $valeurAttendue)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' contient '{$valeurAttendue}' alors qu'il ne devrait pas",
                valeurAttendue: "ne contient pas '{$valeurAttendue}'",
                valeurObtenue: $valeurObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' ne contient pas '{$valeurAttendue}'",
            valeurObtenue: $valeurObtenue,
        );
    }

    private function verifierRegex(
        string $nomEnTete,
        bool $present,
        ?string $valeurObtenue,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if (!$present) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' absent, impossible de tester l'expression reguliere",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: '(absent)',
            );
        }

        // Validation de la regex avant utilisation
        if (@preg_match($valeurAttendue, '') === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Expression reguliere invalide : '{$valeurAttendue}'",
            );
        }

        if (preg_match($valeurAttendue, (string) $valeurObtenue) !== 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "En-tete '{$nomEnTete}' ne correspond pas au pattern '{$valeurAttendue}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $valeurObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "En-tete '{$nomEnTete}' correspond au pattern regex",
            valeurObtenue: $valeurObtenue,
        );
    }
}
