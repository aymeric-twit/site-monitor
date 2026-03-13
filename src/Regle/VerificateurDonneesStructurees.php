<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des donnees structurees (JSON-LD).
 *
 * Controle la presence, la syntaxe, le type @type et les champs obligatoires
 * des blocs JSON-LD embarques dans les balises <script type="application/ld+json">.
 */
final class VerificateurDonneesStructurees implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'donnees_structurees';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $verification = $config['verification'] ?? '';
        $severite = $regle->severite;

        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le DOM de la page',
            );
        }

        return match ($verification) {
            'presence_json_ld' => $this->verifierPresence($xpath, $severite),
            'syntaxe_json' => $this->verifierSyntaxe($xpath, $severite),
            'type_schema' => $this->verifierTypeSchema($xpath, $config, $severite),
            'champs_obligatoires' => $this->verifierChampsObligatoires($xpath, $config, $severite),
            'comptage' => $this->verifierComptage($xpath, $config, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification donnees structurees inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie la presence d'au moins un bloc JSON-LD.
     */
    private function verifierPresence(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $scripts = $this->extraireScriptsJsonLd($xpath);

        if (empty($scripts)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun bloc JSON-LD detecte',
                valeurAttendue: 'Au moins un <script type="application/ld+json">',
                valeurObtenue: '0',
            );
        }

        $types = $this->extraireTousLesTypes($scripts);

        return ResultatVerification::succes(
            message: sprintf(
                '%d bloc(s) JSON-LD detecte(s) : %s',
                count($scripts),
                empty($types) ? '(types non identifies)' : implode(', ', $types),
            ),
            valeurObtenue: (string) count($scripts),
            details: ['nombre_blocs' => count($scripts), 'types' => $types],
        );
    }

    /**
     * Verifie la syntaxe JSON de tous les blocs JSON-LD.
     */
    private function verifierSyntaxe(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//script[@type="application/ld+json"]');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun bloc JSON-LD a valider',
            );
        }

        $erreurs = [];
        $nbValides = 0;

        for ($i = 0; $i < $noeuds->length; $i++) {
            $contenuBrut = trim($noeuds->item($i)?->textContent ?? '');
            if ($contenuBrut === '') {
                $erreurs[] = "Bloc #{$i}: contenu vide";
                continue;
            }

            json_decode($contenuBrut, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $erreurs[] = sprintf('Bloc #%d: %s', $i + 1, json_last_error_msg());
            } else {
                $nbValides++;
            }
        }

        if (!empty($erreurs)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Erreur(s) de syntaxe JSON-LD : ' . implode(' ; ', $erreurs),
                valeurAttendue: 'JSON valide',
                valeurObtenue: sprintf('%d erreur(s) sur %d bloc(s)', count($erreurs), $noeuds->length),
                details: ['erreurs' => $erreurs],
            );
        }

        return ResultatVerification::succes(
            message: sprintf('%d bloc(s) JSON-LD syntaxiquement valide(s)', $nbValides),
            valeurObtenue: (string) $nbValides,
        );
    }

    /**
     * Verifie la presence d'un type de schema specifique (@type).
     *
     * @param array<string, mixed> $config
     */
    private function verifierTypeSchema(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $typeAttendu = $config['type_attendu'] ?? '';
        if ($typeAttendu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Configuration incomplete : "type_attendu" non defini',
            );
        }

        $scripts = $this->extraireScriptsJsonLd($xpath);

        if (empty($scripts)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucun bloc JSON-LD detecte (type attendu : {$typeAttendu})",
                valeurAttendue: $typeAttendu,
                valeurObtenue: 'Aucun JSON-LD',
            );
        }

        $typesTrouves = $this->extraireTousLesTypes($scripts);

        // Recherche insensible a la casse du type
        $typeTrouve = false;
        foreach ($typesTrouves as $type) {
            if (strcasecmp($type, $typeAttendu) === 0) {
                $typeTrouve = true;
                break;
            }
        }

        if (!$typeTrouve) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Type schema \"{$typeAttendu}\" non trouve",
                valeurAttendue: $typeAttendu,
                valeurObtenue: empty($typesTrouves) ? '(aucun type)' : implode(', ', $typesTrouves),
                details: ['types_trouves' => $typesTrouves],
            );
        }

        return ResultatVerification::succes(
            message: "Type schema \"{$typeAttendu}\" present",
            valeurObtenue: $typeAttendu,
            details: ['types_trouves' => $typesTrouves],
        );
    }

    /**
     * Verifie la presence de champs obligatoires dans un type de schema donne.
     *
     * @param array<string, mixed> $config
     */
    private function verifierChampsObligatoires(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $typeAttendu = $config['type_attendu'] ?? '';
        /** @var string[] $champsRequis */
        $champsRequis = $config['champs'] ?? [];

        if ($typeAttendu === '' || empty($champsRequis)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Configuration incomplete : "type_attendu" et/ou "champs" non definis',
            );
        }

        $scripts = $this->extraireScriptsJsonLd($xpath);
        $blocCible = $this->trouverBlocParType($scripts, $typeAttendu);

        if ($blocCible === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Type schema \"{$typeAttendu}\" non trouve pour verifier les champs",
                valeurAttendue: $typeAttendu,
                valeurObtenue: 'Absent',
            );
        }

        $champsManquants = [];
        $champsPresents = [];

        foreach ($champsRequis as $champ) {
            if ($this->champExiste($blocCible, $champ)) {
                $champsPresents[] = $champ;
            } else {
                $champsManquants[] = $champ;
            }
        }

        if (!empty($champsManquants)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    'Champs obligatoires manquants dans %s : %s',
                    $typeAttendu,
                    implode(', ', $champsManquants),
                ),
                valeurAttendue: implode(', ', $champsRequis),
                valeurObtenue: implode(', ', $champsPresents) ?: '(aucun)',
                details: [
                    'champs_manquants' => $champsManquants,
                    'champs_presents' => $champsPresents,
                ],
            );
        }

        return ResultatVerification::succes(
            message: sprintf(
                'Tous les champs obligatoires presents dans %s (%d/%d)',
                $typeAttendu,
                count($champsPresents),
                count($champsRequis),
            ),
            valeurObtenue: implode(', ', $champsPresents),
            details: ['champs_presents' => $champsPresents],
        );
    }

    /**
     * Verifie le nombre de blocs JSON-LD par rapport a des seuils.
     *
     * @param array<string, mixed> $config
     */
    private function verifierComptage(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $scripts = $this->extraireScriptsJsonLd($xpath);
        $nombre = count($scripts);

        $nombreMin = isset($config['nombre_min']) ? (int) $config['nombre_min'] : null;
        $nombreMax = isset($config['nombre_max']) ? (int) $config['nombre_max'] : null;

        if ($nombreMin !== null && $nombre < $nombreMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop peu de blocs JSON-LD : {$nombre} (minimum attendu : {$nombreMin})",
                valeurAttendue: ">= {$nombreMin}",
                valeurObtenue: (string) $nombre,
            );
        }

        if ($nombreMax !== null && $nombre > $nombreMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop de blocs JSON-LD : {$nombre} (maximum attendu : {$nombreMax})",
                valeurAttendue: "<= {$nombreMax}",
                valeurObtenue: (string) $nombre,
            );
        }

        $types = $this->extraireTousLesTypes($scripts);

        return ResultatVerification::succes(
            message: sprintf('%d bloc(s) JSON-LD detecte(s)', $nombre),
            valeurObtenue: (string) $nombre,
            details: ['types' => $types],
        );
    }

    /**
     * Extrait et decode tous les blocs JSON-LD de la page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extraireScriptsJsonLd(\DOMXPath $xpath): array
    {
        $noeuds = $xpath->query('//script[@type="application/ld+json"]');

        if ($noeuds === false) {
            return [];
        }

        $blocs = [];

        for ($i = 0; $i < $noeuds->length; $i++) {
            $contenuBrut = trim($noeuds->item($i)?->textContent ?? '');
            if ($contenuBrut === '') {
                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($contenuBrut, true);
            if ($decoded !== null && is_array($decoded)) {
                $blocs[] = $decoded;
            }
        }

        return $blocs;
    }

    /**
     * Extrait tous les @type de tous les blocs JSON-LD (y compris @graph).
     *
     * @param array<int, array<string, mixed>> $blocs
     * @return string[]
     */
    private function extraireTousLesTypes(array $blocs): array
    {
        $types = [];

        foreach ($blocs as $bloc) {
            $this->extraireTypesRecursif($bloc, $types);
        }

        return array_unique($types);
    }

    /**
     * Extraction recursive des @type (supporte @graph et les objets imbriques).
     *
     * @param array<string, mixed> $donnees
     * @param string[] $types
     */
    private function extraireTypesRecursif(array $donnees, array &$types): void
    {
        if (isset($donnees['@type'])) {
            $type = $donnees['@type'];
            if (is_string($type)) {
                $types[] = $type;
            } elseif (is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t)) {
                        $types[] = $t;
                    }
                }
            }
        }

        // Parcours de @graph
        if (isset($donnees['@graph']) && is_array($donnees['@graph'])) {
            foreach ($donnees['@graph'] as $sousBloc) {
                if (is_array($sousBloc)) {
                    $this->extraireTypesRecursif($sousBloc, $types);
                }
            }
        }
    }

    /**
     * Trouve le premier bloc JSON-LD correspondant a un @type donne.
     *
     * @param array<int, array<string, mixed>> $blocs
     * @return array<string, mixed>|null
     */
    private function trouverBlocParType(array $blocs, string $typeRecherche): ?array
    {
        foreach ($blocs as $bloc) {
            $resultat = $this->chercherTypeRecursif($bloc, $typeRecherche);
            if ($resultat !== null) {
                return $resultat;
            }
        }

        return null;
    }

    /**
     * Recherche recursive d'un bloc par @type (supporte @graph).
     *
     * @param array<string, mixed> $donnees
     * @return array<string, mixed>|null
     */
    private function chercherTypeRecursif(array $donnees, string $typeRecherche): ?array
    {
        if (isset($donnees['@type'])) {
            $type = $donnees['@type'];
            $correspond = is_string($type) && strcasecmp($type, $typeRecherche) === 0;
            if (!$correspond && is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t) && strcasecmp($t, $typeRecherche) === 0) {
                        $correspond = true;
                        break;
                    }
                }
            }
            if ($correspond) {
                return $donnees;
            }
        }

        if (isset($donnees['@graph']) && is_array($donnees['@graph'])) {
            foreach ($donnees['@graph'] as $sousBloc) {
                if (is_array($sousBloc)) {
                    $resultat = $this->chercherTypeRecursif($sousBloc, $typeRecherche);
                    if ($resultat !== null) {
                        return $resultat;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Verifie si un champ existe dans un bloc JSON-LD (supporte la notation pointee).
     *
     * @param array<string, mixed> $bloc
     */
    private function champExiste(array $bloc, string $champ): bool
    {
        // Supporte la notation pointee : "address.streetAddress"
        $segments = explode('.', $champ);
        $courant = $bloc;

        foreach ($segments as $segment) {
            if (!is_array($courant) || !array_key_exists($segment, $courant)) {
                return false;
            }
            $courant = $courant[$segment];
        }

        // Le champ existe si sa valeur n'est pas null et pas une chaine vide
        return $courant !== null && $courant !== '';
    }
}
