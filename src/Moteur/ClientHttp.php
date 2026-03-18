<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Regle\ContexteVerification;

/**
 * Client HTTP base sur cURL pour effectuer les requetes de verification.
 *
 * Supporte le suivi des redirections, la capture des en-tetes,
 * les metriques de performance detaillees et la recuperation des infos SSL.
 */
final class ClientHttp
{
    private string $userAgent = 'SiteMonitor/1.0 (+https://github.com/seo-platform/site-monitor)';
    private int $timeoutSecondes = 30;
    private int $maxRedirections = 10;
    private array $headersSupplementaires = [];
    private bool $suivreRedirections = true;

    public function definirUserAgent(string $ua): self
    {
        $this->userAgent = $ua;
        return $this;
    }

    public function definirTimeout(int $secondes): self
    {
        $this->timeoutSecondes = $secondes;
        return $this;
    }

    public function definirMaxRedirections(int $max): self
    {
        $this->maxRedirections = $max;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function definirHeaders(array $headers): self
    {
        $this->headersSupplementaires = $headers;
        return $this;
    }

    public function definirSuivreRedirections(bool $suivre): self
    {
        $this->suivreRedirections = $suivre;
        return $this;
    }

    /**
     * Execute une requete GET et retourne un ContexteVerification complet.
     */
    public function recuperer(string $url): ContexteVerification
    {
        // Mode plateforme : utiliser le WebClient centralise
        if (defined('PLATFORM_EMBEDDED') && class_exists(\Platform\Http\WebClient::class)) {
            return $this->recupererViaPlateforme($url);
        }

        return $this->recupererViaStandalone($url);
    }

    /**
     * Recuperation via le WebClient centralise de la plateforme.
     */
    private function recupererViaPlateforme(string $url): ContexteVerification
    {
        try {
            $webClient = new \Platform\Http\WebClient('site-monitor');
            $reponse = $webClient->fetch($url);

            $timings = $reponse->timings ?? [];

            // Recuperer les infos SSL via cURL natif (WebClient ne les expose pas)
            $infosSsl = $this->extraireInfosSslDepuisUrl($url);

            return new ContexteVerification(
                url: $url,
                codeHttp: $reponse->statusCode,
                enTetes: $reponse->headers,
                corpsReponse: $reponse->body,
                tempsTotalMs: $timings['total'] ?? $reponse->dureeMs,
                ttfbMs: $timings['ttfb'] ?? 0,
                tempsDnsMs: $timings['dns'] ?? 0,
                tempsConnexionMs: $timings['connect'] ?? 0,
                tempsHandshakeSslMs: $timings['ssl'] ?? 0,
                tailleOctets: $reponse->tailleOctets,
                urlFinale: $reponse->urlFinale !== $url ? $reponse->urlFinale : null,
                nombreRedirections: 0,
                infosSsl: $infosSsl,
            );
        } catch (\Throwable $e) {
            return new ContexteVerification(
                url: $url,
                codeHttp: 0,
                enTetes: [],
                corpsReponse: '',
                tempsTotalMs: 0,
                ttfbMs: 0,
                tempsDnsMs: 0,
                tempsConnexionMs: 0,
                tempsHandshakeSslMs: 0,
                tailleOctets: 0,
                urlFinale: null,
                nombreRedirections: 0,
                infosSsl: null,
                metriquesPerformance: ['erreur_plateforme' => $e->getMessage()],
            );
        }
    }

    /**
     * Recuperation standalone via cURL natif (mode autonome).
     */
    private function recupererViaStandalone(string $url): ContexteVerification
    {
        $ch = curl_init();
        $enTetesReponse = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $this->suivreRedirections,
            CURLOPT_MAXREDIRS => $this->maxRedirections,
            CURLOPT_TIMEOUT => $this->timeoutSecondes,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSecondes),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $ligne) use (&$enTetesReponse) {
                $longueur = strlen($ligne);
                $parties = explode(':', $ligne, 2);
                if (count($parties) === 2) {
                    $nom = trim($parties[0]);
                    $valeur = trim($parties[1]);
                    $enTetesReponse[$nom] = $valeur;
                }
                return $longueur;
            },
        ]);

        // Headers supplementaires
        if (!empty($this->headersSupplementaires)) {
            $headersList = [];
            foreach ($this->headersSupplementaires as $nom => $valeur) {
                $headersList[] = "{$nom}: {$valeur}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headersList);
        }

        $corpsReponse = curl_exec($ch);
        $erreur = curl_error($ch);
        $infoCurl = curl_getinfo($ch);

        // Recuperer les infos SSL
        $infosSsl = $this->extraireInfosSsl($ch, $url);

        curl_close($ch);

        if ($corpsReponse === false) {
            // Retourner un contexte d'erreur
            return new ContexteVerification(
                url: $url,
                codeHttp: 0,
                enTetes: [],
                corpsReponse: '',
                tempsTotalMs: (float) ($infoCurl['total_time'] ?? 0) * 1000,
                ttfbMs: 0,
                tempsDnsMs: 0,
                tempsConnexionMs: 0,
                tempsHandshakeSslMs: 0,
                tailleOctets: 0,
                urlFinale: null,
                nombreRedirections: 0,
                infosSsl: null,
                metriquesPerformance: ['erreur_curl' => $erreur],
            );
        }

        return ContexteVerification::depuisCurl(
            url: $url,
            corpsReponse: $corpsReponse,
            infoCurl: $infoCurl,
            enTetes: $enTetesReponse,
            infosSsl: $infosSsl,
        );
    }

    /**
     * Execute une requete HEAD (pour verifier l'accessibilite sans telecharger le corps).
     */
    public function testerAccessibilite(string $url): array
    {
        // Mode plateforme : utiliser le WebClient centralise
        if (defined('PLATFORM_EMBEDDED') && class_exists(\Platform\Http\WebClient::class)) {
            try {
                $webClient = new \Platform\Http\WebClient('site-monitor');
                $reponse = $webClient->head($url);

                return [
                    'accessible' => $reponse->statusCode > 0,
                    'code_http' => $reponse->statusCode,
                    'erreur' => null,
                ];
            } catch (\Throwable $e) {
                return [
                    'accessible' => false,
                    'code_http' => 0,
                    'erreur' => $e->getMessage(),
                ];
            }
        }

        // Fallback standalone
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $info = curl_getinfo($ch);
        $erreur = curl_error($ch);
        curl_close($ch);

        return [
            'accessible' => ($info['http_code'] ?? 0) > 0,
            'code_http' => (int) ($info['http_code'] ?? 0),
            'erreur' => $erreur ?: null,
        ];
    }

    /**
     * Extrait les informations SSL en ouvrant une connexion dediee.
     * Utilise en mode plateforme ou le handle cURL n'est pas disponible.
     *
     * @return array<string, mixed>|null
     */
    private function extraireInfosSslDepuisUrl(string $url): ?array
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
        ]);
        curl_exec($ch);
        $result = $this->extraireInfosSsl($ch, $url);
        curl_close($ch);

        return $result;
    }

    /**
     * Extrait les informations du certificat SSL.
     *
     * @return array<string, mixed>|null
     */
    private function extraireInfosSsl(\CurlHandle $ch, string $url): ?array
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        if (empty($certInfo)) {
            return null;
        }

        // Le premier certificat est celui du serveur
        $cert = $certInfo[0] ?? [];

        return [
            'sujet' => $cert['Subject'] ?? null,
            'emetteur' => $cert['Issuer'] ?? null,
            'debut_validite' => $cert['Start date'] ?? null,
            'fin_validite' => $cert['Expire date'] ?? null,
            'nombre_certificats' => count($certInfo),
        ];
    }
}
