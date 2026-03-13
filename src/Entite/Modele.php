<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite Modele (template de verification) : regroupe des regles reutilisables.
 */
final readonly class Modele
{
    public function __construct(
        public ?int $id,
        public ?int $clientId,
        public string $nom,
        public ?string $description,
        public bool $estGlobal,
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
            clientId: isset($ligne['client_id']) ? (int) $ligne['client_id'] : null,
            nom: (string) ($ligne['nom'] ?? ''),
            description: $ligne['description'] ?? null,
            estGlobal: (bool) ($ligne['est_global'] ?? false),
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
            'est_global' => $this->estGlobal,
            'cree_le' => $this->creeLe,
            'modifie_le' => $this->modifieLe,
        ];
    }
}
