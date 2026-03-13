<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de disponibilite du site.
 *
 * Controle l'accessibilite basique (code HTTP), detecte les pages de maintenance,
 * les soft 404 et verifie l'attribut lang du document HTML.
 */
final class VerificateurDisponibilite implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'disponibilite';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;

        // 1. Verification basique : le serveur repond-il avec un code 2xx ?
        $resultatDisponibilite = $this->verifierCodeReponse($contexte, $severite);
        if (!$resultatDisponibilite->succes) {
            return $resultatDisponibilite;
        }

        // 2. Detection d'une page de maintenance
        if (!empty($config['pattern_maintenance'])) {
            $resultatMaintenance = $this->detecterMaintenance(
                $contexte,
                $config['pattern_maintenance'],
                $severite,
            );
            if (!$resultatMaintenance->succes) {
                return $resultatMaintenance;
            }
        }

        // 3. Detection de soft 404
        if (!empty($config['detecter_soft_404'])) {
            $resultatSoft404 = $this->detecterSoft404($contexte, $severite);
            if (!$resultatSoft404->succes) {
                return $resultatSoft404;
            }
        }

        // 4. Detection de page d'erreur generique
        if (!empty($config['pattern_erreur'])) {
            $resultatErreur = $this->detecterPageErreur(
                $contexte,
                $config['pattern_erreur'],
                $severite,
            );
            if (!$resultatErreur->succes) {
                return $resultatErreur;
            }
        }

        // 5. Verification de l'attribut lang
        if (!empty($config['verifier_lang']) && isset($config['lang_attendue'])) {
            $resultatLang = $this->verifierAttributLang(
                $contexte,
                $config['lang_attendue'],
                $severite,
            );
            if (!$resultatLang->succes) {
                return $resultatLang;
            }
        }

        return ResultatVerification::succes(
            message: "Page disponible (HTTP {$contexte->codeHttp})",
            valeurObtenue: (string) $contexte->codeHttp,
            dureeMs: (int) round($contexte->tempsTotalMs),
        );
    }

    /**
     * Verifie que le code HTTP indique une reponse reussie (2xx).
     */
    private function verifierCodeReponse(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $code = $contexte->codeHttp;

        // Code 0 = pas de reponse du serveur (timeout, DNS, etc.)
        if ($code === 0) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Critique,
                message: "Aucune reponse du serveur (timeout ou erreur reseau)",
                valeurAttendue: '2xx',
                valeurObtenue: '0 (pas de reponse)',
                dureeMs: (int) round($contexte->tempsTotalMs),
            );
        }

        // Codes 5xx = erreur serveur
        if ($code >= 500) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Critique,
                message: "Erreur serveur HTTP {$code}",
                valeurAttendue: '2xx',
                valeurObtenue: (string) $code,
                dureeMs: (int) round($contexte->tempsTotalMs),
            );
        }

        // Codes 4xx = erreur client (page non trouvee, interdit, etc.)
        if ($code >= 400) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Erreur HTTP {$code} : page inaccessible",
                valeurAttendue: '2xx',
                valeurObtenue: (string) $code,
                dureeMs: (int) round($contexte->tempsTotalMs),
            );
        }

        // Codes 2xx et 3xx sont consideres comme disponibles
        return ResultatVerification::succes(
            message: "Serveur accessible (HTTP {$code})",
            valeurObtenue: (string) $code,
        );
    }

    /**
     * Detecte une page de maintenance via un pattern regex sur le corps de la reponse.
     */
    private function detecterMaintenance(
        ContexteVerification $contexte,
        string $patternMaintenance,
        NiveauSeverite $severite,
    ): ResultatVerification {
        // Validation du pattern regex
        if (@preg_match($patternMaintenance, '') === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Pattern de maintenance invalide : '{$patternMaintenance}'",
            );
        }

        if (preg_match($patternMaintenance, $contexte->corpsReponse) === 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Page de maintenance detectee (pattern : {$patternMaintenance})",
                valeurAttendue: 'contenu normal',
                valeurObtenue: 'page de maintenance',
                dureeMs: (int) round($contexte->tempsTotalMs),
                details: ['pattern_detecte' => $patternMaintenance],
            );
        }

        return ResultatVerification::succes(
            message: "Aucune page de maintenance detectee",
        );
    }

    /**
     * Detecte les soft 404 : pages qui retournent un 200 mais affichent un contenu de type 404.
     *
     * Heuristiques utilisees :
     * - Presence de termes typiques d'erreur dans le <title>
     * - Corps de reponse trop court (page vide ou quasi-vide)
     */
    private function detecterSoft404(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        // Ne verifier que les reponses 200
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::succes(
                message: "Verification soft 404 non applicable (HTTP {$contexte->codeHttp})",
            );
        }

        $corps = $contexte->corpsReponse;

        // Heuristique 1 : recherche de termes d'erreur dans le <title>
        $xpath = $contexte->xpath();
        if ($xpath !== null) {
            $titres = $xpath->query('//title');
            if ($titres !== false && $titres->length > 0) {
                $titrePage = strtolower(trim($titres->item(0)?->textContent ?? ''));
                $termesErreur = ['404', 'not found', 'page introuvable', 'page non trouvée', 'page non trouvee', 'erreur'];

                foreach ($termesErreur as $terme) {
                    if (str_contains($titrePage, $terme)) {
                        return ResultatVerification::echec(
                            severite: $severite,
                            message: "Soft 404 detecte : le titre contient '{$terme}' malgre un HTTP 200",
                            valeurAttendue: 'contenu normal',
                            valeurObtenue: "titre : '{$titrePage}'",
                            details: ['titre_page' => $titrePage, 'terme_detecte' => $terme],
                        );
                    }
                }
            }
        }

        // Heuristique 2 : corps de reponse trop court (< 512 octets de contenu textuel)
        $contenuTexte = strip_tags($corps);
        $longueurTexte = strlen(trim($contenuTexte));
        if ($longueurTexte < 512 && $longueurTexte > 0) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: "Contenu suspicieusement court ({$longueurTexte} caracteres) — possible soft 404",
                valeurAttendue: '>= 512 caracteres',
                valeurObtenue: "{$longueurTexte} caracteres",
                details: ['longueur_texte' => $longueurTexte],
            );
        }

        return ResultatVerification::succes(
            message: "Aucun indicateur de soft 404 detecte",
        );
    }

    /**
     * Detecte une page d'erreur generique via un pattern regex.
     */
    private function detecterPageErreur(
        ContexteVerification $contexte,
        string $patternErreur,
        NiveauSeverite $severite,
    ): ResultatVerification {
        // Validation du pattern regex
        if (@preg_match($patternErreur, '') === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Pattern d'erreur invalide : '{$patternErreur}'",
            );
        }

        if (preg_match($patternErreur, $contexte->corpsReponse) === 1) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Page d'erreur detectee (pattern : {$patternErreur})",
                valeurAttendue: 'contenu normal',
                valeurObtenue: 'page d\'erreur',
                details: ['pattern_detecte' => $patternErreur],
            );
        }

        return ResultatVerification::succes(
            message: "Aucune page d'erreur detectee",
        );
    }

    /**
     * Verifie l'attribut lang de la balise <html>.
     */
    private function verifierAttributLang(
        ContexteVerification $contexte,
        string $langAttendue,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $xpath = $contexte->xpath();

        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Impossible de parser le DOM pour verifier l'attribut lang",
            );
        }

        $htmlNodes = $xpath->query('//html[@lang]');

        if ($htmlNodes === false || $htmlNodes->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Attribut lang absent de la balise <html>",
                valeurAttendue: $langAttendue,
                valeurObtenue: '(absent)',
            );
        }

        $noeudHtml = $htmlNodes->item(0);
        if (!$noeudHtml instanceof \DOMElement) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Impossible de lire l'attribut lang",
            );
        }

        $langObtenue = strtolower(trim($noeudHtml->getAttribute('lang')));
        $langAttendueNormalisee = strtolower(trim($langAttendue));

        // Comparaison souple : 'fr' correspond a 'fr-FR', 'fr-fr', etc.
        if ($langObtenue !== $langAttendueNormalisee && !str_starts_with($langObtenue, $langAttendueNormalisee . '-')) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Attribut lang incorrect : '{$langObtenue}' au lieu de '{$langAttendue}'",
                valeurAttendue: $langAttendue,
                valeurObtenue: $langObtenue,
            );
        }

        return ResultatVerification::succes(
            message: "Attribut lang conforme : '{$langObtenue}'",
            valeurObtenue: $langObtenue,
        );
    }
}
