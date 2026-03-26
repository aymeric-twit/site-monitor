<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

/**
 * Entite Planification : execution automatique periodique.
 */
final readonly class Planification
{
    public function __construct(
        public ?int $id,
        public ?int $clientId,
        public ?int $groupeId,
        public int $frequenceMinutes,
        public ?string $heureDebut,
        public ?string $heureFin,
        public ?string $joursSemaine,
        public ?string $userAgent,
        public ?string $headersJson,
        public int $timeoutSecondes,
        public int $delaiEntreRequetesMs,
        public bool $actif,
        public ?string $derniereExecution,
        public ?string $prochaineExecution,
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
            groupeId: isset($ligne['groupe_id']) ? (int) $ligne['groupe_id'] : null,
            frequenceMinutes: (int) ($ligne['frequence_minutes'] ?? 1440),
            heureDebut: $ligne['heure_debut'] ?? null,
            heureFin: $ligne['heure_fin'] ?? null,
            joursSemaine: $ligne['jours_semaine'] ?? null,
            userAgent: $ligne['user_agent'] ?? null,
            headersJson: $ligne['headers_json'] ?? null,
            timeoutSecondes: (int) ($ligne['timeout_secondes'] ?? 30),
            delaiEntreRequetesMs: (int) ($ligne['delai_entre_requetes_ms'] ?? 1000),
            actif: (bool) ($ligne['actif'] ?? true),
            derniereExecution: $ligne['derniere_execution'] ?? null,
            prochaineExecution: $ligne['prochaine_execution'] ?? null,
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
            'groupe_id' => $this->groupeId,
            'frequence_minutes' => $this->frequenceMinutes,
            'heure_debut' => $this->heureDebut,
            'heure_fin' => $this->heureFin,
            'jours_semaine' => $this->joursSemaine,
            'timeout_secondes' => $this->timeoutSecondes,
            'delai_entre_requetes_ms' => $this->delaiEntreRequetesMs,
            'actif' => $this->actif,
            'derniere_execution' => $this->derniereExecution,
            'prochaine_execution' => $this->prochaineExecution,
            'cree_le' => $this->creeLe,
        ];
    }

    /**
     * Libelle humain de la frequence.
     */
    public function libelleFrequence(): string
    {
        return match (true) {
            $this->frequenceMinutes <= 60 => 'Toutes les heures',
            $this->frequenceMinutes <= 360 => 'Toutes les ' . intdiv($this->frequenceMinutes, 60) . 'h',
            $this->frequenceMinutes <= 720 => 'Toutes les 12h',
            $this->frequenceMinutes <= 1440 => 'Quotidien',
            default => 'Hebdomadaire',
        };
    }
}
