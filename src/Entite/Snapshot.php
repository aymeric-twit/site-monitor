<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite Snapshot : capture du contenu d'une URL pour detection de changements.
 */
final readonly class Snapshot
{
    public function __construct(
        public ?int $id,
        public int $urlId,
        public int $executionId,
        public string $typeContenu,
        public string $hashContenu,
        public ?string $contenuCompresse,
        public int $tailleOctets,
        public ?string $creeLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            urlId: (int) ($ligne['url_id'] ?? 0),
            executionId: (int) ($ligne['execution_id'] ?? 0),
            typeContenu: (string) ($ligne['type_contenu'] ?? 'body'),
            hashContenu: (string) ($ligne['hash_contenu'] ?? ''),
            contenuCompresse: $ligne['contenu_compresse'] ?? null,
            tailleOctets: (int) ($ligne['taille_octets'] ?? 0),
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
            'url_id' => $this->urlId,
            'execution_id' => $this->executionId,
            'type_contenu' => $this->typeContenu,
            'hash_contenu' => $this->hashContenu,
            'taille_octets' => $this->tailleOctets,
            'cree_le' => $this->creeLe,
        ];
    }
}
