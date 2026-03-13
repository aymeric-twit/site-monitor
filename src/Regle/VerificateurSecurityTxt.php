<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur du fichier security.txt.
 *
 * Controle l'accessibilite du fichier /.well-known/security.txt
 * et verifie la presence des champs obligatoires (Contact, Expires)
 * selon la RFC 9116.
 */
final class VerificateurSecurityTxt implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'security_txt';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'accessible';

        return match ($verification) {
            'accessible' => $this->verifierAccessible($contexte, $severite),
            'contact_present' => $this->verifierContactPresent($contexte, $severite),
            'expires_present' => $this->verifierExpiresPresent($contexte, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification security.txt inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie que le security.txt est accessible (code 200) et non vide.
     */
    private function verifierAccessible(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le security.txt n'est pas accessible (code HTTP {$contexte->codeHttp})",
                valeurAttendue: '200',
                valeurObtenue: (string) $contexte->codeHttp,
            );
        }

        $contenu = trim($contexte->corpsReponse);

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le security.txt est vide',
                valeurAttendue: 'contenu non vide',
                valeurObtenue: 'fichier vide',
            );
        }

        // Parser les champs presents
        $champs = $this->parserChamps($contenu);
        $champsPresents = array_keys($champs);

        return ResultatVerification::succes(
            message: 'Le security.txt est accessible',
            valeurObtenue: (string) $contexte->codeHttp,
            details: [
                'champs_presents' => $champsPresents,
                'nombre_lignes' => count(explode("\n", $contenu)),
            ],
        );
    }

    /**
     * Verifie la presence du champ Contact (obligatoire selon RFC 9116).
     */
    private function verifierContactPresent(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenu = $contexte->corpsReponse;
        $champs = $this->parserChamps($contenu);

        if (!isset($champs['contact']) || $champs['contact'] === []) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le champ Contact est absent du security.txt (obligatoire selon RFC 9116)',
                valeurAttendue: 'au moins un champ Contact',
                valeurObtenue: 'absent',
            );
        }

        $nombreContacts = count($champs['contact']);

        return ResultatVerification::succes(
            message: "{$nombreContacts} champ(s) Contact present(s) dans le security.txt",
            valeurObtenue: (string) $nombreContacts,
            details: ['contacts' => $champs['contact']],
        );
    }

    /**
     * Verifie la presence du champ Expires (obligatoire selon RFC 9116).
     */
    private function verifierExpiresPresent(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenu = $contexte->corpsReponse;
        $champs = $this->parserChamps($contenu);

        if (!isset($champs['expires']) || $champs['expires'] === []) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le champ Expires est absent du security.txt (obligatoire selon RFC 9116)',
                valeurAttendue: 'champ Expires present',
                valeurObtenue: 'absent',
            );
        }

        $valeurExpires = $champs['expires'][0];

        // Verifier si la date est parsable et pas expiree
        $timestamp = strtotime($valeurExpires);

        if ($timestamp === false) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: "Le champ Expires est present mais la date n'est pas valide : {$valeurExpires}",
                valeurAttendue: 'date ISO 8601 valide',
                valeurObtenue: $valeurExpires,
            );
        }

        if ($timestamp < time()) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le security.txt a expire le {$valeurExpires}",
                valeurAttendue: 'date future',
                valeurObtenue: $valeurExpires,
                details: ['expire_depuis_jours' => (int) ceil((time() - $timestamp) / 86400)],
            );
        }

        $joursRestants = (int) floor(($timestamp - time()) / 86400);

        return ResultatVerification::succes(
            message: "Le champ Expires est present (expire dans {$joursRestants} jour(s))",
            valeurObtenue: $valeurExpires,
            details: ['jours_restants' => $joursRestants],
        );
    }

    /**
     * Parse le contenu du security.txt et retourne les champs par nom.
     *
     * @return array<string, list<string>> Champs indexes par nom (minuscule), valeurs multiples possibles
     */
    private function parserChamps(string $contenu): array
    {
        $champs = [];
        $lignes = explode("\n", str_replace("\r\n", "\n", $contenu));

        foreach ($lignes as $ligne) {
            $ligne = trim($ligne);

            // Ignorer commentaires et lignes vides
            if ($ligne === '' || str_starts_with($ligne, '#')) {
                continue;
            }

            $position = strpos($ligne, ':');
            if ($position === false) {
                continue;
            }

            $nom = strtolower(trim(substr($ligne, 0, $position)));
            $valeur = trim(substr($ligne, $position + 1));

            if ($nom !== '' && $valeur !== '') {
                $champs[$nom][] = $valeur;
            }
        }

        return $champs;
    }
}
