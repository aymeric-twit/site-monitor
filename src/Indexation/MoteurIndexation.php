<?php

declare(strict_types=1);

namespace SiteMonitor\Indexation;

use SiteMonitor\Core\StatutIndexation;
use SiteMonitor\Core\TypeContradiction;
use SiteMonitor\Moteur\ClientHttp;
use SiteMonitor\Stockage\DepotAuditIndexation;
use SiteMonitor\Stockage\DepotResultatIndexation;

/**
 * Orchestrateur principal de l'audit d'indexation.
 *
 * Croise 5 signaux (HTTP status, meta robots, canonical, robots.txt, sitemap)
 * pour detecter les contradictions d'indexation.
 */
final class MoteurIndexation
{
    /** @var array<string, AnalyseurRobotsTxt> Cache domaine → analyseur robots.txt */
    private array $cacheRobotsTxt = [];

    /** @var array<string, array<string, true>> Cache domaine → URLs du sitemap (hashmap) */
    private array $cacheSitemapUrls = [];

    public function __construct(
        private readonly ClientHttp $clientHttp,
        private readonly DepotAuditIndexation $depotAudit,
        private readonly DepotResultatIndexation $depotResultat,
        private readonly AnalyseurSignaux $analyseurSignaux,
        private readonly DetecteurContradictions $detecteurContradictions,
    ) {}

    /**
     * Execute un audit d'indexation complet.
     *
     * @param string[] $urls URLs a auditer
     * @param \Closure|null $surProgression Callback(int $traitees, int $total, string $etape)
     */
    public function auditer(
        int $auditId,
        array $urls,
        string $domaine,
        ?\Closure $surProgression = null,
        int $delaiMs = 500,
    ): void {
        $total = count($urls);
        $this->depotAudit->mettreAJourProgression($auditId, 0, $total);

        // Etape 1 : Charger et parser robots.txt
        $surProgression?->call($this, 0, $total, 'Analyse du robots.txt...');
        $this->chargerRobotsTxt($domaine);

        // Etape 2 : Extraire et parser les sitemaps
        $surProgression?->call($this, 0, $total, 'Extraction des sitemaps...');
        $this->chargerSitemaps($domaine);

        // Etape 3 : Analyser chaque URL
        $compteurs = [
            'indexables' => 0,
            'non_indexables' => 0,
            'contradictoires' => 0,
        ];

        foreach ($urls as $index => $url) {
            $surProgression?->call($this, $index + 1, $total, "Analyse : {$url}");

            try {
                $resultat = $this->analyserUrl($auditId, $url, $domaine);

                match ($resultat) {
                    StatutIndexation::Indexable => $compteurs['indexables']++,
                    StatutIndexation::NonIndexable => $compteurs['non_indexables']++,
                    StatutIndexation::Contradictoire => $compteurs['contradictoires']++,
                    StatutIndexation::Erreur => $compteurs['non_indexables']++,
                };
            } catch (\Throwable) {
                $compteurs['non_indexables']++;
            }

            $this->depotAudit->mettreAJourProgression($auditId, $index + 1, $total);

            // Delai entre requetes pour ne pas surcharger le serveur cible
            if ($delaiMs > 0 && $index < $total - 1) {
                usleep($delaiMs * 1000);
            }
        }

        // Etape 4 : Terminer l'audit avec les KPIs finaux
        $this->depotAudit->terminer(
            $auditId,
            $compteurs['indexables'],
            $compteurs['non_indexables'],
            $compteurs['contradictoires'],
        );
    }

