<?php

declare(strict_types=1);

namespace SiteMonitor\Entite;

use SiteMonitor\Core\NiveauSeverite;

/**
 * Entite ResultatRegle : resultat individuel d'une regle sur une URL.
 */
final readonly class ResultatRegle
{
    public function __construct(
        public ?int $id,
        public int $executionId,
        public int $urlId,
        public int $regleId,
        public bool $succes,
        public NiveauSeverite $severite,
        public ?string $valeurAttendue,
        public ?string $valeurObtenue,
        public ?string $message,
        public ?int $dureeMs,
        public ?string $detailsJson,
        public ?string $verifieLe,
    ) {}

    /**
     * @param array<string, mixed> $ligne
     */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            id: isset($ligne['id']) ? (int) $ligne['id'] : null,
            executionId: (int) ($ligne['execution_id'] ?? 0),
            urlId: (int) ($ligne['url_id'] ?? 0),
            regleId: (int) ($ligne['regle_id'] ?? 0),
            succes: (bool) ($ligne['succes'] ?? false),
            severite: NiveauSeverite::from((string) ($ligne['severite'] ?? 'erreur')),
            valeurAttendue: $ligne['valeur_attendue'] ?? null,
            valeurObtenue: $ligne['valeur_obtenue'] ?? null,
            message: $ligne['message'] ?? null,
            dureeMs: isset($ligne['duree_ms']) ? (int) $ligne['duree_ms'] : null,
            detailsJson: $ligne['details_json'] ?? null,
            verifieLe: $ligne['verifie_le'] ?? null,
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
            'url_id' => $this->urlId,
            'regle_id' => $this->regleId,
            'succes' => $this->succes,
            'severite' => $this->severite->value,
            'valeur_attendue' => $this->valeurAttendue,
            'valeur_obtenue' => $this->valeurObtenue,
            'message' => $this->message,
            'duree_ms' => $this->dureeMs,
            'details_json' => $this->detailsJson,
            'verifie_le' => $this->verifieLe,
        ];
    }
}
