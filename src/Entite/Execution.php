<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

use SiteMonitor\Core\StatutExecution;

/**
 * Entite Execution : une session de verification (manuelle ou planifiee).
 */
final readonly class Execution
{
    public function __construct(
        public ?int $id,
        public ?int $clientId,
        public ?int $groupeId,
        public string $typeDeclencheur,
        public StatutExecution $statut,
        public int $urlsTotal,
        public int $urlsTraitees,
        public int $reglesTotal,
        public int $succes,
        public int $echecs,
        public int $avertissements,
        public ?int $dureeMs,
        public ?string $demarreeLe,
        public ?string $termineeLe,
        public ?string $creeLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            clientId: isset($ligne['client_id']) ? (int) $ligne['client_id'] : null,
            groupeId: isset($ligne['groupe_id']) ? (int) $ligne['groupe_id'] : null,
            typeDeclencheur: (string) ($ligne['type_declencheur'] ?? 'manuel'),
            statut: StatutExecution::from((string) ($ligne['statut'] ?? 'en_attente')),
            urlsTotal: (int) ($ligne['urls_total'] ?? 0),
            urlsTraitees: (int) ($ligne['urls_traitees'] ?? 0),
            reglesTotal: (int) ($ligne['regles_total'] ?? 0),
            succes: (int) ($ligne['succes'] ?? 0),
            echecs: (int) ($ligne['echecs'] ?? 0),
            avertissements: (int) ($ligne['avertissements'] ?? 0),
            dureeMs: isset($ligne['duree_ms']) ? (int) $ligne['duree_ms'] : null,
            demarreeLe: $ligne['demarree_le'] ?? null,
            termineeLe: $ligne['terminee_le'] ?? null,
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
            'client_id' => $this->clientId,
            'groupe_id' => $this->groupeId,
            'type_declencheur' => $this->typeDeclencheur,
            'statut' => $this->statut->value,
            'urls_total' => $this->urlsTotal,
            'urls_traitees' => $this->urlsTraitees,
            'regles_total' => $this->reglesTotal,
            'succes' => $this->succes,
            'echecs' => $this->echecs,
            'avertissements' => $this->avertissements,
            'duree_ms' => $this->dureeMs,
            'demarree_le' => $this->demarreeLe,
            'terminee_le' => $this->termineeLe,
            'cree_le' => $this->creeLe,
        ];
    }

    public function tauxReussite(): float
    {
        $total = $this->succes + $this->echecs + $this->avertissements;
        return $total > 0 ? round(($this->succes / $total) * 100, 1) : 0.0;
    }
}
