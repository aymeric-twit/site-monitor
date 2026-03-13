<?php

declare(strict_types=1);

namespace SiteMonitor\Indexation;

/**
 * Extraction recursive d'URLs depuis des sitemaps XML.
 *
 * Supporte : sitemap index, urlset, .xml.gz.
 * Zero dependance externe — simplexml + file_get_contents natifs.
 */
class ExtractionSitemap
{
    private string $userAgent = 'SiteMonitor/1.0 (PHP)';
    private int $timeout = 30;
    private int $tentatives = 3;
    private int $profondeurMax = 10;

    /** @var string[] */
    private array $urls = [];
    /** @var string[] */
    private array $erreurs = [];
    private int $compteurSitemaps = 0;

    // --- Setters chainables ---

    public function setUserAgent(string $ua): self
    {
        $this->userAgent = $ua;
        return $this;
    }

    public function setTimeout(int $t): self
    {
        $this->timeout = max(1, $t);
        return $this;
    }

    public function setTentatives(int $r): self
    {
        $this->tentatives = max(1, $r);
        return $this;
    }

    public function setProfondeurMax(int $d): self
    {
        $this->profondeurMax = max(1, $d);
        return $this;
    }

    // --- Extraction principale ---

    /**
     * Extraire toutes les URLs a partir d'une URL de sitemap.
     *
     * @return string[]
     */
    public function extraire(string $url): array
    {
        $this->reinitialiser();
        $this->traiterSitemap($url, 0);

        return $this->urls;
    }

    /**
     * Detecter les sitemaps depuis robots.txt, puis extraire les URLs.
     *
     * @return string[]
     */
    public function extraireDepuisRobots(string $urlBase): array
    {
        $this->reinitialiser();
        $urlRobots = rtrim($urlBase, '/') . '/robots.txt';

        $contenu = $this->telechargerUrl($urlRobots);
        if ($contenu === null) {
            $this->erreurs[] = "Impossible de lire robots.txt : $urlRobots";
            $this->traiterSitemap(rtrim($urlBase, '/') . '/sitemap.xml', 0);
        } else {
            $urlsSitemap = [];
            foreach (explode("\n", $contenu) as $ligne) {
                if (preg_match('/^Sitemap:\s*(.+)$/i', trim($ligne), $m)) {
                    $urlsSitemap[] = trim($m[1]);
                }
            }

            if (empty($urlsSitemap)) {
                $urlsSitemap[] = rtrim($urlBase, '/') . '/sitemap.xml';
            }

            foreach ($urlsSitemap as $urlSitemap) {
                $this->traiterSitemap($urlSitemap, 0);
            }
        }

        return $this->urls;
    }

    // --- Getters ---

    /** @return string[] */
    public function getErreurs(): array
    {
        return $this->erreurs;
    }

    /**
     * @return array{totalUrls: int, totalSitemaps: int, totalErreurs: int}
     */
    public function getStatistiques(): array
    {
        return [
            'totalUrls'     => count($this->urls),
            'totalSitemaps' => $this->compteurSitemaps,
            'totalErreurs'  => count($this->erreurs),
        ];
    }

    // --- Traitement recursif ---

    private function traiterSitemap(string $url, int $profondeur): void
    {
        if ($profondeur > $this->profondeurMax) {
            $this->erreurs[] = "Profondeur max ({$this->profondeurMax}) atteinte : $url";
            return;
        }

        $contenu = $this->telechargerUrl($url);
        if ($contenu === null) {
            return;
        }

        // Decompression gzip si fichier .gz
        $chemin = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        if (str_ends_with($chemin, '.gz')) {
            $decode = @gzdecode($contenu);
            if ($decode === false) {
                $this->erreurs[] = "Decompression gzip echouee : $url";
                return;
            }
            $contenu = $decode;
        }

        // Parser XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenu);
        if ($xml === false) {
            $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            $this->erreurs[] = "XML invalide ($url) : " . implode('; ', $errs);
            return;
        }

        $this->compteurSitemaps++;
        $namespaces = $xml->getNamespaces(true);
        $nomRacine = $xml->getName();

        if ($nomRacine === 'sitemapindex') {
            $this->traiterSitemapIndex($xml, $namespaces, $profondeur);
        } elseif ($nomRacine === 'urlset') {
            $this->traiterUrlset($xml, $namespaces);
        } else {
            $this->erreurs[] = "Type de sitemap inconnu ($nomRacine) : $url";
        }
    }

