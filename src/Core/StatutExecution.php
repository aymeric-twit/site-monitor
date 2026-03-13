<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Statuts possibles d'une execution de verification.
 */
enum StatutExecution: string
{
    case EnAttente = 'en_attente';
    case EnCours = 'en_cours';
    case Termine = 'termine';
    case Erreur = 'erreur';
    case Annule = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::EnCours => 'En cours',
            self::Termine => 'Termine',
            self::Erreur => 'Erreur',
            self::Annule => 'Annule',
        };
    }

    public function classeCss(): string
    {
        return match ($this) {
            self::EnAttente => 'secondary',
            self::EnCours => 'primary',
            self::Termine => 'success',
            self::Erreur => 'danger',
            self::Annule => 'warning',
        };
    }

    public function estTerminal(): bool
    {
        return in_array($this, [self::Termine, self::Erreur, self::Annule], true);
    }
}
