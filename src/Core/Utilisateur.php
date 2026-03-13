<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Helper statique pour extraire l'identifiant de l'utilisateur plateforme courant.
 *
 * En mode plateforme (PLATFORM_EMBEDDED) : utilise Auth::id() ou $_SESSION.
 * En mode standalone : retourne null (pas de multi-utilisateur).
 */
final class Utilisateur
{
    private function __construct() {}

    public static function idCourant(): ?int
    {
        if (!defined('PLATFORM_EMBEDDED') && !defined('PLATFORM_IFRAME')) {
            return null;
        }

        // Essayer la classe Auth de la plateforme
        if (class_exists('\\Platform\\Auth\\Auth')) {
            /** @var int|null $id */
            $id = \Platform\Auth\Auth::id();
            if ($id !== null) {
                return $id;
            }
        }

        // Fallback : session PHP
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}
