<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de sitemap XML.
 *
 * Analyse le contenu du sitemap pour verifier son accessibilite,
 * la validite XML, le comptage d'URLs, la presence de lastmod
 * et la detection de sitemap index.
 */
final class VerificateurSitemapXml implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'sitemap_xml';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'accessible';

        return match ($verification) {
            'accessible' => $this->verifierAccessible($contexte, $severite),
            'xml_valide' => $this->verifierXmlValide($contexte, $severite),
            'comptage_urls' => $this->verifierComptageUrls($contexte, $config, $severite),
            'lastmod_present' => $this->verifierLastmodPresent($contexte, $severite),
            'taille' => $this->verifierTaille($contexte, $config, $severite),
            'index' => $this->verifierIndex($contexte, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification sitemap inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie que le sitemap est accessible (code 200 et contenu non vide).
     */
    private function verifierAccessible(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le sitemap n'est pas accessible (code HTTP {$contexte->codeHttp})",
                valeurAttendue: '200',
                valeurObtenue: (string) $contexte->codeHttp,
            );
        }

        $contenu = trim($contexte->corpsReponse);

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le sitemap est vide',
                valeurAttendue: 'contenu XML non vide',
                valeurObtenue: 'fichier vide',
            );
        }

        return ResultatVerification::succes(
            message: 'Le sitemap est accessible',
            valeurObtenue: (string) $contexte->codeHttp,
            details: ['taille_octets' => $contexte->tailleOctets],
        );
    }

    /**
     * Verifie que le contenu est du XML valide et conforme au schema sitemap.
     */
    private function verifierXmlValide(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        $contenu = $contexte->corpsReponse;

        if (trim($contenu) === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le sitemap est vide, impossible de valider le XML',
            );
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenu);
        $erreurs = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $messagesErreurs = array_map(
                static fn (\LibXMLError $erreur): string => trim($erreur->message),
                $erreurs,
            );

            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le sitemap contient du XML invalide',
                valeurAttendue: 'XML valide',
                valeurObtenue: 'XML invalide',
                details: ['erreurs_xml' => array_slice($messagesErreurs, 0, 10)],
            );
        }

        // Verifier la presence du namespace sitemap
        $namespaces = $xml->getNamespaces(true);
        $estSitemap = false;

        foreach ($namespaces as $ns) {
            if (str_contains($ns, 'sitemaps.org')) {
                $estSitemap = true;
                break;
            }
        }

        // Verifier aussi le nom de la racine
        $racine = $xml->getName();
        $estStructureValide = in_array($racine, ['urlset', 'sitemapindex'], true);

        if (!$estSitemap && !$estStructureValide) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le XML est valide mais ne semble pas etre un sitemap (racine : {$racine})",
                valeurAttendue: 'urlset ou sitemapindex',
                valeurObtenue: $racine,
            );
        }

        return ResultatVerification::succes(
            message: "XML valide, structure sitemap conforme (racine : {$racine})",
            valeurObtenue: $racine,
            details: [
                'namespace_sitemap' => $estSitemap,
                'element_racine' => $racine,
            ],
        );
    }

    /**
     * Verifie que le nombre d'URLs est dans la plage attendue.
     *
     * @param array<string, mixed> $config
     */
    private function verifierComptageUrls(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $xml = $this->chargerXml($contexte->corpsReponse);

        if ($xml === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le sitemap XML pour compter les URLs',
            );
        }

        $nombreUrls = $this->compterUrls($xml);
        $nombreMin = isset($config['nombre_urls_min']) ? (int) $config['nombre_urls_min'] : null;
        $nombreMax = isset($config['nombre_urls_max']) ? (int) $config['nombre_urls_max'] : null;

        // Verification du minimum
        if ($nombreMin !== null && $nombreUrls < $nombreMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le sitemap contient trop peu d'URLs : {$nombreUrls} (minimum attendu : {$nombreMin})",
                valeurAttendue: ">= {$nombreMin}",
                valeurObtenue: (string) $nombreUrls,
                details: ['nombre_urls' => $nombreUrls],
            );
        }

        // Verification du maximum
        if ($nombreMax !== null && $nombreUrls > $nombreMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le sitemap contient trop d'URLs : {$nombreUrls} (maximum attendu : {$nombreMax})",
                valeurAttendue: "<= {$nombreMax}",
                valeurObtenue: (string) $nombreUrls,
                details: ['nombre_urls' => $nombreUrls],
            );
        }

        // Limite officielle Google : 50 000 URLs par sitemap
        $avertissementGoogle = $nombreUrls > 50000
            ? ' (depasse la limite Google de 50 000 URLs par sitemap)'
            : '';

        $plageTexte = match (true) {
            $nombreMin !== null && $nombreMax !== null => "[{$nombreMin}, {$nombreMax}]",
            $nombreMin !== null => ">= {$nombreMin}",
            $nombreMax !== null => "<= {$nombreMax}",
            default => 'sans contrainte',
        };

        return ResultatVerification::succes(
            message: "Le sitemap contient {$nombreUrls} URL(s){$avertissementGoogle}",
            valeurObtenue: (string) $nombreUrls,
            details: [
                'nombre_urls' => $nombreUrls,
                'plage_attendue' => $plageTexte,
            ],
        );
    }

    /**
     * Verifie la presence de la balise lastmod sur les URLs.
     */
    private function verifierLastmodPresent(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $xml = $this->chargerXml($contexte->corpsReponse);

        if ($xml === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le sitemap XML',
            );
        }

        $racine = $xml->getName();
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? null;

        // Compter les URLs avec et sans lastmod
        $totalUrls = 0;
        $avecLastmod = 0;

        if ($racine === 'urlset') {
            $urls = $ns !== null ? $xml->children($ns)->url : $xml->url;
            foreach ($urls as $urlElement) {
                $totalUrls++;
                $lastmod = $ns !== null ? $urlElement->children($ns)->lastmod : $urlElement->lastmod;
                if ($lastmod !== null && (string) $lastmod !== '') {
                    $avecLastmod++;
                }
            }
        } elseif ($racine === 'sitemapindex') {
            $sitemaps = $ns !== null ? $xml->children($ns)->sitemap : $xml->sitemap;
            foreach ($sitemaps as $sitemapElement) {
                $totalUrls++;
                $lastmod = $ns !== null ? $sitemapElement->children($ns)->lastmod : $sitemapElement->lastmod;
                if ($lastmod !== null && (string) $lastmod !== '') {
                    $avecLastmod++;
                }
            }
        }

        if ($totalUrls === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucune entree trouvee dans le sitemap',
            );
        }

        $sansLastmod = $totalUrls - $avecLastmod;
        $pourcentage = round(($avecLastmod / $totalUrls) * 100, 1);

        if ($avecLastmod === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Aucune entree ne contient de balise lastmod ({$totalUrls} entrees)",
                valeurAttendue: 'lastmod present',
                valeurObtenue: '0%',
                details: [
                    'total' => $totalUrls,
                    'avec_lastmod' => 0,
                    'sans_lastmod' => $totalUrls,
                ],
            );
        }

        if ($sansLastmod > 0) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: "{$sansLastmod}/{$totalUrls} entree(s) sans lastmod ({$pourcentage}% couvert)",
                valeurAttendue: '100%',
                valeurObtenue: "{$pourcentage}%",
                details: [
                    'total' => $totalUrls,
                    'avec_lastmod' => $avecLastmod,
                    'sans_lastmod' => $sansLastmod,
                ],
            );
        }

        return ResultatVerification::succes(
            message: "Toutes les {$totalUrls} entree(s) contiennent une balise lastmod",
            valeurObtenue: '100%',
            details: [
                'total' => $totalUrls,
                'avec_lastmod' => $avecLastmod,
            ],
        );
    }

    /**
     * Verifie que la taille du sitemap ne depasse pas le seuil.
     *
     * @param array<string, mixed> $config
     */
    private function verifierTaille(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        // Limite Google : 50 Mo non compresse
        $tailleMaxOctets = (int) ($config['taille_max_octets'] ?? 52428800);
        $taille = $contexte->tailleOctets;

        if ($taille > $tailleMaxOctets) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    'Le sitemap depasse la taille maximale (%s > %s)',
                    $this->formaterOctets($taille),
                    $this->formaterOctets($tailleMaxOctets),
                ),
                valeurAttendue: "<= {$this->formaterOctets($tailleMaxOctets)}",
                valeurObtenue: $this->formaterOctets($taille),
            );
        }

        return ResultatVerification::succes(
            message: "Taille du sitemap conforme ({$this->formaterOctets($taille)})",
            valeurObtenue: $this->formaterOctets($taille),
            details: [
                'taille_octets' => $taille,
                'taille_max_octets' => $tailleMaxOctets,
            ],
        );
    }

    /**
     * Detecte si le sitemap est un sitemap index et compte les sous-sitemaps.
     */
    private function verifierIndex(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        $xml = $this->chargerXml($contexte->corpsReponse);

        if ($xml === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le sitemap XML',
            );
        }

        $racine = $xml->getName();

        if ($racine !== 'sitemapindex') {
            return ResultatVerification::succes(
                message: 'Le sitemap n\'est pas un sitemap index (type : urlset)',
                valeurObtenue: 'urlset',
                details: ['est_index' => false, 'type' => $racine],
            );
        }

        // Compter les sous-sitemaps
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? null;
        $sitemaps = $ns !== null ? $xml->children($ns)->sitemap : $xml->sitemap;

        $sousSitemaps = [];
        foreach ($sitemaps as $sitemap) {
            $loc = $ns !== null ? $sitemap->children($ns)->loc : $sitemap->loc;
            if ($loc !== null) {
                $sousSitemaps[] = (string) $loc;
            }
        }

        $nombre = count($sousSitemaps);

        return ResultatVerification::succes(
            message: "Sitemap index detecte avec {$nombre} sous-sitemap(s)",
            valeurObtenue: (string) $nombre,
            details: [
                'est_index' => true,
                'nombre_sous_sitemaps' => $nombre,
                'sous_sitemaps' => array_slice($sousSitemaps, 0, 50),
            ],
        );
    }

    /**
     * Charge et parse le contenu XML du sitemap.
     */
    private function chargerXml(string $contenu): ?\SimpleXMLElement
    {
        if (trim($contenu) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenu);
        libxml_clear_errors();

        return $xml !== false ? $xml : null;
    }

    /**
     * Compte le nombre d'URLs dans un sitemap (urlset ou sitemapindex).
     */
    private function compterUrls(\SimpleXMLElement $xml): int
    {
        $racine = $xml->getName();
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? null;

        if ($racine === 'urlset') {
            $urls = $ns !== null ? $xml->children($ns)->url : $xml->url;
            return count($urls);
        }

        if ($racine === 'sitemapindex') {
            $sitemaps = $ns !== null ? $xml->children($ns)->sitemap : $xml->sitemap;
            return count($sitemaps);
        }

        return 0;
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
