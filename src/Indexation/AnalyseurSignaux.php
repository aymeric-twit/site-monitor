<?php

declare(strict_types=1);

namespace SiteMonitor\Indexation;

/**
 * Extrait les signaux d'indexation depuis une reponse HTTP.
 */
final class AnalyseurSignaux
{
    /**
     * Extrait la directive meta robots depuis le HTML.
     * Combine les directives de <meta name="robots"> et <meta name="googlebot">.
     */
    public function extraireMetaRobots(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $directives = [];

        // Meta robots generique
        if (preg_match('/<meta\s[^>]*name\s*=\s*["\']robots["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $directives[] = strtolower(trim($m[1]));
        }
        // Variante avec content avant name
        if (preg_match('/<meta\s[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*name\s*=\s*["\']robots["\']/i', $html, $m)) {
            if (!in_array(strtolower(trim($m[1])), $directives, true)) {
                $directives[] = strtolower(trim($m[1]));
            }
        }

        // Meta googlebot (plus specifique, prend le dessus)
        if (preg_match('/<meta\s[^>]*name\s*=\s*["\']googlebot["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $directives[] = strtolower(trim($m[1]));
        }
        if (preg_match('/<meta\s[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*name\s*=\s*["\']googlebot["\']/i', $html, $m)) {
            if (!in_array(strtolower(trim($m[1])), $directives, true)) {
                $directives[] = strtolower(trim($m[1]));
            }
        }

        return $directives !== [] ? implode(', ', $directives) : null;
    }

    /**
     * Extrait l'URL canonical depuis le HTML.
     */
    public function extraireCanonical(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        if (preg_match('/<link\s[^>]*rel\s*=\s*["\']canonical["\'][^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }
        // Variante avec href avant rel
        if (preg_match('/<link\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*rel\s*=\s*["\']canonical["\']/i', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Extrait le X-Robots-Tag depuis les en-tetes HTTP.
     *
     * @param array<string, string> $enTetes
     */
    public function extraireXRobotsTag(array $enTetes): ?string
    {
        foreach ($enTetes as $nom => $valeur) {
            if (strtolower($nom) === 'x-robots-tag') {
                return strtolower(trim($valeur));
            }
        }
        return null;
    }

    /**
     * Determine si les directives indiquent un noindex.
     */
    public function estNoindex(?string $metaRobots, ?string $xRobotsTag): bool
    {
        if ($metaRobots !== null && str_contains(strtolower($metaRobots), 'noindex')) {
            return true;
        }
        if ($xRobotsTag !== null && str_contains(strtolower($xRobotsTag), 'noindex')) {
            return true;
        }
        return false;
    }

    /**
     * Determine si le canonical est auto-referent.
     */
    public function estCanonicalAutoReference(string $url, ?string $canonical): bool
    {
        if ($canonical === null) {
            return false;
        }

        return $this->normaliserUrl($url) === $this->normaliserUrl($canonical);
    }

    /**
     * Normalise une URL pour comparaison (supprime trailing slash, fragment, lowercase host).
     */
    private function normaliserUrl(string $url): string
    {
        $parties = parse_url($url);
        if ($parties === false) {
            return $url;
        }

        $schema = strtolower($parties['scheme'] ?? 'https');
        $hote = strtolower($parties['host'] ?? '');
        $chemin = rtrim($parties['path'] ?? '/', '/') ?: '/';
        $requete = isset($parties['query']) ? '?' . $parties['query'] : '';

        return "{$schema}://{$hote}{$chemin}{$requete}";
    }
}
