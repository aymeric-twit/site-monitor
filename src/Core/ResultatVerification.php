<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Valeur immutable representant le resultat d'une verification de regle.
 */
final readonly class ResultatVerification
{
    public function __construct(
        public bool $succes,
        public NiveauSeverite $severite,
        public string $message,
        public ?string $valeurAttendue = null,
        public ?string $valeurObtenue = null,
        public ?int $dureeMs = null,
        public array $details = [],
    ) {}

    /**
     * Cree un resultat de succes.
     */
    public static function succes(
        string $message,
        ?string $valeurObtenue = null,
        ?int $dureeMs = null,
        array $details = [],
    ): self {
        return new self(
            succes: true,
            severite: NiveauSeverite::Info,
            message: $message,
            valeurObtenue: $valeurObtenue,
            dureeMs: $dureeMs,
            details: $details,
        );
    }

    /**
     * Cree un resultat d'echec.
     */
    public static function echec(
        NiveauSeverite $severite,
        string $message,
        ?string $valeurAttendue = null,
        ?string $valeurObtenue = null,
        ?int $dureeMs = null,
        array $details = [],
    ): self {
        return new self(
            succes: false,
            severite: $severite,
            message: $message,
            valeurAttendue: $valeurAttendue,
            valeurObtenue: $valeurObtenue,
            dureeMs: $dureeMs,
            details: $details,
        );
    }

    /**
     * Conversion en tableau pour stockage JSON.
     *
     * @return array<string, mixed>
     */
    public function versTableau(): array
    {
        return [
            'succes' => $this->succes,
            'severite' => $this->severite->value,
            'message' => $this->message,
            'valeur_attendue' => $this->valeurAttendue,
            'valeur_obtenue' => $this->valeurObtenue,
            'duree_ms' => $this->dureeMs,
            'details' => $this->details,
        ];
    }
}
