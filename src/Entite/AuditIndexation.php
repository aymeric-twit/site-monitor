<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite AuditIndexation : audit de verification d'indexabilite des URLs d'un domaine.
 */
final readonly class AuditIndexation
{
    public function __construct(
        public ?int $id,
        public ?int $clientId,
        public ?int $utilisateurId,
        public string $domaine,
        public int $urlsTotal,
        public int $urlsTraitees,
        public int $urlsIndexables,
        public int $urlsNonIndexables,
        public int $urlsContradictoires,
        public string $statut,
        public ?string $creeLe,
        public ?string $termineLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            clientId: isset($ligne['client_id']) ? (int) $ligne['client_id'] : null,
            utilisateurId: isset($ligne['utilisateur_id']) ? (int) $ligne['utilisateur_id'] : null,
            domaine: (string) ($ligne['domaine'] ?? ''),
            urlsTotal: (int) ($ligne['urls_total'] ?? 0),
            urlsTraitees: (int) ($ligne['urls_traitees'] ?? 0),
            urlsIndexables: (int) ($ligne['urls_indexables'] ?? 0),
            urlsNonIndexables: (int) ($ligne['urls_non_indexables'] ?? 0),
            urlsContradictoires: (int) ($ligne['urls_contradictoires'] ?? 0),
            statut: (string) ($ligne['statut'] ?? 'en_attente'),
            creeLe: $ligne['cree_le'] ?? null,
            termineLe: $ligne['termine_le'] ?? null,
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
            'utilisateur_id' => $this->utilisateurId,
            'domaine' => $this->domaine,
            'urls_total' => $this->urlsTotal,
            'urls_traitees' => $this->urlsTraitees,
            'urls_indexables' => $this->urlsIndexables,
            'urls_non_indexables' => $this->urlsNonIndexables,
            'urls_contradictoires' => $this->urlsContradictoires,
            'statut' => $this->statut,
            'cree_le' => $this->creeLe,
            'termine_le' => $this->termineLe,
        ];
    }
}