    /**
     * Analyse une URL et persiste le resultat.
     */
    private function analyserUrl(int $auditId, string $url, string $domaine): StatutIndexation
    {
        // Fetch HTTP
        $contexte = $this->clientHttp->recuperer($url);

        // Extraire les signaux
        $metaRobots = $this->analyseurSignaux->extraireMetaRobots($contexte->corpsReponse);
        $xRobotsTag = $this->analyseurSignaux->extraireXRobotsTag($contexte->enTetes);
        $canonical = $this->analyseurSignaux->extraireCanonical($contexte->corpsReponse);
        $estNoindex = $this->analyseurSignaux->estNoindex($metaRobots, $xRobotsTag);
        $canonicalAutoRef = $this->analyseurSignaux->estCanonicalAutoReference($url, $canonical);

        // Tester robots.txt
        $robotsTxtAutorise = true;
        $robotsTxtRegle = null;
        if (isset($this->cacheRobotsTxt[$domaine])) {
            $resultatRobots = $this->cacheRobotsTxt[$domaine]->estAutorise($url, 'Googlebot');
            $robotsTxtAutorise = $resultatRobots->autorise;
            $robotsTxtRegle = $resultatRobots->regleAppliquee?->directiveBrute ?? $resultatRobots->raison;
        }

        // Verifier presence dans sitemap
        $urlFinale = $contexte->urlFinale ?? $url;
        $presentSitemap = $this->estDansSitemap($domaine, $url) || $this->estDansSitemap($domaine, $urlFinale);

        // Detecter les contradictions
        $analyse = $this->detecteurContradictions->analyser(
            codeHttp: $contexte->codeHttp,
            estNoindex: $estNoindex,
            canonical: $canonical,
            canonicalAutoReference: $canonicalAutoRef,
            robotsTxtAutorise: $robotsTxtAutorise,
            presentSitemap: $presentSitemap,
        );

        /** @var StatutIndexation $statut */
        $statut = $analyse['statut'];
        /** @var TypeContradiction[] $contradictions */
        $contradictions = $analyse['contradictions'];

        // Calculer la severite max
        $severiteMax = null;
        if ($contradictions !== []) {
            $ordresSeverite = ['critique' => 3, 'attention' => 2, 'info' => 1];
            $maxOrdre = 0;
            foreach ($contradictions as $c) {
                $ordre = $ordresSeverite[$c->severite()] ?? 0;
                if ($ordre > $maxOrdre) {
                    $maxOrdre = $ordre;
                    $severiteMax = $c->severite();
                }
            }
        }

        // Serialiser les contradictions
        $contradictionsJson = $contradictions !== []
            ? array_map(fn(TypeContradiction $c) => [
                'type' => $c->value,
                'severite' => $c->severite(),
                'message' => $c->message(),
            ], $contradictions)
            : null;

        // Persister le resultat
        $this->depotResultat->creer([
            'audit_id' => $auditId,
            'url' => $url,
            'code_http' => $contexte->codeHttp,
            'url_finale' => $contexte->urlFinale,
            'meta_robots' => $metaRobots,
            'x_robots_tag' => $xRobotsTag,
            'canonical' => $canonical,
            'canonical_auto_reference' => $canonicalAutoRef ? 1 : 0,
            'robots_txt_autorise' => $robotsTxtAutorise ? 1 : 0,
            'robots_txt_regle' => $robotsTxtRegle,
            'present_sitemap' => $presentSitemap ? 1 : 0,
            'statut_indexation' => $statut->value,
            'contradictions_json' => $contradictionsJson !== null
                ? json_encode($contradictionsJson, JSON_UNESCAPED_UNICODE)
                : null,
            'severite_max' => $severiteMax,
            'verifie_le' => date('Y-m-d H:i:s'),
        ]);

        return $statut;
    }

    /**
     * Charge et parse le robots.txt du domaine.
     */
    private function chargerRobotsTxt(string $domaine): void
    {
        if (isset($this->cacheRobotsTxt[$domaine])) {
            return;
        }

        $urlRobots = rtrim($domaine, '/') . '/robots.txt';
        $contexte = $this->clientHttp->recuperer($urlRobots);

        if ($contexte->codeHttp === 200 && $contexte->corpsReponse !== '') {
            $this->cacheRobotsTxt[$domaine] = new AnalyseurRobotsTxt($contexte->corpsReponse);
        }
    }

    /**
     * Charge les URLs du sitemap du domaine.
     */
    private function chargerSitemaps(string $domaine): void
    {
        if (isset($this->cacheSitemapUrls[$domaine])) {
            return;
        }

        $this->cacheSitemapUrls[$domaine] = [];
        $urlsSitemap = [];

        // Extraire les sitemaps depuis robots.txt
        if (isset($this->cacheRobotsTxt[$domaine])) {
            $urlsSitemap = $this->cacheRobotsTxt[$domaine]->obtenirSitemaps();
        }

        // Fallback : essayer /sitemap.xml
        if ($urlsSitemap === []) {
            $urlsSitemap = [rtrim($domaine, '/') . '/sitemap.xml'];
        }

        // Parser chaque sitemap
        $extracteur = new ExtractionSitemap();
        foreach ($urlsSitemap as $urlSitemap) {
            try {
                $urls = $extracteur->extraire($urlSitemap);
                foreach ($urls as $url) {
                    $this->cacheSitemapUrls[$domaine][$this->normaliserUrlSitemap($url)] = true;
                }
            } catch (\Throwable) {
                // Sitemap inaccessible, on continue
            }
        }
    }

    /**
     * Verifie si une URL est presente dans le sitemap du domaine.
     */
    private function estDansSitemap(string $domaine, string $url): bool
    {
        return isset($this->cacheSitemapUrls[$domaine][$this->normaliserUrlSitemap($url)]);
    }

    /**
     * Normalise une URL pour la comparaison avec le sitemap.
     */
    private function normaliserUrlSitemap(string $url): string
    {
        $parties = parse_url($url);
        if ($parties === false) {
            return $url;
        }

        $schema = strtolower($parties['scheme'] ?? 'https');
        $hote = strtolower($parties['host'] ?? '');
        $chemin = $parties['path'] ?? '/';

        return "{$schema}://{$hote}{$chemin}";
    }
}
