<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur du code de reponse HTTP.
 *
 * Verifie que le code HTTP correspond a la valeur attendue ou a une plage (2xx, 3xx, etc.),
 * controle le nombre de redirections et l'URL finale apres redirection.
 */
final class VerificateurCodeHttp implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'code_http';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $codeObtenu = $contexte->codeHttp;
        $severite = $regle->severite;

        // Verification du code HTTP exact
        if (isset($config['code_attendu'])) {
            $codeAttendu = (int) $config['code_attendu'];

            if ($codeObtenu !== $codeAttendu) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Code HTTP inattendu : {$codeObtenu} au lieu de {$codeAttendu}",
                    valeurAttendue: (string) $codeAttendu,
                    valeurObtenue: (string) $codeObtenu,
                );
            }
        }

        // Verification par plage (2xx, 3xx, 4xx, 5xx)
        if (isset($config['plage_acceptee'])) {
            $plage = strtolower(trim($config['plage_acceptee']));

            if (!$this->codeDansPlage($codeObtenu, $plage)) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Code HTTP {$codeObtenu} hors de la plage acceptee ({$plage})",
                    valeurAttendue: $plage,
                    valeurObtenue: (string) $codeObtenu,
                );
            }
        }

        // Verification du nombre maximal de redirections
        if (isset($config['max_redirections'])) {
            $maxRedirections = (int) $config['max_redirections'];
            $nombreRedirections = $contexte->nombreRedirections;

            if ($nombreRedirections > $maxRedirections) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "Trop de redirections : {$nombreRedirections} (max autorise : {$maxRedirections})",
                    valeurAttendue: (string) $maxRedirections,
                    valeurObtenue: (string) $nombreRedirections,
                    details: ['nombre_redirections' => $nombreRedirections],
                );
            }
        }

        // Verification de l'URL finale apres redirection
        if (!empty($config['verifier_redirection']) && isset($config['url_finale_attendue'])) {
            $urlFinaleAttendue = trim($config['url_finale_attendue']);
            $urlFinaleObtenue = $contexte->urlFinale;

            if ($urlFinaleObtenue === null || $urlFinaleObtenue !== $urlFinaleAttendue) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "URL finale apres redirection incorrecte",
                    valeurAttendue: $urlFinaleAttendue,
                    valeurObtenue: $urlFinaleObtenue ?? '(aucune redirection)',
                    details: [
                        'nombre_redirections' => $contexte->nombreRedirections,
                        'url_finale' => $urlFinaleObtenue,
                    ],
                );
            }
        }

        // Toutes les verifications sont passees
        $message = "Code HTTP {$codeObtenu} conforme";
        if ($contexte->nombreRedirections > 0) {
            $message .= " ({$contexte->nombreRedirections} redirection(s))";
        }

        return ResultatVerification::succes(
            message: $message,
            valeurObtenue: (string) $codeObtenu,
        );
    }

    /**
     * Verifie si un code HTTP appartient a une plage donnee (ex: '2xx', '3xx').
     */
    private function codeDansPlage(int $code, string $plage): bool
    {
        // Extraction du premier chiffre de la plage (ex: '2xx' -> 2)
        if (preg_match('/^([1-5])xx$/i', $plage, $matches) !== 1) {
            return false;
        }

        $prefixeAttendu = (int) $matches[1];
        $prefixeObtenu = intdiv($code, 100);

        return $prefixeObtenu === $prefixeAttendu;
    }
}
