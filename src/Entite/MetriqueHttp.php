<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

use SiteMonitor\Regle\ContexteVerification;

/**
 * Entite MetriqueHttp : metriques de performance d'une requete HTTP.
 */
final readonly class MetriqueHttp
{
    public function __construct(
        public ?int $id,
        public int $executionId,
        public int $urlId,
        public int $codeHttp,
        public float $tempsTotalMs,
        public float $ttfbMs,
        public float $tempsDnsMs,
        public float $tempsConnexionMs,
        public float $tempsSslMs,
        public int $tailleOctets,
        public ?string $urlFinale,
        public int $nombreRedirections,
        public ?string $enTetesJson,
        public ?string $infosSslJson,
        public ?string $creeLe,
    ) {}

    /**
     * Cree une instance depuis un ContexteVerification.
     */
    public static function depuisContexte(
        ContexteVerification $contexte,
        int $executionId,
        int $urlId,
    ): self {
        return new self(
            id: null,
            executionId: $executionId,
            urlId: $urlId,
            codeHttp: $contexte->codeHttp,
            tempsTotalMs: $contexte->tempsTotalMs,
            ttfbMs: $contexte->ttfbMs,
            tempsDnsMs: $contexte->tempsDnsMs,
            tempsConnexionMs: $contexte->tempsConnexionMs,
            tempsSslMs: $contexte->tempsHandshakeSslMs,
            tailleOctets: $contexte->tailleOctets,
            urlFinale: $contexte->urlFinale,
            nombreRedirections: $contexte->nombreRedirections,
            enTetesJson: !empty($contexte->enTetes)
                ? json_encode($contexte->enTetes, JSON_UNESCAPED_UNICODE)
                : null,
            infosSslJson: $contexte->infosSsl !== null
                ? json_encode($contexte->infosSsl, JSON_UNESCAPED_UNICODE)
                : null,
            creeLe: null,
        );
    }

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            executionId: (int) ($ligne['execution_id'] ?? 0),
            urlId: (int) ($ligne['url_id'] ?? 0),
            codeHttp: (int) ($ligne['code_http'] ?? 0),
            tempsTotalMs: (float) ($ligne['temps_total_ms'] ?? 0),
            ttfbMs: (float) ($ligne['ttfb_ms'] ?? 0),
            tempsDnsMs: (float) ($ligne['temps_dns_ms'] ?? 0),
            tempsConnexionMs: (float) ($ligne['temps_connexion_ms'] ?? 0),
            tempsSslMs: (float) ($ligne['temps_ssl_ms'] ?? 0),
            tailleOctets: (int) ($ligne['taille_octets'] ?? 0),
            urlFinale: $ligne['url_finale'] ?? null,
            nombreRedirections: (int) ($ligne['nombre_redirections'] ?? 0),
            enTetesJson: $ligne['en_tetes_json'] ?? null,
            infosSslJson: $ligne['infos_ssl_json'] ?? null,
            creeLe: $ligne['cree_le'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function versTableau(): array
    {
        return [
            'id' => $this->id,
            'execution_id' => $this->executionId,
            'url_id' => $this->urlId,
            'code_http' => $this->codeHttp,
            'temps_total_ms' => $this->tempsTotalMs,
            'ttfb_ms' => $this->ttfbMs,
            'temps_dns_ms' => $this->tempsDnsMs,
            'temps_connexion_ms' => $this->tempsConnexionMs,
            'temps_ssl_ms' => $this->tempsSslMs,
            'taille_octets' => $this->tailleOctets,
            'url_finale' => $this->urlFinale,
            'nombre_redirections' => $this->nombreRedirections,
            'en_tetes_json' => $this->enTetesJson,
            'infos_ssl_json' => $this->infosSslJson,
            'cree_le' => $this->creeLe,
        ];
    }
}
