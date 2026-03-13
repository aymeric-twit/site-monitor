<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite ResultatIndexation : resultat de verification d'indexabilite pour une URL.
 */
final readonly class ResultatIndexation
{
    public function __construct(
        public ?int $id,
        public int $auditId,
        public string $url,
        public ?int $codeHttp,
        public ?string $urlFinale,
        public ?string $metaRobots,
        public ?string $xRobotsTag,
        public ?string $canonical,
        public ?bool $canonicalAutoReference,
        public ?bool $robotsTxtAutorise,
        public ?string $robotsTxtRegle,
        public ?bool $presentSitemap,
        public string $statutIndexation,
        public ?array $contradictions,
        public ?string $severiteMax,
        public ?string $verifieLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            auditId: (int) ($ligne['audit_id'] ?? 0),
            url: (string) ($ligne['url'] ?? ''),
            codeHttp: isset($ligne['code_http']) ? (int) $ligne['code_http'] : null,
            urlFinale: $ligne['url_finale'] ?? null,
            metaRobots: $ligne['meta_robots'] ?? null,
            xRobotsTag: $ligne['x_robots_tag'] ?? null,
            canonical: $ligne['canonical'] ?? null,
            canonicalAutoReference: isset($ligne['canonical_auto_reference'])
                ? (bool) $ligne['canonical_auto_reference']
                : null,
            robotsTxtAutorise: isset($ligne['robots_txt_autorise'])
                ? (bool) $ligne['robots_txt_autorise']
                : null,
            robotsTxtRegle: $ligne['robots_txt_regle'] ?? null,
            presentSitemap: isset($ligne['present_sitemap'])
                ? (bool) $ligne['present_sitemap']
                : null,
            statutIndexation: (string) ($ligne['statut_indexation'] ?? 'inconnu'),
            contradictions: isset($ligne['contradictions_json'])
                ? json_decode($ligne['contradictions_json'], true)
                : null,
            severiteMax: $ligne['severite_max'] ?? null,
            verifieLe: $ligne['verifie_le'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function versTableau(): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->auditId,
            'url' => $this->url,
            'code_http' => $this->codeHttp,
            'url_finale' => $this->urlFinale,
            'meta_robots' => $this->metaRobots,
            'x_robots_tag' => $this->xRobotsTag,
            'canonical' => $this->canonical,
            'canonical_auto_reference' => $this->canonicalAutoReference,
            'robots_txt_autorise' => $this->robotsTxtAutorise,
            'robots_txt_regle' => $this->robotsTxtRegle,
            'present_sitemap' => $this->presentSitemap,
            'statut_indexation' => $this->statutIndexation,
            'contradictions' => $this->contradictions,
            'severite_max' => $this->severiteMax,
            'verifie_le' => $this->verifieLe,
        ];
    }
}
