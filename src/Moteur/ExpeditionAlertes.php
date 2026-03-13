<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Core\Expediteur;
use SiteMonitor\Entite\Alerte;
use SiteMonitor\Stockage\DepotAlerte;

/**
 * Service d'expedition des alertes par email.
 *
 * Traite la file d'alertes non envoyees et les expedie
 * via l'Expediteur (plateforme ou standalone).
 */
final class ExpeditionAlertes
{
    public function __construct(
        private readonly DepotAlerte $depotAlerte,
    ) {}

    /**
     * Envoie toutes les alertes non envoyees.
     *
     * @return int Nombre d'alertes envoyees avec succes
     */
    public function expedierEnAttente(): int
    {
        $alertes = $this->depotAlerte->trouverNonEnvoyees();
        $envoyees = 0;

        foreach ($alertes as $alerte) {
            if ($alerte->destinataires === '') {
                continue;
            }

            $corpsHtml = $this->construireHtml($alerte);

            $succes = Expediteur::obtenir()->envoyer(
                $alerte->destinataires,
                $alerte->sujet,
                $corpsHtml,
            );

            if ($succes) {
                $this->depotAlerte->marquerEnvoyee($alerte->id);
                $envoyees++;
            }
        }

        return $envoyees;
    }

    /**
     * Construit le corps HTML de l'email a partir de l'alerte.
     */
    private function construireHtml(Alerte $alerte): string
    {
        $severiteLabel = strtoupper($alerte->severite->value);
        $severiteCouleur = match ($alerte->severite->value) {
            'critique' => '#dc3545',
            'erreur' => '#fd7e14',
            'avertissement' => '#fbb03b',
            default => '#66b2b2',
        };

        $corpsFormate = nl2br(htmlspecialchars($alerte->corpsTexte, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#f2f2f2;font-family:Poppins,Arial,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f2;padding:20px 0;">
                <tr><td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;">
                        <!-- Header -->
                        <tr>
                            <td style="background:#004c4c;padding:16px 24px;border-bottom:3px solid #fbb03b;">
                                <span style="color:#fff;font-size:18px;font-weight:700;">Site Monitor</span>
                            </td>
                        </tr>
                        <!-- Badge severite -->
                        <tr>
                            <td style="padding:20px 24px 0;">
                                <span style="display:inline-block;background:{$severiteCouleur};color:#fff;font-size:11px;font-weight:700;letter-spacing:0.05em;padding:4px 10px;border-radius:4px;text-transform:uppercase;">{$severiteLabel}</span>
                            </td>
                        </tr>
                        <!-- Sujet -->
                        <tr>
                            <td style="padding:12px 24px 0;">
                                <h2 style="margin:0;font-size:16px;color:#333;">{$alerte->sujet}</h2>
                            </td>
                        </tr>
                        <!-- Corps -->
                        <tr>
                            <td style="padding:16px 24px 24px;">
                                <div style="font-size:13px;line-height:1.6;color:#333;background:#f8f9fa;padding:16px;border-radius:6px;border:1px solid #e2e8f0;">
                                    {$corpsFormate}
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style="padding:12px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#999;">
                                Email genere automatiquement par Site Monitor.
                            </td>
                        </tr>
                    </table>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
