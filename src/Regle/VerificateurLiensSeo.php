<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur SEO des liens.
 *
 * Compte les liens internes/externes, detecte les nofollow,
 * les ancres vides ou generiques, et le fil d'Ariane.
 */
final class VerificateurLiensSeo implements InterfaceVerificateur
{
    /** Ancres generiques considerees comme non descriptives (insensible a la casse). */
    private const array ANCRES_GENERIQUES = [
        'cliquez ici',
        'cliquer ici',
        'click here',
        'lire la suite',
        'en savoir plus',
        'voir plus',
        'plus',
        'ici',
        'lien',
        'link',
        'read more',
        'learn more',
        'more',
        'suite',
        'details',
    ];

    #[\Override]
    public function typeGere(): string
    {
        return 'liens_seo';
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
            'comptage_internes' => $this->verifierComptageInternes($xpath, $config, $severite, $contexte->url),
            'comptage_externes' => $this->verifierComptageExternes($xpath, $config, $severite, $contexte->url),
            'nofollow' => $this->verifierNofollow($xpath, $severite),
            'ancres_vides' => $this->verifierAncresVides($xpath, $severite),
            'fil_ariane' => $this->verifierFilAriane($xpath, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification liens inconnu : {$verification}",
            ),
        };
    }

    /**
     * Compte les liens internes et verifie les seuils min/max.
     *
     * @param array<string, mixed> $config
     */
    private function verifierComptageInternes(\DOMXPath $xpath, array $config, NiveauSeverite $severite, string $urlPage): ResultatVerification
    {
        $domaineReference = $this->extraireDomaine($config['domaine_reference'] ?? $urlPage);
        $liens = $this->extraireLiens($xpath);

        $internes = array_filter($liens, fn(array $lien): bool => $this->estLienInterne($lien['href'], $domaineReference));
        $nombre = count($internes);

        $nombreMin = isset($config['nombre_min']) ? (int) $config['nombre_min'] : null;
        $nombreMax = isset($config['nombre_max']) ? (int) $config['nombre_max'] : null;

        if ($nombreMin !== null && $nombre < $nombreMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop peu de liens internes : {$nombre} (minimum attendu : {$nombreMin})",
                valeurAttendue: ">= {$nombreMin}",
                valeurObtenue: (string) $nombre,
                details: ['domaine' => $domaineReference],
            );
        }

        if ($nombreMax !== null && $nombre > $nombreMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop de liens internes : {$nombre} (maximum attendu : {$nombreMax})",
                valeurAttendue: "<= {$nombreMax}",
                valeurObtenue: (string) $nombre,
                details: ['domaine' => $domaineReference],
            );
        }

        return ResultatVerification::succes(
            message: "{$nombre} lien(s) interne(s) detecte(s)",
            valeurObtenue: (string) $nombre,
            details: ['domaine' => $domaineReference, 'total_liens' => count($liens)],
        );
    }

    /**
     * Compte les liens externes et verifie les seuils min/max.
     *
     * @param array<string, mixed> $config
     */
    private function verifierComptageExternes(\DOMXPath $xpath, array $config, NiveauSeverite $severite, string $urlPage): ResultatVerification
    {
        $domaineReference = $this->extraireDomaine($config['domaine_reference'] ?? $urlPage);
        $liens = $this->extraireLiens($xpath);

        $externes = array_filter($liens, fn(array $lien): bool => !$this->estLienInterne($lien['href'], $domaineReference) && $this->estUrlAbsolue($lien['href']));
        $nombre = count($externes);

        $nombreMin = isset($config['nombre_min']) ? (int) $config['nombre_min'] : null;
        $nombreMax = isset($config['nombre_max']) ? (int) $config['nombre_max'] : null;

        if ($nombreMin !== null && $nombre < $nombreMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop peu de liens externes : {$nombre} (minimum attendu : {$nombreMin})",
                valeurAttendue: ">= {$nombreMin}",
                valeurObtenue: (string) $nombre,
            );
        }

        if ($nombreMax !== null && $nombre > $nombreMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Trop de liens externes : {$nombre} (maximum attendu : {$nombreMax})",
                valeurAttendue: "<= {$nombreMax}",
                valeurObtenue: (string) $nombre,
            );
        }

        // Extraction des domaines externes uniques pour les details
        $domainesExternes = [];
        foreach ($externes as $lien) {
            $domaine = $this->extraireDomaine($lien['href']);
            if ($domaine !== '') {
                $domainesExternes[$domaine] = ($domainesExternes[$domaine] ?? 0) + 1;
            }
        }
        arsort($domainesExternes);

        return ResultatVerification::succes(
            message: "{$nombre} lien(s) externe(s) detecte(s)",
            valeurObtenue: (string) $nombre,
            details: [
                'domaine_reference' => $domaineReference,
                'domaines_externes' => $domainesExternes,
            ],
        );
    }

    /**
     * Detecte les liens avec rel="nofollow" et leur proportion.
     */
    private function verifierNofollow(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $tousLiens = $xpath->query('//a[@href]');
        $nbTotal = $tousLiens !== false ? $tousLiens->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucun lien detecte sur la page',
                valeurObtenue: '0',
            );
        }

        $liensNofollow = [];

        for ($i = 0; $i < $tousLiens->length; $i++) {
            $noeud = $tousLiens->item($i);
            if ($noeud === null) {
                continue;
            }

            $rel = strtolower(trim($noeud->getAttribute('rel')));
            if (str_contains($rel, 'nofollow')) {
                $href = $noeud->getAttribute('href');
                $ancre = trim($noeud->textContent);
                $liensNofollow[] = [
                    'href' => mb_strimwidth($href, 0, 120, '...'),
                    'ancre' => mb_strimwidth($ancre, 0, 60, '...'),
                ];
            }
        }

        $nbNofollow = count($liensNofollow);

        if ($nbNofollow > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbNofollow} lien(s) nofollow detecte(s) sur {$nbTotal}",
                valeurObtenue: "{$nbNofollow}/{$nbTotal}",
                details: ['liens_nofollow' => array_slice($liensNofollow, 0, 20)],
            );
        }

        return ResultatVerification::succes(
            message: "Aucun lien nofollow sur {$nbTotal} lien(s)",
            valeurObtenue: "0/{$nbTotal}",
        );
    }

    /**
     * Detecte les ancres vides ou generiques (non descriptives pour le SEO).
     */
    private function verifierAncresVides(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $tousLiens = $xpath->query('//a[@href]');
        $nbTotal = $tousLiens !== false ? $tousLiens->length : 0;

        if ($nbTotal === 0) {
            return ResultatVerification::succes(
                message: 'Aucun lien detecte sur la page',
                valeurObtenue: '0',
            );
        }

        $ancresProblematiques = [];

        for ($i = 0; $i < $tousLiens->length; $i++) {
            $noeud = $tousLiens->item($i);
            if ($noeud === null) {
                continue;
            }

            $ancre = trim($noeud->textContent);
            $href = $noeud->getAttribute('href');

            // Ignorer les liens avec des images (l'image sert d'ancre)
            $imagesInternes = $xpath->query('.//img', $noeud);
            $contientImage = $imagesInternes !== false && $imagesInternes->length > 0;

            if ($ancre === '' && !$contientImage) {
                $ancresProblematiques[] = [
                    'href' => mb_strimwidth($href, 0, 120, '...'),
                    'raison' => 'Ancre vide',
                ];
                continue;
            }

            // Detection des ancres generiques
            $ancreNormalisee = mb_strtolower(trim($ancre));
            if (in_array($ancreNormalisee, self::ANCRES_GENERIQUES, true)) {
                $ancresProblematiques[] = [
                    'href' => mb_strimwidth($href, 0, 120, '...'),
                    'ancre' => $ancre,
                    'raison' => 'Ancre generique',
                ];
            }
        }

        $nbProblematiques = count($ancresProblematiques);

        if ($nbProblematiques > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbProblematiques} lien(s) avec ancre vide ou generique sur {$nbTotal}",
                valeurAttendue: '0 ancre vide ou generique',
                valeurObtenue: (string) $nbProblematiques,
                details: ['ancres_problematiques' => array_slice($ancresProblematiques, 0, 20)],
            );
        }

        return ResultatVerification::succes(
            message: "Toutes les ancres sont descriptives ({$nbTotal} liens)",
            valeurObtenue: '0',
        );
    }

    /**
     * Detecte la presence d'un fil d'Ariane (breadcrumb) via donnees structurees ou markup.
     */
    private function verifierFilAriane(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        // Recherche via donnees structurees JSON-LD
        $scriptsJsonLd = $xpath->query('//script[@type="application/ld+json"]');
        $filArianeJsonLd = false;

        if ($scriptsJsonLd !== false) {
            for ($i = 0; $i < $scriptsJsonLd->length; $i++) {
                $contenu = trim($scriptsJsonLd->item($i)?->textContent ?? '');
                if ($contenu !== '' && str_contains($contenu, 'BreadcrumbList')) {
                    $filArianeJsonLd = true;
                    break;
                }
            }
        }

        // Recherche via attributs HTML courants (aria, class, itemprop)
        $selecteursBreadcrumb = [
            '//*[@aria-label="breadcrumb" or @aria-label="Breadcrumb" or @aria-label="fil d\'ariane"]',
            '//*[contains(@class, "breadcrumb")]',
            '//*[@itemtype="https://schema.org/BreadcrumbList"]',
            '//*[@itemtype="http://schema.org/BreadcrumbList"]',
            '//nav[contains(@class, "breadcrumb")]',
            '//ol[contains(@class, "breadcrumb")]',
        ];

        $filArianeHtml = false;
        foreach ($selecteursBreadcrumb as $selecteur) {
            $noeuds = $xpath->query($selecteur);
            if ($noeuds !== false && $noeuds->length > 0) {
                $filArianeHtml = true;
                break;
            }
        }

        if (!$filArianeJsonLd && !$filArianeHtml) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun fil d\'Ariane detecte (ni JSON-LD, ni markup HTML)',
                valeurAttendue: 'Presence d\'un fil d\'Ariane',
                valeurObtenue: 'Absent',
            );
        }

        $sources = array_filter([
            $filArianeJsonLd ? 'JSON-LD (BreadcrumbList)' : null,
            $filArianeHtml ? 'Markup HTML' : null,
        ]);

        return ResultatVerification::succes(
            message: 'Fil d\'Ariane detecte : ' . implode(' + ', $sources),
            valeurObtenue: implode(', ', $sources),
            details: ['json_ld' => $filArianeJsonLd, 'html' => $filArianeHtml],
        );
    }

    /**
     * Extrait tous les liens <a href="..."> de la page.
     *
     * @return array<int, array{href: string, ancre: string, rel: string}>
     */
    private function extraireLiens(\DOMXPath $xpath): array
    {
        $noeuds = $xpath->query('//a[@href]');

        if ($noeuds === false) {
            return [];
        }

        $liens = [];

        for ($i = 0; $i < $noeuds->length; $i++) {
            $noeud = $noeuds->item($i);
            if ($noeud === null) {
                continue;
            }

            $href = trim($noeud->getAttribute('href'));

            // Ignorer les ancres vides, javascript:, mailto:, tel:
            if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, '#')) {
                continue;
            }

            $liens[] = [
                'href' => $href,
                'ancre' => trim($noeud->textContent),
                'rel' => strtolower(trim($noeud->getAttribute('rel'))),
            ];
        }

        return $liens;
    }

    /**
     * Determine si un lien est interne (meme domaine).
     */
    private function estLienInterne(string $href, string $domaineReference): bool
    {
        // Liens relatifs = internes
        if (!$this->estUrlAbsolue($href)) {
            return true;
        }

        $domaineLien = $this->extraireDomaine($href);

        return strcasecmp($domaineLien, $domaineReference) === 0;
    }

    /**
     * Verifie si une URL est absolue.
     */
    private function estUrlAbsolue(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//');
    }

    /**
     * Extrait le domaine d'une URL.
     */
    private function extraireDomaine(string $url): string
    {
        $parsee = parse_url($url);

        return strtolower($parsee['host'] ?? '');
    }
}
