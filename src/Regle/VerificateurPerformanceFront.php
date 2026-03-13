<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de performance front-end.
 *
 * Detecte les CSS/JS bloquants dans le <head>, compte les ressources externes,
 * mesure le CSS/JS inline excessif, verifie les hints de preconnect/preload,
 * et detecte le mixed content (HTTP sur pages HTTPS).
 */
final class VerificateurPerformanceFront implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'performance_front';
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
            'css_bloquants' => $this->verifierCssBloquants($xpath, $config, $severite),
            'js_bloquants' => $this->verifierJsBloquants($xpath, $config, $severite),
            'comptage_ressources' => $this->verifierComptageRessources($xpath, $config, $severite),
            'inline_excessif' => $this->verifierInlineExcessif($xpath, $config, $severite),
            'preconnect' => $this->verifierPreconnect($xpath, $severite),
            'mixed_content' => $this->verifierMixedContent($xpath, $severite, $contexte->url),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification performance front inconnu : {$verification}",
            ),
        };
    }

    /**
     * Detecte les feuilles CSS bloquantes dans le <head> (sans media="print" ni disabled).
     *
     * @param array<string, mixed> $config
     */
    private function verifierCssBloquants(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        // CSS bloquants = <link rel="stylesheet"> dans le head sans media non-bloquant
        $cssBloquants = $xpath->query('//head/link[@rel="stylesheet" and not(@media="print") and not(@disabled)]');
        $nbBloquants = $cssBloquants !== false ? $cssBloquants->length : 0;

        $maxCss = isset($config['max_css']) ? (int) $config['max_css'] : null;

        $fichiers = [];
        if ($cssBloquants !== false) {
            for ($i = 0; $i < $cssBloquants->length; $i++) {
                $href = $cssBloquants->item($i)?->getAttribute('href') ?? '';
                if ($href !== '') {
                    $fichiers[] = mb_strimwidth($href, 0, 120, '...');
                }
            }
        }

        if ($maxCss !== null && $nbBloquants > $maxCss) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbBloquants} feuille(s) CSS bloquante(s) dans le <head> (max : {$maxCss})",
                valeurAttendue: "<= {$maxCss}",
                valeurObtenue: (string) $nbBloquants,
                details: ['fichiers_css' => $fichiers],
            );
        }

        if ($nbBloquants > 3 && $maxCss === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbBloquants} feuille(s) CSS bloquante(s) dans le <head> (nombre eleve)",
                valeurObtenue: (string) $nbBloquants,
                details: ['fichiers_css' => $fichiers],
            );
        }

        return ResultatVerification::succes(
            message: "{$nbBloquants} feuille(s) CSS bloquante(s) dans le <head>",
            valeurObtenue: (string) $nbBloquants,
            details: ['fichiers_css' => $fichiers],
        );
    }

    /**
     * Detecte les scripts JS bloquants dans le <head> (sans async, defer, type="module").
     *
     * @param array<string, mixed> $config
     */
    private function verifierJsBloquants(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        // Scripts bloquants = <script src="..."> dans le head sans async/defer/module
        $scriptsHead = $xpath->query('//head/script[@src and not(@async) and not(@defer) and not(@type="module")]');
        $nbBloquants = $scriptsHead !== false ? $scriptsHead->length : 0;

        $maxJs = isset($config['max_js']) ? (int) $config['max_js'] : null;

        $fichiers = [];
        if ($scriptsHead !== false) {
            for ($i = 0; $i < $scriptsHead->length; $i++) {
                $src = $scriptsHead->item($i)?->getAttribute('src') ?? '';
                if ($src !== '') {
                    $fichiers[] = mb_strimwidth($src, 0, 120, '...');
                }
            }
        }

        if ($maxJs !== null && $nbBloquants > $maxJs) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbBloquants} script(s) JS bloquant(s) dans le <head> (max : {$maxJs})",
                valeurAttendue: "<= {$maxJs}",
                valeurObtenue: (string) $nbBloquants,
                details: ['fichiers_js' => $fichiers],
            );
        }

        if ($nbBloquants > 0 && $maxJs === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbBloquants} script(s) JS bloquant(s) dans le <head> (sans async/defer)",
                valeurObtenue: (string) $nbBloquants,
                details: ['fichiers_js' => $fichiers],
            );
        }

        return ResultatVerification::succes(
            message: 'Aucun script JS bloquant dans le <head>',
            valeurObtenue: '0',
        );
    }

    /**
     * Compte le nombre total de ressources externes (CSS + JS).
     *
     * @param array<string, mixed> $config
     */
    private function verifierComptageRessources(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $cssExternes = $xpath->query('//link[@rel="stylesheet" and @href]');
        $jsExternes = $xpath->query('//script[@src]');

        $nbCss = $cssExternes !== false ? $cssExternes->length : 0;
        $nbJs = $jsExternes !== false ? $jsExternes->length : 0;
        $total = $nbCss + $nbJs;

        $maxCss = isset($config['max_css']) ? (int) $config['max_css'] : null;
        $maxJs = isset($config['max_js']) ? (int) $config['max_js'] : null;

        $erreurs = [];

        if ($maxCss !== null && $nbCss > $maxCss) {
            $erreurs[] = "CSS: {$nbCss} (max: {$maxCss})";
        }
        if ($maxJs !== null && $nbJs > $maxJs) {
            $erreurs[] = "JS: {$nbJs} (max: {$maxJs})";
        }

        $details = [
            'css_externes' => $nbCss,
            'js_externes' => $nbJs,
            'total' => $total,
        ];

        if (!empty($erreurs)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Nombre de ressources excessif : ' . implode(', ', $erreurs),
                valeurObtenue: "CSS: {$nbCss}, JS: {$nbJs}",
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: "{$total} ressource(s) externe(s) : {$nbCss} CSS, {$nbJs} JS",
            valeurObtenue: (string) $total,
            details: $details,
        );
    }

    /**
     * Detecte le CSS/JS inline excessif (depassant un seuil en octets).
     *
     * @param array<string, mixed> $config
     */
    private function verifierInlineExcessif(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $seuilOctets = (int) ($config['seuil_inline_octets'] ?? 10000);

        // CSS inline : <style>...</style>
        $stylesInline = $xpath->query('//style');
        $tailleCssInline = 0;
        $nbStylesInline = 0;
        if ($stylesInline !== false) {
            for ($i = 0; $i < $stylesInline->length; $i++) {
                $contenu = $stylesInline->item($i)?->textContent ?? '';
                $tailleCssInline += strlen($contenu);
                $nbStylesInline++;
            }
        }

        // JS inline : <script> sans src (et pas JSON-LD)
        $scriptsInline = $xpath->query('//script[not(@src) and not(@type="application/ld+json") and not(@type="application/json")]');
        $tailleJsInline = 0;
        $nbScriptsInline = 0;
        if ($scriptsInline !== false) {
            for ($i = 0; $i < $scriptsInline->length; $i++) {
                $contenu = $scriptsInline->item($i)?->textContent ?? '';
                $tailleJsInline += strlen($contenu);
                $nbScriptsInline++;
            }
        }

        $totalInline = $tailleCssInline + $tailleJsInline;

        $details = [
            'css_inline_octets' => $tailleCssInline,
            'js_inline_octets' => $tailleJsInline,
            'total_octets' => $totalInline,
            'nb_styles' => $nbStylesInline,
            'nb_scripts' => $nbScriptsInline,
            'seuil' => $seuilOctets,
        ];

        if ($totalInline > $seuilOctets) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    'CSS/JS inline excessif : %s octets (seuil : %s)',
                    number_format($totalInline, 0, ',', ' '),
                    number_format($seuilOctets, 0, ',', ' '),
                ),
                valeurAttendue: "<= {$seuilOctets} octets",
                valeurObtenue: "{$totalInline} octets",
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: sprintf(
                'CSS/JS inline acceptable : %s octets (CSS: %s, JS: %s)',
                number_format($totalInline, 0, ',', ' '),
                number_format($tailleCssInline, 0, ',', ' '),
                number_format($tailleJsInline, 0, ',', ' '),
            ),
            valeurObtenue: "{$totalInline} octets",
            details: $details,
        );
    }

    /**
     * Verifie la presence de resource hints (preconnect, dns-prefetch, preload).
     */
    private function verifierPreconnect(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $preconnect = $xpath->query('//head/link[@rel="preconnect"]');
        $dnsPrefetch = $xpath->query('//head/link[@rel="dns-prefetch"]');
        $preload = $xpath->query('//head/link[@rel="preload"]');

        $nbPreconnect = $preconnect !== false ? $preconnect->length : 0;
        $nbDnsPrefetch = $dnsPrefetch !== false ? $dnsPrefetch->length : 0;
        $nbPreload = $preload !== false ? $preload->length : 0;

        $total = $nbPreconnect + $nbDnsPrefetch + $nbPreload;

        $domainesPreconnect = [];
        if ($preconnect !== false) {
            for ($i = 0; $i < $preconnect->length; $i++) {
                $href = $preconnect->item($i)?->getAttribute('href') ?? '';
                if ($href !== '') {
                    $domainesPreconnect[] = $href;
                }
            }
        }

        $ressourcesPreload = [];
        if ($preload !== false) {
            for ($i = 0; $i < $preload->length; $i++) {
                $noeud = $preload->item($i);
                if ($noeud === null) {
                    continue;
                }
                $href = $noeud->getAttribute('href');
                $as = $noeud->getAttribute('as');
                if ($href !== '') {
                    $ressourcesPreload[] = [
                        'href' => mb_strimwidth($href, 0, 120, '...'),
                        'as' => $as,
                    ];
                }
            }
        }

        $details = [
            'preconnect' => $nbPreconnect,
            'dns_prefetch' => $nbDnsPrefetch,
            'preload' => $nbPreload,
            'domaines_preconnect' => $domainesPreconnect,
            'ressources_preload' => $ressourcesPreload,
        ];

        if ($total === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun resource hint detecte (preconnect, dns-prefetch, preload)',
                valeurAttendue: 'Au moins un resource hint',
                valeurObtenue: '0',
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: sprintf(
                '%d resource hint(s) : %d preconnect, %d dns-prefetch, %d preload',
                $total,
                $nbPreconnect,
                $nbDnsPrefetch,
                $nbPreload,
            ),
            valeurObtenue: (string) $total,
            details: $details,
        );
    }

    /**
     * Detecte le mixed content : ressources HTTP chargees sur une page HTTPS.
     */
    private function verifierMixedContent(\DOMXPath $xpath, NiveauSeverite $severite, string $urlPage): ResultatVerification
    {
        // Ne verifier que si la page est en HTTPS
        if (!str_starts_with($urlPage, 'https://')) {
            return ResultatVerification::succes(
                message: 'Page non HTTPS, verification du mixed content non applicable',
                details: ['raison' => 'Page servie en HTTP'],
            );
        }

        $selecteurs = [
            '//script[@src]' => 'src',
            '//link[@href and @rel="stylesheet"]' => 'href',
            '//img[@src]' => 'src',
            '//iframe[@src]' => 'src',
            '//video[@src]' => 'src',
            '//audio[@src]' => 'src',
            '//source[@src]' => 'src',
            '//object[@data]' => 'data',
            '//embed[@src]' => 'src',
        ];

        $ressourcesHttp = [];

        foreach ($selecteurs as $selecteur => $attribut) {
            $noeuds = $xpath->query($selecteur);
            if ($noeuds === false) {
                continue;
            }

            for ($i = 0; $i < $noeuds->length; $i++) {
                $noeud = $noeuds->item($i);
                if ($noeud === null) {
                    continue;
                }

                $url = trim($noeud->getAttribute($attribut));

                // Detection explicite des URL http:// (pas les relatives ni les protocol-relative)
                if (str_starts_with($url, 'http://')) {
                    $ressourcesHttp[] = [
                        'type' => $noeud->nodeName,
                        'url' => mb_strimwidth($url, 0, 120, '...'),
                    ];
                }
            }
        }

        $nbMixed = count($ressourcesHttp);

        if ($nbMixed > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nbMixed} ressource(s) en HTTP detectee(s) sur une page HTTPS (mixed content)",
                valeurAttendue: '0 ressource HTTP',
                valeurObtenue: "{$nbMixed} ressource(s) HTTP",
                details: ['ressources_http' => array_slice($ressourcesHttp, 0, 20)],
            );
        }

        return ResultatVerification::succes(
            message: 'Aucun mixed content detecte',
            valeurObtenue: '0',
        );
    }
}
