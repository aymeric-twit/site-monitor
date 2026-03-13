<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des balises Open Graph.
 *
 * Controle la presence et le contenu des meta og:title, og:description,
 * og:image, og:url, et la completude globale.
 */
final class VerificateurOpenGraph implements InterfaceVerificateur
{
    /** Balises OG essentielles pour une completude minimale. */
    private const array BALISES_ESSENTIELLES = ['og:title', 'og:description', 'og:image', 'og:url', 'og:type'];

    #[\Override]
    public function typeGere(): string
    {
        return 'open_graph';
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
            'og_title' => $this->verifierOgTitle($xpath, $config, $severite),
            'og_description' => $this->verifierOgDescription($xpath, $config, $severite),
            'og_image' => $this->verifierOgImage($xpath, $config, $severite),
            'og_url' => $this->verifierOgUrl($xpath, $severite),
            'og_complet' => $this->verifierCompletude($xpath, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification Open Graph inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie og:title : presence et longueur.
     *
     * @param array<string, mixed> $config
     */
    private function verifierOgTitle(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'og:title');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:title absente',
                valeurAttendue: 'Presence de og:title',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:title vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMin = (int) ($config['longueur_min'] ?? 15);
        $longueurMax = (int) ($config['longueur_max'] ?? 70);

        if ($longueur < $longueurMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:title trop court : {$longueur} caracteres (min : {$longueurMin})",
                valeurAttendue: ">= {$longueurMin} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:title trop long : {$longueur} caracteres (max : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        return ResultatVerification::succes(
            message: "og:title conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie og:description : presence et longueur.
     *
     * @param array<string, mixed> $config
     */
    private function verifierOgDescription(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'og:description');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:description absente',
                valeurAttendue: 'Presence de og:description',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:description vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMin = (int) ($config['longueur_min'] ?? 50);
        $longueurMax = (int) ($config['longueur_max'] ?? 200);

        if ($longueur < $longueurMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:description trop courte : {$longueur} caracteres (min : {$longueurMin})",
                valeurAttendue: ">= {$longueurMin} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:description trop longue : {$longueur} caracteres (max : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        return ResultatVerification::succes(
            message: "og:description conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie og:image : presence et URL valide.
     *
     * @param array<string, mixed> $config
     */
    private function verifierOgImage(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'og:image');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:image absente',
                valeurAttendue: 'Presence de og:image',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:image vide',
                valeurAttendue: 'URL d\'image valide',
                valeurObtenue: '(vide)',
            );
        }

        // Verification d'URL valide
        if (filter_var($contenu, FILTER_VALIDATE_URL) === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:image n'est pas une URL valide : {$contenu}",
                valeurAttendue: 'URL valide',
                valeurObtenue: $contenu,
            );
        }

        // Verification du protocole HTTPS
        if (!str_starts_with($contenu, 'https://')) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'og:image utilise HTTP au lieu de HTTPS',
                valeurAttendue: 'URL en HTTPS',
                valeurObtenue: $contenu,
            );
        }

        // Verification de l'accessibilite de l'image (optionnel)
        $details = ['url' => $contenu];
        $verifierAccessible = (bool) ($config['verifier_image_accessible'] ?? false);
        if ($verifierAccessible) {
            $details['verification_accessibilite'] = 'non implementee (necessite requete HTTP)';
        }

        // Verification des dimensions via og:image:width et og:image:height
        $largeur = $this->obtenirContenuMeta($xpath, 'og:image:width');
        $hauteur = $this->obtenirContenuMeta($xpath, 'og:image:height');
        if ($largeur !== null && $hauteur !== null) {
            $details['dimensions'] = "{$largeur}x{$hauteur}";
        }

        return ResultatVerification::succes(
            message: 'og:image conforme',
            valeurObtenue: $contenu,
            details: $details,
        );
    }

    /**
     * Verifie og:url : presence et URL valide.
     */
    private function verifierOgUrl(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $this->obtenirContenuMeta($xpath, 'og:url');

        if ($contenu === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:url absente',
                valeurAttendue: 'Presence de og:url',
                valeurObtenue: 'Absente',
            );
        }

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise og:url vide',
                valeurAttendue: 'URL valide',
                valeurObtenue: '(vide)',
            );
        }

        if (filter_var($contenu, FILTER_VALIDATE_URL) === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "og:url n'est pas une URL valide : {$contenu}",
                valeurAttendue: 'URL valide',
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::succes(
            message: 'og:url conforme',
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la completude des balises OG essentielles.
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
                    'Open Graph incomplet : %d/%d balise(s) presente(s), manquante(s) : %s',
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
            message: "Open Graph complet ({$nbPresentes}/{$total} balises essentielles)",
            valeurObtenue: "{$nbPresentes}/{$total}",
            details: ['presentes' => $presentes],
        );
    }

    /**
     * Recupere le contenu d'une meta OG par son property.
     */
    private function obtenirContenuMeta(\DOMXPath $xpath, string $property): ?string
    {
        $noeuds = $xpath->query(sprintf(
            '//head/meta[@property="%s"]',
            $property,
        ));

        if ($noeuds === false || $noeuds->length === 0) {
            return null;
        }

        return trim($noeuds->item(0)?->getAttribute('content') ?? '');
    }
}
