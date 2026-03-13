<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

/**
 * Contexte de verification : contient toutes les donnees de la reponse HTTP
 * necessaires aux verificateurs.
 *
 * Construit une seule fois par URL verifiee, puis passe a chaque verificateur.
 */
final class ContexteVerification
{
    private ?\DOMDocument $dom = null;
    private ?\DOMXPath $xpath = null;

    public function __construct(
        public readonly string $url,
        public readonly int $codeHttp,
        public readonly array $enTetes,
        public readonly string $corpsReponse,
        public readonly float $tempsTotalMs,
        public readonly float $ttfbMs,
        public readonly float $tempsDnsMs,
        public readonly float $tempsConnexionMs,
        public readonly float $tempsHandshakeSslMs,
        public readonly int $tailleOctets,
        public readonly ?string $urlFinale,
        public readonly int $nombreRedirections,
        public readonly ?array $infosSsl,
        public readonly array $metriquesPerformance = [],
    ) {}

    /**
     * Construit un contexte depuis une reponse cURL.
     *
     * @param array<string, mixed> $infoCurl Resultat de curl_getinfo()
     * @param array<string, string> $enTetes En-tetes de reponse
     */
    public static function depuisCurl(
        string $url,
        string $corpsReponse,
        array $infoCurl,
        array $enTetes,
        ?array $infosSsl = null,
    ): self {
        return new self(
            url: $url,
            codeHttp: (int) ($infoCurl['http_code'] ?? 0),
            enTetes: $enTetes,
            corpsReponse: $corpsReponse,
            tempsTotalMs: (float) ($infoCurl['total_time'] ?? 0) * 1000,
            ttfbMs: (float) ($infoCurl['starttransfer_time'] ?? 0) * 1000,
            tempsDnsMs: (float) ($infoCurl['namelookup_time'] ?? 0) * 1000,
            tempsConnexionMs: (float) ($infoCurl['connect_time'] ?? 0) * 1000,
            tempsHandshakeSslMs: (float) ($infoCurl['appconnect_time'] ?? 0) * 1000,
            tailleOctets: (int) ($infoCurl['size_download'] ?? strlen($corpsReponse)),
            urlFinale: $infoCurl['url'] ?? null,
            nombreRedirections: (int) ($infoCurl['redirect_count'] ?? 0),
            infosSsl: $infosSsl,
        );
    }

    /**
     * Retourne le DOM parse de la reponse (cache).
     */
    public function dom(): ?\DOMDocument
    {
        if ($this->dom !== null) {
            return $this->dom;
        }

        if (empty($this->corpsReponse)) {
            return null;
        }

        $this->dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $this->dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $this->corpsReponse,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();

        return $this->dom;
    }

    /**
     * Retourne un objet XPath pour interroger le DOM.
     */
    public function xpath(): ?\DOMXPath
    {
        if ($this->xpath !== null) {
            return $this->xpath;
        }

        $dom = $this->dom();
        if ($dom === null) {
            return null;
        }

        $this->xpath = new \DOMXPath($dom);
        return $this->xpath;
    }

    /**
     * Retourne la valeur d'un en-tete (insensible a la casse).
     */
    public function enTete(string $nom): ?string
    {
        $nomLower = strtolower($nom);
        foreach ($this->enTetes as $cle => $valeur) {
            if (strtolower($cle) === $nomLower) {
                return $valeur;
            }
        }
        return null;
    }

    /**
     * Verifie si un en-tete est present.
     */
    public function aEnTete(string $nom): bool
    {
        return $this->enTete($nom) !== null;
    }
}
