<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de comptage d'occurrences.
 *
 * Compte les occurrences d'un motif (texte brut, regex ou selecteur XPath)
 * dans le contenu de la reponse, puis compare avec la valeur attendue
 * selon l'operateur configure.
 */
final class VerificateurComptageOccurrences implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'comptage_occurrences';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $typeRecherche = $config['type_recherche'] ?? 'texte';
        $motif = $config['motif'] ?? '';

        if ($motif === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: 'Aucun motif de recherche configure',
            );
        }

        // Compter les occurrences selon le type de recherche
        $resultatComptage = match ($typeRecherche) {
            'texte' => $this->compterTexte($motif, $contexte),
            'regex' => $this->compterRegex($motif, $contexte),
            'xpath' => $this->compterXpath($motif, $contexte),
            default => null,
        };

        if ($resultatComptage === null) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de recherche inconnu : {$typeRecherche}",
            );
        }

        // Erreur lors du comptage
        if ($resultatComptage['erreur'] !== null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: $resultatComptage['erreur'],
                details: ['motif' => $motif, 'type_recherche' => $typeRecherche],
            );
        }

        $nombreObtenu = $resultatComptage['nombre'];

        // Comparer avec la valeur attendue
        return $this->comparerValeur($nombreObtenu, $config, $motif, $typeRecherche, $severite);
    }

    /**
     * Compte les occurrences d'un texte brut dans le corps de la reponse.
     *
     * @return array{nombre: int, erreur: ?string}
     */
    private function compterTexte(string $motif, ContexteVerification $contexte): array
    {
        $nombre = substr_count($contexte->corpsReponse, $motif);

        return ['nombre' => $nombre, 'erreur' => null];
    }

    /**
     * Compte les occurrences d'une expression reguliere dans le corps de la reponse.
     *
     * @return array{nombre: int, erreur: ?string}
     */
    private function compterRegex(string $motif, ContexteVerification $contexte): array
    {
        $resultat = @preg_match_all($motif, $contexte->corpsReponse, $correspondances);

        if ($resultat === false) {
            $erreur = preg_last_error_msg();
            return ['nombre' => 0, 'erreur' => "Expression reguliere invalide : {$erreur}"];
        }

        return ['nombre' => $resultat, 'erreur' => null];
    }

    /**
     * Compte les resultats d'une requete XPath.
     *
     * @return array{nombre: int, erreur: ?string}
     */
    private function compterXpath(string $motif, ContexteVerification $contexte): array
    {
        $xpath = $contexte->xpath();

        if ($xpath === null) {
            return ['nombre' => 0, 'erreur' => 'Impossible de parser le DOM pour la requete XPath'];
        }

        $resultats = @$xpath->query($motif);

        if ($resultats === false) {
            return ['nombre' => 0, 'erreur' => "Expression XPath invalide : {$motif}"];
        }

        return ['nombre' => $resultats->length, 'erreur' => null];
    }

    /**
     * Compare le nombre obtenu avec la valeur attendue selon l'operateur configure.
     *
     * @param array<string, mixed> $config
     */
    private function comparerValeur(
        int $nombreObtenu,
        array $config,
        string $motif,
        string $typeRecherche,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $operateur = $config['operateur'] ?? 'egal';
        $details = [
            'motif' => $motif,
            'type_recherche' => $typeRecherche,
            'nombre_obtenu' => $nombreObtenu,
            'operateur' => $operateur,
        ];

        $resultat = match ($operateur) {
            'egal' => $this->verifierEgal($nombreObtenu, $config, $details, $severite),
            'superieur' => $this->verifierSuperieur($nombreObtenu, $config, $details, $severite),
            'inferieur' => $this->verifierInferieur($nombreObtenu, $config, $details, $severite),
            'entre' => $this->verifierEntre($nombreObtenu, $config, $details, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Operateur de comparaison inconnu : {$operateur}",
            ),
        };

        return $resultat;
    }

    /**
     * Verifie que le nombre est egal a la valeur attendue.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $details
     */
    private function verifierEgal(
        int $nombreObtenu,
        array $config,
        array $details,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $valeurAttendue = (int) ($config['valeur_attendue'] ?? 0);

        if ($nombreObtenu !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Nombre d'occurrences incorrect : {$nombreObtenu} au lieu de {$valeurAttendue}",
                valeurAttendue: (string) $valeurAttendue,
                valeurObtenue: (string) $nombreObtenu,
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'occurrences conforme : {$nombreObtenu}",
            valeurObtenue: (string) $nombreObtenu,
            details: $details,
        );
    }

    /**
     * Verifie que le nombre est superieur a la valeur attendue.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $details
     */
    private function verifierSuperieur(
        int $nombreObtenu,
        array $config,
        array $details,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $valeurAttendue = (int) ($config['valeur_attendue'] ?? 0);

        if ($nombreObtenu <= $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Nombre d'occurrences insuffisant : {$nombreObtenu} (attendu > {$valeurAttendue})",
                valeurAttendue: "> {$valeurAttendue}",
                valeurObtenue: (string) $nombreObtenu,
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'occurrences conforme : {$nombreObtenu} (> {$valeurAttendue})",
            valeurObtenue: (string) $nombreObtenu,
            details: $details,
        );
    }

    /**
     * Verifie que le nombre est inferieur a la valeur attendue.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $details
     */
    private function verifierInferieur(
        int $nombreObtenu,
        array $config,
        array $details,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $valeurAttendue = (int) ($config['valeur_attendue'] ?? 0);

        if ($nombreObtenu >= $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Nombre d'occurrences trop eleve : {$nombreObtenu} (attendu < {$valeurAttendue})",
                valeurAttendue: "< {$valeurAttendue}",
                valeurObtenue: (string) $nombreObtenu,
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'occurrences conforme : {$nombreObtenu} (< {$valeurAttendue})",
            valeurObtenue: (string) $nombreObtenu,
            details: $details,
        );
    }

    /**
     * Verifie que le nombre est entre valeur_min et valeur_max (inclus).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $details
     */
    private function verifierEntre(
        int $nombreObtenu,
        array $config,
        array $details,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $valeurMin = (int) ($config['valeur_min'] ?? 0);
        $valeurMax = (int) ($config['valeur_max'] ?? PHP_INT_MAX);

        if ($nombreObtenu < $valeurMin || $nombreObtenu > $valeurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Nombre d'occurrences hors plage : {$nombreObtenu} (attendu entre {$valeurMin} et {$valeurMax})",
                valeurAttendue: "[{$valeurMin}, {$valeurMax}]",
                valeurObtenue: (string) $nombreObtenu,
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'occurrences conforme : {$nombreObtenu} (entre {$valeurMin} et {$valeurMax})",
            valeurObtenue: (string) $nombreObtenu,
            details: $details,
        );
    }
}
