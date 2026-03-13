<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de favicon.
 *
 * Detecte la presence d'un favicon via les balises <link> dans le HTML
 * (rel="icon", rel="shortcut icon", rel="apple-touch-icon") ou
 * via l'accessibilite de /favicon.ico.
 */
final class VerificateurFavicon implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'favicon';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'present';

        if ($verification !== 'present') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification favicon inconnu : {$verification}",
            );
        }

        return $this->verifierPresent($contexte, $severite);
    }

    /**
     * Verifie la presence d'un favicon sur la page.
     *
     * Deux strategies :
     * 1. Recherche de balises <link> avec rel icon/shortcut icon/apple-touch-icon dans le HTML
     * 2. Si l'URL testee est /favicon.ico, verifie le code HTTP
     */
    private function verifierPresent(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        // Strategie 1 : si l'URL est /favicon.ico, on verifie simplement l'accessibilite
        $cheminUrl = parse_url($contexte->url, PHP_URL_PATH);
        if ($cheminUrl !== null && str_ends_with($cheminUrl, '/favicon.ico')) {
            return $this->verifierFaviconIco($contexte, $severite);
        }

        // Strategie 2 : rechercher les balises <link> dans le HTML
        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le DOM pour rechercher le favicon',
            );
        }

        $favicons = $this->extraireFavicons($xpath);

        if ($favicons === []) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun favicon detecte dans le HTML (pas de balise <link> icon ni de /favicon.ico reference)',
                valeurAttendue: 'au moins un favicon',
                valeurObtenue: '0',
            );
        }

        $nombre = count($favicons);

        return ResultatVerification::succes(
            message: "{$nombre} favicon(s) detecte(s) dans le HTML",
            valeurObtenue: (string) $nombre,
            details: ['favicons' => $favicons],
        );
    }

    /**
     * Verifie l'accessibilite directe de /favicon.ico.
     */
    private function verifierFaviconIco(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le favicon.ico n'est pas accessible (code HTTP {$contexte->codeHttp})",
                valeurAttendue: '200',
                valeurObtenue: (string) $contexte->codeHttp,
            );
        }

        if ($contexte->tailleOctets === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le favicon.ico est accessible mais vide (0 octet)',
                valeurAttendue: '> 0 octets',
                valeurObtenue: '0 octets',
            );
        }

        // Verifier le Content-Type
        $contentType = $contexte->enTete('content-type') ?? '';
        $typesValides = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml', 'image/gif'];
        $typeValide = false;

        foreach ($typesValides as $type) {
            if (str_contains(strtolower($contentType), $type)) {
                $typeValide = true;
                break;
            }
        }

        if (!$typeValide && $contentType !== '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: "Le favicon.ico a un Content-Type inattendu : {$contentType}",
                valeurAttendue: implode(', ', $typesValides),
                valeurObtenue: $contentType,
                details: ['taille_octets' => $contexte->tailleOctets],
            );
        }

        return ResultatVerification::succes(
            message: 'Le favicon.ico est accessible et valide',
            valeurObtenue: $this->formaterOctets($contexte->tailleOctets),
            details: [
                'taille_octets' => $contexte->tailleOctets,
                'content_type' => $contentType,
            ],
        );
    }

    /**
     * Extrait les informations sur les favicons trouves dans le HTML.
     *
     * @return list<array{rel: string, href: string, type: ?string, sizes: ?string}>
     */
    private function extraireFavicons(\DOMXPath $xpath): array
    {
        $favicons = [];

        // Chercher les balises <link> avec rel contenant "icon"
        $requete = '//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "icon")]';
        $resultats = $xpath->query($requete);

        if ($resultats !== false) {
            foreach ($resultats as $noeud) {
                if (!$noeud instanceof \DOMElement) {
                    continue;
                }

                $href = $noeud->getAttribute('href');
                if ($href === '') {
                    continue;
                }

                $favicons[] = [
                    'rel' => $noeud->getAttribute('rel'),
                    'href' => $href,
                    'type' => $noeud->getAttribute('type') ?: null,
                    'sizes' => $noeud->getAttribute('sizes') ?: null,
                ];
            }
        }

        return $favicons;
    }

    /**
     * Formate une taille en octets en format lisible.
     */
    private function formaterOctets(int $octets): string
    {
        if ($octets < 1024) {
            return "{$octets} o";
        }

        if ($octets < 1048576) {
            return round($octets / 1024, 1) . ' Ko';
        }

        return round($octets / 1048576, 1) . ' Mo';
    }
}
