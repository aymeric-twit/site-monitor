<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Niveaux de severite pour les regles et alertes.
 */
enum NiveauSeverite: string
{
    case Info = 'info';
    case Avertissement = 'avertissement';
    case Erreur = 'erreur';
    case Critique = 'critique';

    /**
     * Retourne le libelle affichable.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Avertissement => 'Avertissement',
            self::Erreur => 'Erreur',
            self::Critique => 'Critique',
        };
    }

    /**
     * Retourne la classe CSS Bootstrap associee.
     */
    public function classeCss(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Avertissement => 'warning',
            self::Erreur => 'danger',
            self::Critique => 'danger',
        };
    }

    /**
     * Priorite numerique (plus c'est eleve, plus c'est grave).
     */
    public function priorite(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Avertissement => 1,
            self::Erreur => 2,
            self::Critique => 3,
        };
    }
}
