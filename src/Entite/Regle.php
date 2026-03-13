<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\TypeRegle;

/**
 * Entite Regle : une verification unitaire appartenant a un modele.
 */
final readonly class Regle
{
    public function __construct(
        public ?int $id,
        public int $modeleId,
        public TypeRegle $typeRegle,
        public string $nom,
        public array $configuration,
        public NiveauSeverite $severite,
        public int $ordreTri,
        public bool $actif,
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
            modeleId: (int) ($ligne['modele_id'] ?? 0),
            typeRegle: TypeRegle::from((string) ($ligne['type_regle'] ?? 'code_http')),
            nom: (string) ($ligne['nom'] ?? ''),
            configuration: isset($ligne['configuration_json'])
                ? json_decode($ligne['configuration_json'], true) ?? []
                : [],
            severite: NiveauSeverite::from((string) ($ligne['severite'] ?? 'erreur')),
            ordreTri: (int) ($ligne['ordre_tri'] ?? 0),
            actif: (bool) ($ligne['actif'] ?? true),
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
            'modele_id' => $this->modeleId,
            'type_regle' => $this->typeRegle->value,
            'nom' => $this->nom,
            'configuration' => $this->configuration,
            'severite' => $this->severite->value,
            'ordre_tri' => $this->ordreTri,
            'actif' => $this->actif,
            'cree_le' => $this->creeLe,
            'modifie_le' => $this->modifieLe,
        ];
    }
}
