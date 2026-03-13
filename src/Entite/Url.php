<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite URL : une URL a surveiller, associee a un groupe et un ou plusieurs modeles.
 */
final readonly class Url
{
    public function __construct(
        public ?int $id,
        public int $groupeId,
        public string $url,
        public ?string $libelle,
        public bool $actif,
        public ?string $derniereVerification,
        public ?string $dernierStatut,
        public ?string $notes,
        public ?string $creeLe,
        public ?string $modifieLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            groupeId: (int) ($ligne['groupe_id'] ?? 0),
            url: (string) ($ligne['url'] ?? ''),
            libelle: $ligne['libelle'] ?? null,
            actif: (bool) ($ligne['actif'] ?? true),
            derniereVerification: $ligne['derniere_verification'] ?? null,
            dernierStatut: $ligne['dernier_statut'] ?? null,
            notes: $ligne['notes'] ?? null,
            creeLe: $ligne['cree_le'] ?? null,
            modifieLe: $ligne['modifie_le'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function versTableau(): array
    {
        return [
            'id' => $this->id,
            'groupe_id' => $this->groupeId,
            'url' => $this->url,
            'libelle' => $this->libelle,
            'actif' => $this->actif,
            'derniere_verification' => $this->derniereVerification,
            'dernier_statut' => $this->dernierStatut,
            'notes' => $this->notes,
            'cree_le' => $this->creeLe,
            'modifie_le' => $this->modifieLe,
        ];
    }
}
