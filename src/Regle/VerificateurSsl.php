<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de certificat SSL/TLS.
 *
 * Controle la validite, la date d'expiration, la correspondance du domaine,
 * la detection de certificat auto-signe et la chaine de confiance.
 */
final class VerificateurSsl implements InterfaceVerificateur
{
    /** Nombre de jours avant expiration par defaut pour declencher une alerte. */
    private const int JOURS_AVANT_EXPIRATION_DEFAUT = 30;

    #[\Override]
    public function typeGere(): string
    {
        return 'ssl';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'validite';
        $infosSsl = $contexte->infosSsl;

        // Pas d'informations SSL disponibles
        if ($infosSsl === null || empty($infosSsl)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucune information SSL disponible pour cette URL',
                valeurAttendue: 'certificat SSL valide',
                valeurObtenue: 'aucun certificat',
            );
        }

        return match ($verification) {
            'validite' => $this->verifierValidite($infosSsl, $severite),
            'expiration' => $this->verifierExpiration($infosSsl, $config, $severite),
            'domaine' => $this->verifierDomaine($infosSsl, $contexte->url, $severite),
            'auto_signe' => $this->detecterAutoSigne($infosSsl, $severite),
            'chaine_complete' => $this->verifierChaineComplete($infosSsl, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification SSL inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie la validite generale du certificat (dates de debut et fin).
     *
     * @param array<string, mixed> $infosSsl
     */
    private function verifierValidite(array $infosSsl, NiveauSeverite $severite): ResultatVerification
    {
        $certInfo = $this->extraireCertificat($infosSsl);

        if ($certInfo === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible d\'extraire les informations du certificat',
            );
        }

        $maintenant = time();
        $validFrom = $certInfo['validFrom_time_t'] ?? 0;
        $validTo = $certInfo['validTo_time_t'] ?? 0;

        if ($maintenant < $validFrom) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le certificat n\'est pas encore valide',
                valeurAttendue: 'certificat actif',
                valeurObtenue: 'debut de validite : ' . date('Y-m-d H:i:s', $validFrom),
                details: [
                    'valid_from' => date('c', $validFrom),
                    'valid_to' => date('c', $validTo),
                ],
            );
        }

