<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

use SiteMonitor\Core\NiveauSeverite;

/**
 * Entite Alerte : notification generee apres une execution contenant des echecs.
 */
final readonly class Alerte
{
    public function __construct(
        public ?int $id,
        public int $executionId,
        public int $clientId,
        public NiveauSeverite $severite,
        public string $sujet,
        public string $corpsTexte,
        public string $destinataires,
        public bool $envoyee,
        public ?string $envoyeeLe,
        public ?string $creeLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            executionId: (int) ($ligne['execution_id'] ?? 0),
            clientId: (int) ($ligne['client_id'] ?? 0),
            severite: NiveauSeverite::from((string) ($ligne['severite'] ?? 'erreur')),
            sujet: (string) ($ligne['sujet'] ?? ''),
            corpsTexte: (string) ($ligne['corps_texte'] ?? ''),
            destinataires: (string) ($ligne['destinataires'] ?? ''),
            envoyee: (bool) ($ligne['envoyee'] ?? false),
            envoyeeLe: $ligne['envoyee_le'] ?? null,
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
            'execution_id' => $this->executionId,
            'client_id' => $this->clientId,
            'severite' => $this->severite->value,
            'sujet' => $this->sujet,
            'corps_texte' => $this->corpsTexte,
            'destinataires' => $this->destinataires,
            'envoyee' => $this->envoyee,
            'envoyee_le' => $this->envoyeeLe,
            'cree_le' => $this->creeLe,
        ];
    }
}
