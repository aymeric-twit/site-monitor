<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur des metriques de performance.
 *
 * Compare les temps de reponse (TTFB, DNS, total, etc.) et la taille
 * contre des seuils configurables.
 */
final class VerificateurPerformance implements InterfaceVerificateur
{
    /** @var array<string, string> Correspondance metrique -> libelle affichable */
    private const array LIBELLES_METRIQUES = [
        'temps_total' => 'Temps total',
        'ttfb' => 'TTFB (Time to First Byte)',
        'dns' => 'Resolution DNS',
        'connexion' => 'Temps de connexion',
        'ssl' => 'Handshake SSL/TLS',
        'taille' => 'Taille de la reponse',
    ];

    #[\Override]
    public function typeGere(): string
    {
        return 'performance';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;

        $metrique = $config['metrique'] ?? '';
        $seuilMax = isset($config['seuil_max']) ? (float) $config['seuil_max'] : null;
        $unite = $config['unite'] ?? 'ms';

        if ($metrique === '' || $seuilMax === null) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Configuration invalide : metrique ou seuil_max manquant",
            );
        }

        $valeurMesuree = $this->extraireMetrique($metrique, $contexte);

        if ($valeurMesuree === null) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Metrique inconnue : '{$metrique}'",
            );
        }

        $libelle = self::LIBELLES_METRIQUES[$metrique] ?? $metrique;
        $uniteAffichage = $unite === 'octets' ? 'octets' : 'ms';
        $valeurFormatee = $this->formaterValeur($valeurMesuree, $uniteAffichage);
        $seuilFormate = $this->formaterValeur($seuilMax, $uniteAffichage);

        if ($valeurMesuree > $seuilMax) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$libelle} trop eleve : {$valeurFormatee} (seuil : {$seuilFormate})",
                valeurAttendue: "<= {$seuilFormate}",
                valeurObtenue: $valeurFormatee,
                dureeMs: $unite === 'ms' ? (int) round($valeurMesuree) : null,
                details: [
                    'metrique' => $metrique,
                    'valeur' => $valeurMesuree,
                    'seuil_max' => $seuilMax,
                    'unite' => $uniteAffichage,
                    'depassement_pourcent' => round(
                        (($valeurMesuree - $seuilMax) / $seuilMax) * 100,
                        1,
                    ),
                ],
            );
        }

        return ResultatVerification::succes(
            message: "{$libelle} conforme : {$valeurFormatee} (seuil : {$seuilFormate})",
            valeurObtenue: $valeurFormatee,
            dureeMs: $unite === 'ms' ? (int) round($valeurMesuree) : null,
            details: [
                'metrique' => $metrique,
                'valeur' => $valeurMesuree,
                'seuil_max' => $seuilMax,
                'unite' => $uniteAffichage,
                'marge_pourcent' => round(
                    (($seuilMax - $valeurMesuree) / $seuilMax) * 100,
                    1,
                ),
            ],
        );
    }

    /**
     * Extrait la valeur d'une metrique depuis le contexte de verification.
     */
    private function extraireMetrique(string $metrique, ContexteVerification $contexte): ?float
    {
        return match ($metrique) {
            'temps_total' => $contexte->tempsTotalMs,
            'ttfb' => $contexte->ttfbMs,
            'dns' => $contexte->tempsDnsMs,
            'connexion' => $contexte->tempsConnexionMs,
            'ssl' => $contexte->tempsHandshakeSslMs,
            'taille' => (float) $contexte->tailleOctets,
            default => null,
        };
    }

    /**
     * Formate une valeur avec son unite pour l'affichage.
     */
    private function formaterValeur(float $valeur, string $unite): string
    {
        if ($unite === 'octets') {
            return $this->formaterTaille($valeur);
        }

        // Affichage en millisecondes avec une decimale
        return round($valeur, 1) . ' ms';
    }

    /**
     * Formate une taille en octets de maniere lisible (Ko, Mo).
     */
    private function formaterTaille(float $octets): string
    {
        if ($octets >= 1_048_576) {
            return round($octets / 1_048_576, 2) . ' Mo';
        }

        if ($octets >= 1_024) {
            return round($octets / 1_024, 1) . ' Ko';
        }

        return (int) $octets . ' octets';
    }
}
