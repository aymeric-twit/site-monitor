<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des balises meta SEO essentielles.
 *
 * Controle la presence, la longueur et le contenu des balises title,
 * meta description, robots, canonical, viewport, charset, hreflang,
 * meta refresh et pagination (rel prev/next).
 */
final class VerificateurMetaSeo implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'meta_seo';
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
            'title' => $this->verifierTitle($xpath, $config, $severite),
            'meta_description' => $this->verifierMetaDescription($xpath, $config, $severite),
            'meta_robots' => $this->verifierMetaRobots($xpath, $config, $severite),
            'canonical' => $this->verifierCanonical($xpath, $config, $severite, $contexte->url),
            'viewport' => $this->verifierViewport($xpath, $severite),
            'charset' => $this->verifierCharset($xpath, $severite),
            'hreflang' => $this->verifierHreflang($xpath, $severite),
            'meta_refresh' => $this->verifierMetaRefresh($xpath, $severite),
            'pagination' => $this->verifierPagination($xpath, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification meta inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie la balise <title> : presence, longueur, contenu attendu.
     *
     * @param array<string, mixed> $config
     */
    private function verifierTitle(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/title');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise <title> absente',
                valeurAttendue: 'Presence de la balise <title>',
                valeurObtenue: 'Absente',
            );
        }

        if ($noeuds->length > 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Plusieurs balises <title> detectees ({$noeuds->length})",
                valeurAttendue: '1',
                valeurObtenue: (string) $noeuds->length,
            );
        }

        $contenu = trim($noeuds->item(0)?->textContent ?? '');

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise <title> vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMin = (int) ($config['longueur_min'] ?? 30);
        $longueurMax = (int) ($config['longueur_max'] ?? 60);

        if ($longueur < $longueurMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Title trop court : {$longueur} caracteres (min recommande : {$longueurMin})",
                valeurAttendue: ">= {$longueurMin} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Title trop long : {$longueur} caracteres (max recommande : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        // Verification du contenu attendu (sous-chaine)
        if (!empty($config['contenu_attendu'])) {
            $attendu = $config['contenu_attendu'];
            if (mb_stripos($contenu, $attendu) === false) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Title ne contient pas le texte attendu : \"{$attendu}\"",
                    valeurAttendue: $attendu,
                    valeurObtenue: $contenu,
                );
            }
        }

        return ResultatVerification::succes(
            message: "Title conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la meta description : presence, longueur, contenu attendu.
     *
     * @param array<string, mixed> $config
     */
    private function verifierMetaDescription(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta description absente',
                valeurAttendue: 'Presence de la meta description',
                valeurObtenue: 'Absente',
            );
        }

        if ($noeuds->length > 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Plusieurs meta descriptions detectees ({$noeuds->length})",
                valeurAttendue: '1',
                valeurObtenue: (string) $noeuds->length,
            );
        }

        $contenu = trim($noeuds->item(0)?->getAttribute('content') ?? '');

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta description vide',
                valeurAttendue: 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        $longueur = mb_strlen($contenu);
        $longueurMin = (int) ($config['longueur_min'] ?? 120);
        $longueurMax = (int) ($config['longueur_max'] ?? 160);

        if ($longueur < $longueurMin) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Meta description trop courte : {$longueur} caracteres (min recommande : {$longueurMin})",
                valeurAttendue: ">= {$longueurMin} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        if ($longueur > $longueurMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Meta description trop longue : {$longueur} caracteres (max recommande : {$longueurMax})",
                valeurAttendue: "<= {$longueurMax} caracteres",
                valeurObtenue: "{$longueur} caracteres",
                details: ['contenu' => $contenu],
            );
        }

        if (!empty($config['contenu_attendu'])) {
            $attendu = $config['contenu_attendu'];
            if (mb_stripos($contenu, $attendu) === false) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Meta description ne contient pas le texte attendu : \"{$attendu}\"",
                    valeurAttendue: $attendu,
                    valeurObtenue: $contenu,
                );
            }
        }

        return ResultatVerification::succes(
            message: "Meta description conforme ({$longueur} caracteres)",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la meta robots : presence et valeurs valides.
     *
     * @param array<string, mixed> $config
     */
    private function verifierMetaRobots(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]');

        if ($noeuds === false || $noeuds->length === 0) {
            // Absence de meta robots = index, follow par defaut
            $verifierPresence = (bool) ($config['verifier_presence'] ?? false);
            if ($verifierPresence) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: 'Meta robots absente (par defaut : index, follow)',
                    valeurAttendue: 'Presence de la meta robots',
                    valeurObtenue: 'Absente',
                );
            }

            return ResultatVerification::succes(
                message: 'Meta robots absente (index, follow implicite)',
            );
        }

        $contenu = strtolower(trim($noeuds->item(0)?->getAttribute('content') ?? ''));
        $directives = array_map('trim', explode(',', $contenu));

        // Verification du contenu attendu
        if (!empty($config['contenu_attendu'])) {
            $attendu = strtolower(trim($config['contenu_attendu']));
            $directivesAttendues = array_map('trim', explode(',', $attendu));

            foreach ($directivesAttendues as $directive) {
                if (!in_array($directive, $directives, true)) {
                    return ResultatVerification::echec(
                        severite: $severite,
                        message: "Directive robots manquante : \"{$directive}\"",
                        valeurAttendue: $attendu,
                        valeurObtenue: $contenu,
                        details: ['directives_trouvees' => $directives],
                    );
                }
            }
        }

        // Detection de noindex potentiellement non souhaite
        $valeursBloquantes = ['noindex', 'none'];
        $directivesBloquantes = array_intersect($directives, $valeursBloquantes);
        if (!empty($directivesBloquantes) && empty($config['contenu_attendu'])) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta robots bloque l\'indexation : ' . implode(', ', $directivesBloquantes),
                valeurObtenue: $contenu,
                details: ['directives_bloquantes' => array_values($directivesBloquantes)],
            );
        }

        return ResultatVerification::succes(
            message: "Meta robots conforme : {$contenu}",
            valeurObtenue: $contenu,
            details: ['directives' => $directives],
        );
    }

    /**
     * Verifie la balise canonical : presence, URL valide, auto-reference.
     *
     * @param array<string, mixed> $config
     */
    private function verifierCanonical(\DOMXPath $xpath, array $config, NiveauSeverite $severite, string $urlPage): ResultatVerification
    {
        $noeuds = $xpath->query('//head/link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]');

        if ($noeuds === false || $noeuds->length === 0) {
            $verifierPresence = (bool) ($config['verifier_presence'] ?? true);
            if ($verifierPresence) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: 'Balise canonical absente',
                    valeurAttendue: 'Presence de la balise canonical',
                    valeurObtenue: 'Absente',
                );
            }

            return ResultatVerification::succes(
                message: 'Balise canonical absente (non requise)',
            );
        }

        if ($noeuds->length > 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Plusieurs balises canonical detectees ({$noeuds->length})",
                valeurAttendue: '1',
                valeurObtenue: (string) $noeuds->length,
            );
        }

        $href = trim($noeuds->item(0)?->getAttribute('href') ?? '');

        if ($href === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Balise canonical avec href vide',
                valeurAttendue: 'URL valide',
                valeurObtenue: '(vide)',
            );
        }

        // Verification d'URL valide
        if (filter_var($href, FILTER_VALIDATE_URL) === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "URL canonical invalide : {$href}",
                valeurAttendue: 'URL valide',
                valeurObtenue: $href,
            );
        }

        // Verification auto-reference
        $estAutoReference = $this->normaliserUrl($href) === $this->normaliserUrl($urlPage);
        $details = [
            'href' => $href,
            'auto_reference' => $estAutoReference,
        ];

        if (!empty($config['contenu_attendu'])) {
            $attendu = trim($config['contenu_attendu']);
            if ($this->normaliserUrl($href) !== $this->normaliserUrl($attendu)) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: 'URL canonical ne correspond pas a l\'URL attendue',
                    valeurAttendue: $attendu,
                    valeurObtenue: $href,
                    details: $details,
                );
            }
        }

        $messageSucces = $estAutoReference
            ? "Canonical auto-referent conforme : {$href}"
            : "Canonical pointe vers : {$href}";

        return ResultatVerification::succes(
            message: $messageSucces,
            valeurObtenue: $href,
            details: $details,
        );
    }

    /**
     * Verifie la meta viewport : presence et valeur correcte.
     */
    private function verifierViewport(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="viewport"]');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta viewport absente (indispensable pour le mobile)',
                valeurAttendue: 'Presence de la meta viewport',
                valeurObtenue: 'Absente',
            );
        }

        $contenu = strtolower(trim($noeuds->item(0)?->getAttribute('content') ?? ''));

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta viewport vide',
                valeurAttendue: 'width=device-width, initial-scale=1',
                valeurObtenue: '(vide)',
            );
        }

        // Verification que width=device-width est present
        if (str_contains($contenu, 'width=device-width') === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Meta viewport ne contient pas "width=device-width"',
                valeurAttendue: 'width=device-width',
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::succes(
            message: "Meta viewport conforme : {$contenu}",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la declaration charset : presence et utf-8.
     */
    private function verifierCharset(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        // Verification de <meta charset="...">
        $noeudsCharset = $xpath->query('//head/meta[@charset]');
        if ($noeudsCharset !== false && $noeudsCharset->length > 0) {
            $charset = strtolower(trim($noeudsCharset->item(0)?->getAttribute('charset') ?? ''));

            if ($charset !== 'utf-8') {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Charset non UTF-8 : {$charset}",
                    valeurAttendue: 'utf-8',
                    valeurObtenue: $charset,
                );
            }

            return ResultatVerification::succes(
                message: 'Charset UTF-8 declare correctement',
                valeurObtenue: $charset,
            );
        }

        // Verification alternative via Content-Type
        $noeudsContentType = $xpath->query('//head/meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="content-type"]');
        if ($noeudsContentType !== false && $noeudsContentType->length > 0) {
            $contenu = strtolower(trim($noeudsContentType->item(0)?->getAttribute('content') ?? ''));
            if (str_contains($contenu, 'utf-8')) {
                return ResultatVerification::succes(
                    message: 'Charset UTF-8 declare via http-equiv Content-Type',
                    valeurObtenue: $contenu,
                );
            }

            return ResultatVerification::echec(
                severite: $severite,
                message: "Charset declare via http-equiv mais pas en UTF-8 : {$contenu}",
                valeurAttendue: 'utf-8',
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::echec(
            severite: $severite,
            message: 'Aucune declaration de charset trouvee',
            valeurAttendue: '<meta charset="utf-8">',
            valeurObtenue: 'Absente',
        );
    }

    /**
     * Verifie les balises hreflang : presence et format valide.
     */
    private function verifierHreflang(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="alternate"][@hreflang]');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucune balise hreflang detectee',
                valeurAttendue: 'Presence de balises hreflang',
                valeurObtenue: 'Absente',
            );
        }

        $langues = [];
        $erreurs = [];

        for ($i = 0; $i < $noeuds->length; $i++) {
            $noeud = $noeuds->item($i);
            if ($noeud === null) {
                continue;
            }

            $hreflang = trim($noeud->getAttribute('hreflang'));
            $href = trim($noeud->getAttribute('href'));

            // Format valide : xx, xx-XX, ou x-default
            if ($hreflang !== 'x-default' && preg_match('/^[a-z]{2}(-[A-Za-z]{2,})?$/', $hreflang) !== 1) {
                $erreurs[] = "Format hreflang invalide : \"{$hreflang}\"";
            }

            if ($href === '') {
                $erreurs[] = "Href vide pour hreflang=\"{$hreflang}\"";
            }

            $langues[] = $hreflang;
        }

        if (!empty($erreurs)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Erreurs dans les balises hreflang : ' . implode(' ; ', $erreurs),
                valeurObtenue: implode(', ', $langues),
                details: ['langues' => $langues, 'erreurs' => $erreurs],
            );
        }

        // Verification de la presence de x-default
        $aXDefault = in_array('x-default', $langues, true);

        return ResultatVerification::succes(
            message: sprintf(
                '%d balise(s) hreflang detectee(s) : %s%s',
                count($langues),
                implode(', ', $langues),
                $aXDefault ? '' : ' (x-default absent)',
            ),
            valeurObtenue: implode(', ', $langues),
            details: ['langues' => $langues, 'x_default' => $aXDefault],
        );
    }

    /**
     * Detecte la presence d'une meta refresh (generalement deconseille en SEO).
     */
    private function verifierMetaRefresh(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//head/meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="refresh"]');

        if ($noeuds !== false && $noeuds->length > 0) {
            $contenu = trim($noeuds->item(0)?->getAttribute('content') ?? '');

            return ResultatVerification::echec(
                severite: $severite,
                message: "Meta refresh detectee : \"{$contenu}\" (deconseille pour le SEO)",
                valeurAttendue: 'Aucune meta refresh',
                valeurObtenue: $contenu,
            );
        }

        return ResultatVerification::succes(
            message: 'Aucune meta refresh detectee',
        );
    }

    /**
     * Verifie les balises de pagination rel="prev" et rel="next".
     */
    private function verifierPagination(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeudsPrev = $xpath->query('//head/link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="prev"]');
        $noeudsNext = $xpath->query('//head/link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="next"]');

        $aPrev = $noeudsPrev !== false && $noeudsPrev->length > 0;
        $aNext = $noeudsNext !== false && $noeudsNext->length > 0;

        if (!$aPrev && !$aNext) {
            return ResultatVerification::succes(
                message: 'Aucune balise de pagination detectee (page non paginee)',
                details: ['prev' => false, 'next' => false],
            );
        }

        $details = [
            'prev' => $aPrev ? trim($noeudsPrev->item(0)?->getAttribute('href') ?? '') : null,
            'next' => $aNext ? trim($noeudsNext->item(0)?->getAttribute('href') ?? '') : null,
        ];

        return ResultatVerification::succes(
            message: sprintf(
                'Pagination detectee : %s',
                implode(', ', array_filter([
                    $aPrev ? 'rel="prev"' : null,
                    $aNext ? 'rel="next"' : null,
                ])),
            ),
            details: $details,
        );
    }

    /**
     * Normalise une URL pour comparaison (supprime le fragment et le trailing slash).
     */
    private function normaliserUrl(string $url): string
    {
        // Suppression du fragment
        $url = explode('#', $url)[0];
        // Suppression du trailing slash sauf pour la racine
        $urlParsee = parse_url($url);
        $chemin = $urlParsee['path'] ?? '/';
        if ($chemin !== '/' && str_ends_with($chemin, '/')) {
            $chemin = rtrim($chemin, '/');
        }

        return sprintf(
            '%s://%s%s%s',
            $urlParsee['scheme'] ?? 'https',
            $urlParsee['host'] ?? '',
            $chemin,
            isset($urlParsee['query']) ? '?' . $urlParsee['query'] : '',
        );
    }
}
