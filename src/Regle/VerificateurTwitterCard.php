<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des balises Twitter Card.
 *
 * Controle la presence et le contenu des meta twitter:card, twitter:title,
 * twitter:description, twitter:image, et la completude globale.
 */
final class VerificateurTwitterCard implements InterfaceVerificateur
{
    /** Types de carte Twitter valides. */
    private const array TYPES_CARTE_VALIDES = [
        'summary',
        'summary_large_image',
        'app',
        'player',
    ];

    /** Balises Twitter Card essentielles pour la completude. */
    private const array BALISES_ESSENTIELLES = ['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image'];

    #[\Override]
    public function typeGere(): string
    {
        return 'twitter_card';
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
            'card_type' => $this->verifierCardType($xpath, $config, $severite),
            'title' => $this->verifierTitle($xpath, $config, $severite),
            'description' => $this->verifierDescription($xpath, $config, $severite),
            'image' => $this->verifierImage($xpath, $severite),
            'complet' => $this->verifierCompletude($xpath, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification Twitter Card inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie twitter:card : presence et valeur valide.
     *
     * @param array<string, mixed> $config
     */
    private function verifierCardType(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'twitter:card');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:card absente',
                valeurAttendue: 'Presence de twitter:card',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:card vide',
                valeurAttendue: implode(', ', self::TYPES_CARTE_VALIDES),
                valeurObtenue: '(vide)',
            );
        }

        // Verification du type attendu specifique (si configure)
        if (!empty($config['contenu_attendu'])) {
            $attendu = strtolower(trim($config['contenu_attendu']));
            if (strtolower($contenu) !== $attendu) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Type de carte Twitter incorrect : \"{$contenu}\" au lieu de \"{$attendu}\"",
                    valeurAttendue: $attendu,
                    valeurObtenue: $contenu,
                );
            }
        }

        // Verification que le type est valide
        if (!in_array(strtolower($contenu), self::TYPES_CARTE_VALIDES, true)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Type de carte Twitter invalide : \"{$contenu}\"",
                valeurAttendue: implode(', ', self::TYPES_CARTE_VALIDES),
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::succes(
            message: "twitter:card conforme : {$contenu}",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie twitter:title : presence et longueur.
     *
     * @param array<string, mixed> $config
     */
    private function verifierTitle(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'twitter:title');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:title absente',
                valeurAttendue: 'Presence de twitter:title',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:title vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMax = (int) ($config['longueur_max'] ?? 70);

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "twitter:title trop long : {$longueur} caracteres (max : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        return ResultatVerification::succes(
            message: "twitter:title conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie twitter:description : presence et longueur.
     *
     * @param array<string, mixed> $config
     */
    private function verifierDescription(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'twitter:description');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:description absente',
                valeurAttendue: 'Presence de twitter:description',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:description vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMax = (int) ($config['longueur_max'] ?? 200);

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "twitter:description trop longue : {$longueur} caracteres (max : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        return ResultatVerification::succes(
            message: "twitter:description conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie twitter:image : presence et URL valide.
     */
    private function verifierImage(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'twitter:image');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:image absente',
                valeurAttendue: 'Presence de twitter:image',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise twitter:image vide',
                valeurAttendue: 'URL d\'image valide',
                valeurObtenue: '(vide)',
            );
        }

        if (filter_var($contenu, FILTER_VALIDATE_URL) === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "twitter:image n'est pas une URL valide : {$contenu}",
                valeurAttendue: 'URL valide',
                valeurObtenue: $contenu,
            );
        }

        if (!str_starts_with($contenu, 'https://')) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'twitter:image utilise HTTP au lieu de HTTPS',
                valeurAttendue: 'URL en HTTPS',
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::succes(
            message: 'twitter:image conforme',
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la completude des balises Twitter Card essentielles.
     */
    private function verifierCompletude(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $presentes = [];
        $absentes = [];

        foreach (self::BALISES_ESSENTIELLES as $balise) {
            $contenu = $this->obtenirContenuMeta($xpath, $balise);
            if ($contenu !== null && $contenu !== '') {
                $presentes[] = $balise;
            } else {
                $absentes[] = $balise;
            }
        }

        $total = count(self::BALISES_ESSENTIELLES);
        $nbPresentes = count($presentes);

        if (!empty($absentes)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    'Twitter Card incomplete : %d/%d balise(s) presente(s), manquante(s) : %s',
                    $nbPresentes,
                    $total,
                    implode(', ', $absentes),
                ),
                valeurAttendue: "{$total}/{$total}",
                valeurObtenue: "{$nbPresentes}/{$total}",
                details: ['presentes' => $presentes, 'absentes' => $absentes],
            );
        }

        return ResultatVerification::succes(
            message: "Twitter Card complete ({$nbPresentes}/{$total} balises essentielles)",
            valeurObtenue: "{$nbPresentes}/{$total}",
            details: ['presentes' => $presentes],
        );
    }

    /**
     * Recupere le contenu d'une meta Twitter par son name ou property.
     *
     * Twitter supporte a la fois name="twitter:*" et property="twitter:*".
     */
    private function obtenirContenuMeta(\DOMXPath $xpath, string $name): ?string
    {
        // Recherche par name (plus courant)
        $noeuds = $xpath->query(sprintf(
            '//head/meta[@name="%s" or @property="%s"]',
            $name,
            $name,
        ));

        if ($noeuds === false || $noeuds->length === 0) {
            return null;
        }

        return trim($noeuds->item(0)?->getAttribute('content') ?? '');
    }
}