    /**
     * @param array<string, string> $namespaces
     */
    private function traiterSitemapIndex(\SimpleXMLElement $xml, array $namespaces, int $profondeur): void
    {
        $ns = $namespaces[''] ?? 'http://www.sitemaps.org/schemas/sitemap/0.9';

        foreach ($xml->children($ns) as $enfant) {
            if ($enfant->getName() !== 'sitemap') {
                continue;
            }

            $enfantsNs = $enfant->children($ns);
            $loc = isset($enfantsNs->loc) ? trim((string) $enfantsNs->loc) : null;

            if ($loc !== null && $loc !== '') {
                $this->traiterSitemap($loc, $profondeur + 1);
            }
        }
    }

    /**
     * @param array<string, string> $namespaces
     */
    private function traiterUrlset(\SimpleXMLElement $xml, array $namespaces): void
    {
        $ns = $namespaces[''] ?? 'http://www.sitemaps.org/schemas/sitemap/0.9';

        foreach ($xml->children($ns) as $noeudUrl) {
            if ($noeudUrl->getName() !== 'url') {
                continue;
            }

            $enfants = $noeudUrl->children($ns);
            $loc = isset($enfants->loc) ? trim((string) $enfants->loc) : null;
            if ($loc === null || $loc === '') {
                continue;
            }

            $this->urls[] = $loc;
        }
    }

    // --- Reseau ---

    /**
     * Verifier qu'une URL est publique (anti-SSRF).
     *
     * Rejette les schemas non HTTP(S) et les hotes resolvant vers des IP
     * privees ou reservees (127.0.0.0/8, 10/8, 172.16/12, 192.168/16, ::1...).
     */
    public static function estUrlPublique(string $url): bool
    {
        $parties = parse_url($url);
        $scheme = strtolower($parties['scheme'] ?? '');
        $hote = $parties['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $hote === '') {
            return false;
        }

        $ip = gethostbyname($hote);

        // gethostbyname renvoie le hostname tel quel en cas d'echec
        if ($ip === $hote && !filter_var($hote, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Rejeter les IP privees et reservees (IPv4)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    private function telechargerUrl(string $url): ?string
    {
        if (!self::estUrlPublique($url)) {
            $this->erreurs[] = "URL bloquee (adresse privee/reservee) : $url";
            return null;
        }

        $codeHttp = 0;

        for ($tentative = 1; $tentative <= $this->tentatives; $tentative++) {
            $contexte = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'header'          => "User-Agent: {$this->userAgent}\r\nAccept-Encoding: gzip\r\n",
                    'timeout'         => $this->timeout,
                    'follow_location' => true,
                    'max_redirects'   => 5,
                    'ignore_errors'   => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $contenu = @file_get_contents($url, false, $contexte);
            $codeHttp = $this->extraireCodeHttp($http_response_header ?? []);

            if ($contenu !== false && $codeHttp >= 200 && $codeHttp < 300) {
                // Decompression Content-Encoding gzip
                $encoding = $this->extraireHeader($http_response_header ?? [], 'Content-Encoding');
                if (stripos($encoding, 'gzip') !== false) {
                    $decode = @gzdecode($contenu);
                    if ($decode !== false) {
                        $contenu = $decode;
                    }
                }
                return $contenu;
            }

            if ($tentative < $this->tentatives) {
                sleep($tentative * 2);
            }
        }

        $this->erreurs[] = "Echec apres {$this->tentatives} tentatives : $url (HTTP $codeHttp)";

        return null;
    }

    /**
     * @param string[] $headers
     */
    private function extraireCodeHttp(array $headers): int
    {
        for ($i = count($headers) - 1; $i >= 0; $i--) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $headers[$i], $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * @param string[] $headers
     */
    private function extraireHeader(array $headers, string $nom): string
    {
        for ($i = count($headers) - 1; $i >= 0; $i--) {
            if (stripos($headers[$i], "$nom:") === 0) {
                return trim(substr($headers[$i], strlen($nom) + 1));
            }
        }
        return '';
    }

    // --- Helpers internes ---

    private function reinitialiser(): void
    {
        $this->urls = [];
        $this->erreurs = [];
        $this->compteurSitemaps = 0;
    }
}
