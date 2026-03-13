<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur XPath : interroge le DOM de la reponse avec des expressions XPath.
 *
 * Supporte les operations : existe, absent, compte_exact, compte_min, compte_max,
 * texte_egal, texte_contient, texte_regex, attribut_egal.
 */
final class VerificateurXPath implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'xpath';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;

        $expression = $config['expression'] ?? '';
        $operation = $config['operation'] ?? 'existe';
        $valeurAttendue = $config['valeur_attendue'] ?? '';
        $attribut = $config['attribut'] ?? '';

        if ($expression === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Configuration invalide : expression XPath manquante",
            );
        }

        $xpath = $contexte->xpath();

        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Impossible de parser le DOM de la reponse (corps vide ou invalide)",
            );
        }

        // Execution de la requete XPath
        $resultat = @$xpath->query($expression);

        if ($resultat === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Expression XPath invalide : '{$expression}'",
            );
        }

        return match ($operation) {
            'existe' => $this->verifierExiste($expression, $resultat, $severite),
            'absent' => $this->verifierAbsent($expression, $resultat, $severite),
            'compte_exact' => $this->verifierCompteExact($expression, $resultat, $valeurAttendue, $severite),
            'compte_min' => $this->verifierCompteMin($expression, $resultat, $valeurAttendue, $severite),
            'compte_max' => $this->verifierCompteMax($expression, $resultat, $valeurAttendue, $severite),
            'texte_egal' => $this->verifierTexteEgal($expression, $resultat, $valeurAttendue, $severite),
            'texte_contient' => $this->verifierTexteContient($expression, $resultat, $valeurAttendue, $severite),
            'texte_regex' => $this->verifierTexteRegex($expression, $resultat, $valeurAttendue, $severite),
            'attribut_egal' => $this->verifierAttributEgal($expression, $resultat, $attribut, $valeurAttendue, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Operation XPath inconnue : '{$operation}'",
            ),
        };
    }

    private function verifierExiste(
        string $expression,
        \DOMNodeList $resultat,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultat->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour l'expression XPath '{$expression}'",
                valeurAttendue: '>= 1 element',
                valeurObtenue: '0 element',
            );
        }

        return ResultatVerification::succes(
            message: "Element(s) trouve(s) : {$resultat->length} resultat(s) pour '{$expression}'",
            valeurObtenue: (string) $resultat->length,
        );
    }

    private function verifierAbsent(
        string $expression,
        \DOMNodeList $resultat,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultat->length > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Element(s) present(s) alors qu'ils devraient etre absents ({$resultat->length} trouve(s))",
                valeurAttendue: '0 element',
                valeurObtenue: "{$resultat->length} element(s)",
            );
        }

        return ResultatVerification::succes(
            message: "Aucun element trouve pour '{$expression}' (attendu)",
        );
    }

    private function verifierCompteExact(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $compteAttendu = (int) $valeurAttendue;
        $compteObtenu = $resultat->length;

        if ($compteObtenu !== $compteAttendu) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Nombre d'elements incorrect pour '{$expression}' : {$compteObtenu} au lieu de {$compteAttendu}",
                valeurAttendue: (string) $compteAttendu,
                valeurObtenue: (string) $compteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'elements conforme : {$compteObtenu}",
            valeurObtenue: (string) $compteObtenu,
        );
    }

    private function verifierCompteMin(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $compteMin = (int) $valeurAttendue;
        $compteObtenu = $resultat->length;

        if ($compteObtenu < $compteMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop peu d'elements pour '{$expression}' : {$compteObtenu} (minimum : {$compteMin})",
                valeurAttendue: ">= {$compteMin}",
                valeurObtenue: (string) $compteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'elements suffisant : {$compteObtenu} (minimum : {$compteMin})",
            valeurObtenue: (string) $compteObtenu,
        );
    }

    private function verifierCompteMax(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $compteMax = (int) $valeurAttendue;
        $compteObtenu = $resultat->length;

        if ($compteObtenu > $compteMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop d'elements pour '{$expression}' : {$compteObtenu} (maximum : {$compteMax})",
                valeurAttendue: "<= {$compteMax}",
                valeurObtenue: (string) $compteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Nombre d'elements dans la limite : {$compteObtenu} (maximum : {$compteMax})",
            valeurObtenue: (string) $compteObtenu,
        );
    }

    private function verifierTexteEgal(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultat->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$expression}', impossible de comparer le texte",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: '(absent)',
            );
        }

        $texteObtenu = trim($resultat->item(0)?->textContent ?? '');

        if ($texteObtenu !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Texte different de la valeur attendue pour '{$expression}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $texteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Texte conforme pour '{$expression}'",
            valeurObtenue: $texteObtenu,
        );
    }

    private function verifierTexteContient(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultat->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$expression}', impossible de verifier le contenu textuel",
                valeurAttendue: "contient '{$valeurAttendue}'",
                valeurObtenue: '(absent)',
            );
        }

        $texteObtenu = trim($resultat->item(0)?->textContent ?? '');

        if (!str_contains($texteObtenu, $valeurAttendue)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Texte de l'element ne contient pas '{$valeurAttendue}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $texteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Texte contient '{$valeurAttendue}'",
            valeurObtenue: $texteObtenu,
        );
    }

    private function verifierTexteRegex(
        string $expression,
        \DOMNodeList $resultat,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultat->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$expression}', impossible de tester la regex",
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

        $texteObtenu = trim($resultat->item(0)?->textContent ?? '');

        if (preg_match($valeurAttendue, $texteObtenu) !== 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Texte ne correspond pas au pattern '{$valeurAttendue}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $texteObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Texte correspond au pattern regex",
            valeurObtenue: $texteObtenu,
        );
    }

    private function verifierAttributEgal(
        string $expression,
        \DOMNodeList $resultat,
        string $attribut,
        string $valeurAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($attribut === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Configuration invalide : nom d'attribut manquant pour l'operation 'attribut_egal'",
            );
        }

        if ($resultat->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$expression}', impossible de verifier l'attribut '{$attribut}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: '(absent)',
            );
        }

        $noeud = $resultat->item(0);

        if (!$noeud instanceof \DOMElement) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le noeud trouve n'est pas un element DOM (impossible de lire l'attribut)",
            );
        }

        if (!$noeud->hasAttribute($attribut)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "L'element ne possede pas l'attribut '{$attribut}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: '(attribut absent)',
            );
        }

        $valeurObtenue = $noeud->getAttribute($attribut);

        if ($valeurObtenue !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Attribut '{$attribut}' : valeur differente de celle attendue",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $valeurObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "Attribut '{$attribut}' conforme",
            valeurObtenue: $valeurObtenue,
        );
    }
}