        if ($maintenant > $validTo) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Critique,
                message: 'Le certificat SSL a expire le ' . date('d/m/Y', $validTo),
                valeurAttendue: 'certificat valide',
                valeurObtenue: 'expire depuis ' . $this->joursDepuis($validTo) . ' jour(s)',
                details: [
                    'valid_from' => date('c', $validFrom),
                    'valid_to' => date('c', $validTo),
                    'expire_depuis_jours' => $this->joursDepuis($validTo),
                ],
            );
        }

        $joursRestants = $this->joursJusqua($validTo);

        return ResultatVerification::succes(
            message: "Certificat SSL valide (expire dans {$joursRestants} jour(s))",
            valeurObtenue: "{$joursRestants} jours restants",
            details: [
                'valid_from' => date('c', $validFrom),
                'valid_to' => date('c', $validTo),
                'jours_restants' => $joursRestants,
                'emetteur' => $certInfo['issuer']['O'] ?? 'inconnu',
            ],
        );
    }

    /**
     * Verifie si le certificat expire bientot (alerte X jours avant).
     *
     * @param array<string, mixed> $infosSsl
     * @param array<string, mixed> $config
     */
    private function verifierExpiration(
        array $infosSsl,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $certInfo = $this->extraireCertificat($infosSsl);

        if ($certInfo === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible d\'extraire les informations du certificat',
            );
        }

        $joursAvantExpiration = (int) ($config['jours_avant_expiration'] ?? self::JOURS_AVANT_EXPIRATION_DEFAUT);
        $validTo = $certInfo['validTo_time_t'] ?? 0;
        $maintenant = time();

        // Certificat deja expire
        if ($maintenant > $validTo) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Critique,
                message: 'Le certificat SSL a expire le ' . date('d/m/Y', $validTo),
                valeurAttendue: "valide au moins {$joursAvantExpiration} jour(s)",
                valeurObtenue: 'expire depuis ' . $this->joursDepuis($validTo) . ' jour(s)',
            );
        }

        $joursRestants = $this->joursJusqua($validTo);

        if ($joursRestants <= $joursAvantExpiration) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le certificat SSL expire dans {$joursRestants} jour(s) (seuil : {$joursAvantExpiration})",
                valeurAttendue: "> {$joursAvantExpiration} jours",
                valeurObtenue: "{$joursRestants} jours restants",
                details: [
                    'date_expiration' => date('c', $validTo),
                    'jours_restants' => $joursRestants,
                    'seuil_alerte' => $joursAvantExpiration,
                ],
            );
        }

        return ResultatVerification::succes(
            message: "Certificat SSL valide, expire dans {$joursRestants} jour(s)",
            valeurObtenue: "{$joursRestants} jours restants",
            details: [
                'date_expiration' => date('c', $validTo),
                'jours_restants' => $joursRestants,
                'seuil_alerte' => $joursAvantExpiration,
            ],
        );
    }

    /**
     * Verifie que le domaine du certificat correspond a l'URL testee.
     *
     * @param array<string, mixed> $infosSsl
     */
    private function verifierDomaine(
        array $infosSsl,
        string $url,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $certInfo = $this->extraireCertificat($infosSsl);

        if ($certInfo === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible d\'extraire les informations du certificat',
            );
        }

        $domaineUrl = parse_url($url, PHP_URL_HOST);
        if ($domaineUrl === null || $domaineUrl === false) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Impossible d'extraire le domaine de l'URL : {$url}",
            );
        }

        $domainesCertificat = $this->extraireDomaines($certInfo);

        if ($this->domaineCouvert($domaineUrl, $domainesCertificat)) {
            return ResultatVerification::succes(
                message: "Le domaine {$domaineUrl} est couvert par le certificat",
                valeurObtenue: $domaineUrl,
                details: ['domaines_certificat' => $domainesCertificat],
            );
        }

        return ResultatVerification::echec(
            severite: $severite,
            message: "Le domaine {$domaineUrl} n'est pas couvert par le certificat",
            valeurAttendue: $domaineUrl,
            valeurObtenue: implode(', ', $domainesCertificat),
            details: ['domaines_certificat' => $domainesCertificat],
        );
    }

    /**
     * Detecte si le certificat est auto-signe.
     *
     * @param array<string, mixed> $infosSsl
     */
    private function detecterAutoSigne(array $infosSsl, NiveauSeverite $severite): ResultatVerification
    {
        $certInfo = $this->extraireCertificat($infosSsl);

        if ($certInfo === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible d\'extraire les informations du certificat',
            );
        }

        $sujet = $certInfo['subject'] ?? [];
        $emetteur = $certInfo['issuer'] ?? [];

        // Un certificat auto-signe a le meme sujet et emetteur
        $estAutoSigne = ($sujet === $emetteur);

        if ($estAutoSigne) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le certificat est auto-signe',
                valeurAttendue: 'certificat signe par une autorite de confiance',
                valeurObtenue: 'auto-signe (sujet = emetteur)',
                details: [
                    'sujet' => $sujet['CN'] ?? 'inconnu',
                    'emetteur' => $emetteur['CN'] ?? 'inconnu',
                ],
            );
        }

        return ResultatVerification::succes(
            message: 'Le certificat est signe par une autorite tierce',
            valeurObtenue: $emetteur['O'] ?? ($emetteur['CN'] ?? 'inconnu'),
            details: [
                'sujet' => $sujet['CN'] ?? 'inconnu',
                'emetteur_cn' => $emetteur['CN'] ?? 'inconnu',
                'emetteur_o' => $emetteur['O'] ?? 'inconnu',
            ],
        );
    }

    /**
     * Verifie que la chaine de certificats est complete.
     *
     * @param array<string, mixed> $infosSsl
     */
    private function verifierChaineComplete(array $infosSsl, NiveauSeverite $severite): ResultatVerification
    {
        // La chaine est disponible dans les options du contexte SSL
        $options = $infosSsl['options'] ?? [];
        $peerCertificate = $infosSsl['peer_certificate'] ?? null;
        $peerCertificateChain = $infosSsl['peer_certificate_chain'] ?? [];

        // Si pas de chaine disponible, on verifie via les informations de base
        if (empty($peerCertificateChain) && $peerCertificate === null) {
            $certInfo = $this->extraireCertificat($infosSsl);

            if ($certInfo === null) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: 'Impossible de verifier la chaine de certificats',
                );
            }

            // Verification basique : si le certificat est auto-signe, la chaine est incomplete
            $sujet = $certInfo['subject'] ?? [];
            $emetteur = $certInfo['issuer'] ?? [];

            if ($sujet === $emetteur) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: 'Chaine de certificats incomplete : certificat auto-signe detecte',
                    valeurAttendue: 'chaine complete avec autorite racine',
                    valeurObtenue: 'certificat auto-signe',
                );
            }

            return ResultatVerification::succes(
                message: 'La chaine de certificats semble valide (verification basique)',
                details: [
                    'emetteur' => $emetteur['O'] ?? ($emetteur['CN'] ?? 'inconnu'),
                    'note' => 'Verification approfondie non disponible sans la chaine complete',
                ],
            );
        }

        // Avec la chaine complete, on verifie chaque maillon
        $nombreCertificats = count($peerCertificateChain);

        if ($nombreCertificats < 2) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Chaine de certificats possiblement incomplete ({$nombreCertificats} certificat(s))",
                valeurAttendue: '>= 2 certificats dans la chaine',
                valeurObtenue: "{$nombreCertificats} certificat(s)",
            );
        }

        return ResultatVerification::succes(
            message: "Chaine de certificats complete ({$nombreCertificats} certificat(s))",
            valeurObtenue: "{$nombreCertificats} certificats",
            details: ['nombre_certificats_chaine' => $nombreCertificats],
        );
    }

    /**
     * Extrait les informations du certificat depuis les donnees SSL.
     *
     * @param array<string, mixed> $infosSsl
     * @return array<string, mixed>|null
     */
    private function extraireCertificat(array $infosSsl): ?array
    {
        // Si c'est directement un tableau d'infos openssl_x509_parse
        if (isset($infosSsl['subject']) && isset($infosSsl['issuer'])) {
            return $infosSsl;
        }

        // Si c'est un resultat de stream_context_get_params
        if (isset($infosSsl['options']['ssl']['peer_certificate'])) {
            $certResource = $infosSsl['options']['ssl']['peer_certificate'];
            $parsed = openssl_x509_parse($certResource);
            return $parsed !== false ? $parsed : null;
        }

        // Si la cle 'peer_certificate' est directement disponible
        if (isset($infosSsl['peer_certificate'])) {
            $parsed = openssl_x509_parse($infosSsl['peer_certificate']);
            return $parsed !== false ? $parsed : null;
        }

        return null;
    }

    /**
     * Extrait tous les domaines couverts par le certificat (CN + SAN).
     *
     * @param array<string, mixed> $certInfo
     * @return list<string>
     */
    private function extraireDomaines(array $certInfo): array
    {
        $domaines = [];

        // Common Name (CN)
        if (isset($certInfo['subject']['CN'])) {
            $domaines[] = strtolower($certInfo['subject']['CN']);
        }

        // Subject Alternative Names (SAN)
        $extensions = $certInfo['extensions'] ?? [];
        if (isset($extensions['subjectAltName'])) {
            $sanEntries = explode(',', $extensions['subjectAltName']);
            foreach ($sanEntries as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $domaines[] = strtolower(substr($entry, 4));
                }
            }
        }

        return array_values(array_unique($domaines));
    }

    /**
     * Verifie si un domaine est couvert par la liste de domaines du certificat (avec wildcard).
     *
     * @param list<string> $domainesCertificat
     */
    private function domaineCouvert(string $domaine, array $domainesCertificat): bool
    {
        $domaine = strtolower($domaine);

        foreach ($domainesCertificat as $domaineCert) {
            $domaineCert = strtolower($domaineCert);

            // Correspondance exacte
            if ($domaine === $domaineCert) {
                return true;
            }

            // Wildcard : *.example.com couvre sub.example.com mais pas sub.sub.example.com
            if (str_starts_with($domaineCert, '*.')) {
                $suffixe = substr($domaineCert, 2);
                if (str_ends_with($domaine, $suffixe) && substr_count($domaine, '.') === substr_count($suffixe, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calcule le nombre de jours depuis un timestamp passe.
     */
    private function joursDepuis(int $timestamp): int
    {
        return (int) ceil((time() - $timestamp) / 86400);
    }

    /**
     * Calcule le nombre de jours jusqu'a un timestamp futur.
     */
    private function joursJusqua(int $timestamp): int
    {
        return (int) floor(($timestamp - time()) / 86400);
    }
}
