<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Abstraction dual-mode pour l'envoi d'emails.
 *
 * Mode plateforme (PLATFORM_EMBEDDED) : delegue au Mailer de la plateforme.
 * Mode standalone : utilise mail() avec la config SMTP de config.json.
 */
final class Expediteur
{
    private static ?self $instance = null;

    private function __construct(
        private readonly bool $modePlateforme,
        private readonly ?array $configSmtp,
    ) {}

    /**
     * Retourne l'instance singleton de l'expediteur.
     */
    public static function obtenir(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if ((defined('PLATFORM_EMBEDDED') || defined('PLATFORM_IFRAME'))
            && class_exists('\\Platform\\Service\\Mailer')) {
            self::$instance = new self(modePlateforme: true, configSmtp: null);
        } else {
            self::$instance = new self(
                modePlateforme: false,
                configSmtp: self::chargerConfigStandalone(),
            );
        }

        return self::$instance;
    }

    /**
     * Envoie un email.
     *
     * @return bool true si l'envoi a reussi
     */
    public function envoyer(string $destinataire, string $sujet, string $corpsHtml): bool
    {
        if ($destinataire === '') {
            return false;
        }

        if ($this->modePlateforme) {
            return $this->envoyerViaPlateforme($destinataire, $sujet, $corpsHtml);
        }

        return $this->envoyerViaMail($destinataire, $sujet, $corpsHtml);
    }

    /**
     * Reinitialise le singleton (pour les tests).
     */
    public static function reinitialiser(): void
    {
        self::$instance = null;
    }

    /**
     * Envoie via le Mailer de la plateforme.
     */
    private function envoyerViaPlateforme(string $destinataire, string $sujet, string $corpsHtml): bool
    {
        try {
            /** @var bool $resultat */
            $resultat = \Platform\Service\Mailer::instance()->envoyer(
                $destinataire,
                $sujet,
                $corpsHtml,
                'site_monitor',
            );
            return $resultat;
        } catch (\Throwable $e) {
            error_log('[site-monitor] Erreur envoi email plateforme : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envoie via la fonction mail() de PHP (mode standalone).
     */
    private function envoyerViaMail(string $destinataire, string $sujet, string $corpsHtml): bool
    {
        $expediteur = $this->configSmtp['expediteur'] ?? $this->configSmtp['utilisateur'] ?? 'noreply@localhost';

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: Site Monitor <' . $expediteur . '>',
            'X-Mailer: SiteMonitor/1.0',
        ]);

        try {
            return mail($destinataire, $sujet, $corpsHtml, $headers);
        } catch (\Throwable $e) {
            error_log('[site-monitor] Erreur envoi email standalone : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Charge la configuration SMTP depuis config.json.
     *
     * @return array<string, mixed>|null
     */
    private static function chargerConfigStandalone(): ?array
    {
        $cheminConfig = dirname(__DIR__, 2) . '/config.json';

        if (!file_exists($cheminConfig)) {
            return null;
        }

        $contenu = file_get_contents($cheminConfig);
        $config = json_decode($contenu, true);

        return $config['smtp'] ?? null;
    }
}
