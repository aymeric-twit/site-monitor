<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite Client : proprietaire de groupes d'URLs a surveiller.
 */
final readonly class Client
{
    public function __construct(
        public ?int $id,
        public string $nom,
        public string $slug,
        public string $domaine,
        public ?string $emailContact,
        public bool $actif,
        public ?array $configuration,
        public ?int $utilisateurId,
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
            nom: (string) ($ligne['nom'] ?? ''),
            slug: (string) ($ligne['slug'] ?? ''),
            domaine: (string) ($ligne['domaine'] ?? ''),
            emailContact: $ligne['email_contact'] ?? null,
            actif: (bool) ($ligne['actif'] ?? true),
            configuration: isset($ligne['configuration_json'])
                ? json_decode($ligne['configuration_json'], true)
                : null,
            utilisateurId: isset($ligne['utilisateur_id']) ? (int) $ligne['utilisateur_id'] : null,
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
            'nom' => $this->nom,
            'slug' => $this->slug,
            'domaine' => $this->domaine,
            'email_contact' => $this->emailContact,
            'actif' => $this->actif,
            'configuration' => $this->configuration,
            'utilisateur_id' => $this->utilisateurId,
            'cree_le' => $this->creeLe,
            'modifie_le' => $this->modifieLe,
        ];
    }
}
