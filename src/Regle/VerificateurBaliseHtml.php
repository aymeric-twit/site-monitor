<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur generique de balises HTML.
 *
 * Verifie l'existence, l'absence, le comptage, le contenu ou un attribut
 * d'elements HTML identifies par un selecteur CSS simplifie (converti en XPath).
 */
final class VerificateurBaliseHtml implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'balise_html';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $selecteur = $config['selecteur'] ?? '';
        $operation = $config['operation'] ?? 'existe';

        if ($selecteur === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: 'Aucun selecteur HTML configure',
            );
        }

        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le DOM HTML',
            );
        }

        // Convertir le selecteur CSS en XPath
        $expressionXpath = $this->cssVersXpath($selecteur);
        $resultats = @$xpath->query($expressionXpath);

        if ($resultats === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Selecteur invalide : {$selecteur} (XPath genere : {$expressionXpath})",
            );
        }

        return match ($operation) {
            'existe' => $this->verifierExiste($resultats, $selecteur, $severite),
            'absent' => $this->verifierAbsent($resultats, $selecteur, $severite),
            'compte' => $this->verifierCompte($resultats, $selecteur, $config, $severite),
            'contenu' => $this->verifierContenu($resultats, $selecteur, $config, $severite),
            'attribut' => $this->verifierAttribut($resultats, $selecteur, $config, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Operation inconnue : {$operation}",
            ),
        };
    }

    /**
     * Verifie qu'au moins un element correspond au selecteur.
     */
    private function verifierExiste(
        \DOMNodeList $resultats,
        string $selecteur,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultats->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour le selecteur '{$selecteur}'",
                valeurAttendue: '>= 1',
                valeurObtenue: '0',
            );
        }

        return ResultatVerification::succes(
            message: "{$resultats->length} element(s) trouve(s) pour '{$selecteur}'",
            valeurObtenue: (string) $resultats->length,
        );
    }

    /**
     * Verifie qu'aucun element ne correspond au selecteur.
     */
    private function verifierAbsent(
        \DOMNodeList $resultats,
        string $selecteur,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultats->length > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$resultats->length} element(s) trouve(s) pour '{$selecteur}' alors qu'il ne devrait y en avoir aucun",
                valeurAttendue: '0',
                valeurObtenue: (string) $resultats->length,
            );
        }

        return ResultatVerification::succes(
            message: "Aucun element trouve pour '{$selecteur}' (conforme)",
            valeurObtenue: '0',
        );
    }

    /**
     * Verifie que le nombre d'elements est dans la plage attendue.
     *
     * @param array<string, mixed> $config
     */
    private function verifierCompte(
        \DOMNodeList $resultats,
        string $selecteur,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $nombre = $resultats->length;
        $nombreMin = isset($config['nombre_min']) ? (int) $config['nombre_min'] : null;
        $nombreMax = isset($config['nombre_max']) ? (int) $config['nombre_max'] : null;
        $valeurAttendue = isset($config['valeur_attendue']) ? (int) $config['valeur_attendue'] : null;

        // Verification de valeur exacte
        if ($valeurAttendue !== null) {
            if ($nombre !== $valeurAttendue) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Nombre d'elements incorrect pour '{$selecteur}' : {$nombre} au lieu de {$valeurAttendue}",
                    valeurAttendue: (string) $valeurAttendue,
                    valeurObtenue: (string) $nombre,
                );
            }

            return ResultatVerification::succes(
                message: "Nombre d'elements conforme pour '{$selecteur}' : {$nombre}",
                valeurObtenue: (string) $nombre,
            );
        }

        // Verification de plage
        if ($nombreMin !== null && $nombre < $nombreMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop peu d'elements pour '{$selecteur}' : {$nombre} (minimum : {$nombreMin})",
                valeurAttendue: ">= {$nombreMin}",
                valeurObtenue: (string) $nombre,
            );
        }

        if ($nombreMax !== null && $nombre > $nombreMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop d'elements pour '{$selecteur}' : {$nombre} (maximum : {$nombreMax})",
                valeurAttendue: "<= {$nombreMax}",
                valeurObtenue: (string) $nombre,
            );
        }

        $plageTexte = match (true) {
            $nombreMin !== null && $nombreMax !== null => "[{$nombreMin}, {$nombreMax}]",
            $nombreMin !== null => ">= {$nombreMin}",
            $nombreMax !== null => "<= {$nombreMax}",
            default => 'sans contrainte',
        };

        return ResultatVerification::succes(
            message: "Nombre d'elements conforme pour '{$selecteur}' : {$nombre} ({$plageTexte})",
            valeurObtenue: (string) $nombre,
        );
    }

    /**
     * Verifie le contenu textuel du premier element correspondant.
     *
     * @param array<string, mixed> $config
     */
    private function verifierContenu(
        \DOMNodeList $resultats,
        string $selecteur,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($resultats->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$selecteur}'",
            );
        }

        $element = $resultats->item(0);
        $contenuObtenu = trim($element?->textContent ?? '');
        $valeurAttendue = $config['valeur_attendue'] ?? null;

        if ($valeurAttendue === null) {
            // Pas de valeur attendue : on verifie juste que le contenu est non vide
            if ($contenuObtenu === '') {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "L'element '{$selecteur}' est vide",
                    valeurAttendue: 'contenu non vide',
                    valeurObtenue: '(vide)',
                );
            }

            return ResultatVerification::succes(
                message: "L'element '{$selecteur}' contient du texte",
                valeurObtenue: mb_strlen($contenuObtenu) > 100
                    ? mb_substr($contenuObtenu, 0, 100) . '...'
                    : $contenuObtenu,
            );
        }

        // Comparaison avec la valeur attendue
        if ($contenuObtenu !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Contenu de '{$selecteur}' non conforme",
                valeurAttendue: mb_strlen($valeurAttendue) > 100
                    ? mb_substr($valeurAttendue, 0, 100) . '...'
                    : $valeurAttendue,
                valeurObtenue: mb_strlen($contenuObtenu) > 100
                    ? mb_substr($contenuObtenu, 0, 100) . '...'
                    : $contenuObtenu,
            );
        }

        return ResultatVerification::succes(
            message: "Contenu de '{$selecteur}' conforme",
            valeurObtenue: mb_strlen($contenuObtenu) > 100
                ? mb_substr($contenuObtenu, 0, 100) . '...'
                : $contenuObtenu,
        );
    }

    /**
     * Verifie la valeur d'un attribut du premier element correspondant.
     *
     * @param array<string, mixed> $config
     */
    private function verifierAttribut(
        \DOMNodeList $resultats,
        string $selecteur,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $nomAttribut = $config['attribut'] ?? null;

        if ($nomAttribut === null || $nomAttribut === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: 'Aucun nom d\'attribut configure pour la verification',
            );
        }

        if ($resultats->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun element trouve pour '{$selecteur}'",
            );
        }

        $element = $resultats->item(0);

        if (!$element instanceof \DOMElement) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "L'element trouve pour '{$selecteur}' n'est pas un element DOM valide",
            );
        }

        if (!$element->hasAttribute($nomAttribut)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "L'attribut '{$nomAttribut}' n'existe pas sur l'element '{$selecteur}'",
                valeurAttendue: "attribut '{$nomAttribut}' present",
                valeurObtenue: 'attribut absent',
            );
        }

        $valeurAttribut = $element->getAttribute($nomAttribut);
        $valeurAttendue = $config['valeur_attendue'] ?? null;

        // Pas de valeur attendue : on verifie juste la presence
        if ($valeurAttendue === null) {
            return ResultatVerification::succes(
                message: "L'attribut '{$nomAttribut}' est present sur '{$selecteur}'",
                valeurObtenue: $valeurAttribut,
            );
        }

        // Comparaison avec la valeur attendue
        if ($valeurAttribut !== $valeurAttendue) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Valeur de l'attribut '{$nomAttribut}' non conforme sur '{$selecteur}'",
                valeurAttendue: $valeurAttendue,
                valeurObtenue: $valeurAttribut,
            );
        }

        return ResultatVerification::succes(
            message: "L'attribut '{$nomAttribut}' de '{$selecteur}' est conforme",
            valeurObtenue: $valeurAttribut,
        );
    }

    /**
     * Convertit un selecteur CSS simplifie en expression XPath.
     *
     * Supporte : tag, .class, #id, [attr], [attr=value], tag.class, tag#id,
     * combinaison d'espaces (descendant), et selecteurs multiples separes par des virgules.
     */
    private function cssVersXpath(string $selecteur): string
    {
        $selecteur = trim($selecteur);

        // Si ca ressemble deja a du XPath, le retourner tel quel
        if (str_starts_with($selecteur, '/') || str_starts_with($selecteur, '(')) {
            return $selecteur;
        }

        // Gerer les selecteurs multiples (virgule)
        if (str_contains($selecteur, ',')) {
            $parties = array_map('trim', explode(',', $selecteur));
            $xpaths = array_map(fn (string $partie): string => $this->convertirSelecteurSimple($partie), $parties);
            return implode(' | ', $xpaths);
        }

        return $this->convertirSelecteurSimple($selecteur);
    }

    /**
     * Convertit un selecteur CSS simple (sans virgule) en XPath.
     */
    private function convertirSelecteurSimple(string $selecteur): string
    {
        // Decouper par espaces pour les descendants
        $segments = preg_split('/\s+/', trim($selecteur));
        if ($segments === false || $segments === []) {
            return '//*';
        }

        $xpathParts = [];

        foreach ($segments as $segment) {
            $xpathParts[] = $this->convertirSegment($segment);
        }

        return '//' . implode('//', $xpathParts);
    }

    /**
     * Convertit un segment CSS individuel (ex: div.class#id[attr]) en fragment XPath.
     */
    private function convertirSegment(string $segment): string
    {
        $tag = '*';
        $conditions = [];

        // Extraire le tag au debut (avant tout . # ou [)
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/i', $segment, $match)) {
            $tag = $match[1];
            $segment = substr($segment, strlen($match[0]));
        }

        // Extraire les classes (.class)
        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $segment, $matches)) {
            foreach ($matches[1] as $classe) {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$classe} ')";
            }
        }

        // Extraire les IDs (#id)
        if (preg_match('/#([a-zA-Z0-9_-]+)/', $segment, $match)) {
            $conditions[] = "@id='{$match[1]}'";
        }

        // Extraire les attributs ([attr] et [attr=value] et [attr*=value] et [attr^=value] et [attr$=value])
        if (preg_match_all('/\[([^\]]+)\]/', $segment, $matches)) {
            foreach ($matches[1] as $attrExpr) {
                $conditions[] = $this->convertirConditionAttribut($attrExpr);
            }
        }

        if ($conditions === []) {
            return $tag;
        }

        return $tag . '[' . implode(' and ', $conditions) . ']';
    }

    /**
     * Convertit une condition d'attribut CSS en condition XPath.
     */
    private function convertirConditionAttribut(string $expression): string
    {
        // [attr=value]
        if (preg_match('/^([a-zA-Z0-9_-]+)\s*=\s*["\']?([^"\']*)["\']?$/', $expression, $match)) {
            return "@{$match[1]}='{$match[2]}'";
        }

        // [attr*=value] (contient)
        if (preg_match('/^([a-zA-Z0-9_-]+)\s*\*=\s*["\']?([^"\']*)["\']?$/', $expression, $match)) {
            return "contains(@{$match[1]}, '{$match[2]}')";
        }

        // [attr^=value] (commence par)
        if (preg_match('/^([a-zA-Z0-9_-]+)\s*\^=\s*["\']?([^"\']*)["\']?$/', $expression, $match)) {
            return "starts-with(@{$match[1]}, '{$match[2]}')";
        }

        // [attr$=value] (finit par) — pas de equivalent direct en XPath 1.0
        if (preg_match('/^([a-zA-Z0-9_-]+)\s*\$=\s*["\']?([^"\']*)["\']?$/', $expression, $match)) {
            $attr = $match[1];
            $val = $match[2];
            return "substring(@{$attr}, string-length(@{$attr}) - string-length('{$val}') + 1) = '{$val}'";
        }

        // [attr] (presence seule)
        if (preg_match('/^([a-zA-Z0-9_-]+)$/', $expression, $match)) {
            return "@{$match[1]}";
        }

        // Fallback : retourner tel quel comme attribut de presence
        return "@{$expression}";
    }
}
