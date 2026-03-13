<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite Groupe d'URLs : regroupe des URLs a verifier avec des parametres communs.
 */
final readonly class GroupeUrls
{
    public function __construct(
        public ?int $id,
        public int $clientId,
        public string $nom,
        public ?string $description,
        public int $ordreTri,
        public bool $actif,
        public ?array $planification,
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
            clientId: (int) ($ligne['client_id'] ?? 0),
            nom: (string) ($ligne['nom'] ?? ''),
            description: $ligne['description'] ?? null,
            ordreTri: (int) ($ligne['ordre_tri'] ?? 0),
            actif: (bool) ($ligne['actif'] ?? true),
            planification: isset($ligne['planification_json'])
                ? json_decode($ligne['planification_json'], true)
                : null,
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
            'client_id' => $this->clientId,
            'nom' => $this->nom,
            'description' => $this->description,
            'ordre_tri' => $this->ordreTri,
            'actif' => $this->actif,
            'planification' => $this->planification,
            'cree_le' => $this->creeLe,
            'modifie_le' => $this->modifieLe,
        ];
    }
}
