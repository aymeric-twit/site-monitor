<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur SEO des images.
 *
 * Detecte les images sans attribut alt, sans dimensions (width/height),
 * avec un alt vide, compte les images et verifie le lazy loading.
 */
final class VerificateurImagesSeo implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'images_seo';
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
            'alt_manquant' => $this->verifierAltManquant($xpath, $severite),
            'alt_vide' => $this->verifierAltVide($xpath, $severite),
            'dimensions_manquantes' => $this->verifierDimensionsManquantes($xpath, $severite),
            'comptage' => $this->verifierComptage($xpath, $severite),
            'lazy_loading' => $this->verifierLazyLoading($xpath, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification images inconnu : {$verification}",
            ),
        };
    }

    /**
     * Detecte les images sans attribut alt (attribut completement absent).
     */
    private function verifierAltManquant(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $imagesSansAlt = $xpath->query('//img[not(@alt)]');
        $totalImages = $xpath->query('//img');

        $nbSansAlt = $imagesSansAlt !== false ? $imagesSansAlt->length : 0;
        $nbTotal = $totalImages !== false ? $totalImages->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucune image detectee sur la page',
                valeurObtenue: '0 image',
            );
        }

        if ($nbSansAlt > 0) {
            $sources = $this->extraireSources($imagesSansAlt);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbSansAlt} image(s) sans attribut alt sur {$nbTotal}",
                valeurAttendue: '0 image sans alt',
                valeurObtenue: "{$nbSansAlt} image(s) sans alt",
                details: ['images_sans_alt' => $sources],
            );
        }

        return ResultatVerification::succes(
            message: "Toutes les images ({$nbTotal}) possedent un attribut alt",
            valeurObtenue: "{$nbTotal} image(s) avec alt",
        );
    }

    /**
     * Detecte les images avec un attribut alt vide (alt="").
     * Note : alt="" est valide pour les images decoratives, mais suspect en SEO.
     */
    private function verifierAltVide(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $imagesAltVide = $xpath->query('//img[@alt=""]');
        $totalImages = $xpath->query('//img');

        $nbAltVide = $imagesAltVide !== false ? $imagesAltVide->length : 0;
        $nbTotal = $totalImages !== false ? $totalImages->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucune image detectee sur la page',
                valeurObtenue: '0 image',
            );
        }

        if ($nbAltVide > 0) {
            $sources = $this->extraireSources($imagesAltVide);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbAltVide} image(s) avec un alt vide sur {$nbTotal}",
                valeurAttendue: '0 image avec alt vide',
                valeurObtenue: "{$nbAltVide} image(s) avec alt vide",
                details: ['images_alt_vide' => $sources],
            );
        }

        return ResultatVerification::succes(
            message: "Aucune image avec alt vide sur {$nbTotal} image(s)",
            valeurObtenue: '0',
        );
    }

    /**
     * Detecte les images sans attributs width et/ou height explicites.
     * L'absence de dimensions provoque du CLS (Cumulative Layout Shift).
     */
    private function verifierDimensionsManquantes(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $imagesSansDimensions = $xpath->query('//img[not(@width) or not(@height)]');
        $totalImages = $xpath->query('//img');

        $nbSansDimensions = $imagesSansDimensions !== false ? $imagesSansDimensions->length : 0;
        $nbTotal = $totalImages !== false ? $totalImages->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucune image detectee sur la page',
                valeurObtenue: '0 image',
            );
        }

        if ($nbSansDimensions > 0) {
            $sources = $this->extraireSources($imagesSansDimensions);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbSansDimensions} image(s) sans dimensions explicites (width/height) sur {$nbTotal}",
                valeurAttendue: '0 image sans dimensions',
                valeurObtenue: "{$nbSansDimensions} image(s) sans dimensions",
                details: ['images_sans_dimensions' => $sources],
            );
        }

        return ResultatVerification::succes(
            message: "Toutes les images ({$nbTotal}) ont des dimensions explicites",
            valeurObtenue: "{$nbTotal} image(s) avec dimensions",
        );
    }

    /**
     * Compte le nombre total d'images sur la page.
     */
    private function verifierComptage(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $totalImages = $xpath->query('//img');
        $nbTotal = $totalImages !== false ? $totalImages->length : 0;

        // Statistiques complementaires
        $sansAlt = $xpath->query('//img[not(@alt)]');
        $altVide = $xpath->query('//img[@alt=""]');
        $avecLazy = $xpath->query('//img[@loading="lazy"]');

        $details = [
            'total' => $nbTotal,
            'sans_alt' => $sansAlt !== false ? $sansAlt->length : 0,
            'alt_vide' => $altVide !== false ? $altVide->length : 0,
            'lazy_loading' => $avecLazy !== false ? $avecLazy->length : 0,
        ];

        return ResultatVerification::succes(
            message: sprintf(
                '%d image(s) detectee(s) (sans alt: %d, alt vide: %d, lazy: %d)',
                $details['total'],
                $details['sans_alt'],
                $details['alt_vide'],
                $details['lazy_loading'],
            ),
            valeurObtenue: (string) $nbTotal,
            details: $details,
        );
    }

    /**
     * Verifie que les images utilisent le lazy loading (loading="lazy").
     * Exclut la premiere image (souvent above-the-fold, ne doit pas etre lazy).
     */
    private function verifierLazyLoading(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $toutesImages = $xpath->query('//img');
        $nbTotal = $toutesImages !== false ? $toutesImages->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucune image detectee sur la page',
                valeurObtenue: '0 image',
            );
        }

        // On exclut la premiere image (above-the-fold)
        $imagesSansLazy = [];
        for ($i = 0; $i < $toutesImages->length; $i++) {
            $noeud = $toutesImages->item($i);
            if ($noeud === null) {
                continue;
            }

            // Premiere image : loading="eager" ou rien est acceptable
            if ($i === 0) {
                continue;
            }

            $loading = strtolower(trim($noeud->getAttribute('loading')));
            if ($loading !== 'lazy') {
                $src = $noeud->getAttribute('src') ?: $noeud->getAttribute('data-src') ?: '(sans src)';
                $imagesSansLazy[] = mb_strimwidth($src, 0, 120, '...');
            }
        }

        if (!empty($imagesSansLazy)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    '%d image(s) sans loading="lazy" (hors premiere image above-the-fold)',
                    count($imagesSansLazy),
                ),
                valeurAttendue: 'loading="lazy" sur les images below-the-fold',
                valeurObtenue: sprintf('%d image(s) sans lazy loading', count($imagesSansLazy)),
                details: ['images_sans_lazy' => $imagesSansLazy],
            );
        }

        return ResultatVerification::succes(
            message: sprintf('Lazy loading correctement applique sur %d image(s)', $nbTotal - 1),
            valeurObtenue: sprintf('%d/%d', $nbTotal - 1, $nbTotal),
        );
    }

    /**
     * Extrait les sources (src) d'une liste de noeuds img (pour les details).
     *
     * @return string[]
     */
    private function extraireSources(\DOMNodeList $noeuds, int $max = 10): array
    {
        $sources = [];

        for ($i = 0; $i < min($noeuds->length, $max); $i++) {
            $noeud = $noeuds->item($i);
            if ($noeud === null) {
                continue;
            }

            $src = $noeud->getAttribute('src') ?: $noeud->getAttribute('data-src') ?: '(sans src)';
            $sources[] = mb_strimwidth($src, 0, 120, '...');
        }

        if ($noeuds->length > $max) {
            $sources[] = sprintf('... et %d autre(s)', $noeuds->length - $max);
        }

        return $sources;
    }
}
