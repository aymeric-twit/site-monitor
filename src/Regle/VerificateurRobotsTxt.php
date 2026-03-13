<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur du fichier robots.txt.
 *
 * Analyse le contenu du robots.txt pour verifier son accessibilite,
 * la presence d'un sitemap, la detection d'un blocage total,
 * le controle d'URLs critiques et la comparaison avec une reference.
 */
final class VerificateurRobotsTxt implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'robots_txt';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'accessible';

        return match ($verification) {
            'accessible' => $this->verifierAccessible($contexte, $severite),
            'sitemap_present' => $this->verifierSitemapPresent($contexte, $severite),
            'disallow_total' => $this->verifierDisallowTotal($contexte, $severite),
            'urls_critiques' => $this->verifierUrlsCritiques($contexte, $config, $severite),
            'taille' => $this->verifierTaille($contexte, $config, $severite),
            'comparaison' => $this->verifierComparaison($contexte, $config, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification robots.txt inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie que le robots.txt est accessible (code 200) et non vide.
     */
    private function verifierAccessible(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le robots.txt n'est pas accessible (code HTTP {$contexte->codeHttp})",
                valeurAttendue: '200',
                valeurObtenue: (string) $contexte->codeHttp,
            );
        }

        $contenu = trim($contexte->corpsReponse);

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le robots.txt est vide',
                valeurAttendue: 'contenu non vide',
                valeurObtenue: 'fichier vide',
            );
        }

        $nombreLignes = count(explode("\n", $contenu));

        return ResultatVerification::succes(
            message: "Le robots.txt est accessible ({$nombreLignes} ligne(s))",
            valeurObtenue: "{$nombreLignes} lignes",
            details: [
                'taille_octets' => strlen($contenu),
                'nombre_lignes' => $nombreLignes,
            ],
        );
    }

    /**
     * Verifie la presence d'au moins une directive Sitemap.
     */
    private function verifierSitemapPresent(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenu = $contexte->corpsReponse;
        $sitemaps = $this->extraireSitemaps($contenu);

        if ($sitemaps === []) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucune directive Sitemap trouvee dans le robots.txt',
                valeurAttendue: 'au moins une directive Sitemap',
                valeurObtenue: '0 sitemap',
            );
        }

        $nombre = count($sitemaps);

        return ResultatVerification::succes(
            message: "{$nombre} directive(s) Sitemap trouvee(s)",
            valeurObtenue: (string) $nombre,
            details: ['sitemaps' => $sitemaps],
        );
    }

    /**
     * Detecte un blocage total (Disallow: /) qui empeche l'indexation.
     */
    private function verifierDisallowTotal(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenu = $contexte->corpsReponse;
        $blocs = $this->parserBlocs($contenu);
        $blocsBloquants = [];

        foreach ($blocs as $bloc) {
            $userAgent = strtolower($bloc['user_agent']);
            foreach ($bloc['regles'] as $regleTxt) {
                $type = strtolower($regleTxt['type']);
                $chemin = trim($regleTxt['valeur']);

                // Disallow: / bloque tout (sauf si un Allow le surcharge)
                if ($type === 'disallow' && $chemin === '/') {
                    // Verifier qu'il n'y a pas un Allow: / dans le meme bloc
                    $aAllowGlobal = false;
                    foreach ($bloc['regles'] as $autreRegle) {
                        if (strtolower($autreRegle['type']) === 'allow' && trim($autreRegle['valeur']) === '/') {
                            $aAllowGlobal = true;
                            break;
                        }
                    }

                    if (!$aAllowGlobal) {
                        $blocsBloquants[] = $bloc['user_agent'];
                    }
                }
            }
        }

        if ($blocsBloquants !== []) {
            $agents = implode(', ', $blocsBloquants);
            $estGlobal = in_array('*', $blocsBloquants, true);

            return ResultatVerification::echec(
                severite: $estGlobal ? NiveauSeverite::Critique : $severite,
                message: $estGlobal
                    ? 'Le robots.txt bloque TOUS les robots (Disallow: / pour User-Agent: *)'
                    : "Le robots.txt bloque completement certains robots : {$agents}",
                valeurAttendue: 'pas de blocage total',
                valeurObtenue: "Disallow: / pour {$agents}",
                details: ['user_agents_bloques' => $blocsBloquants],
            );
        }

        return ResultatVerification::succes(
            message: 'Aucun blocage total detecte dans le robots.txt',
        );
    }

    /**
     * Verifie que des URLs critiques ne sont pas bloquees par le robots.txt.
     *
     * @param array<string, mixed> $config
     */
    private function verifierUrlsCritiques(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $urlsCritiques = $config['urls_critiques'] ?? [];

        if ($urlsCritiques === []) {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: 'Aucune URL critique configuree pour la verification',
            );
        }

        $contenu = $contexte->corpsReponse;
        $blocs = $this->parserBlocs($contenu);
        $urlsBloquees = [];

        foreach ($urlsCritiques as $url) {
            if ($this->urlEstBloquee($url, $blocs)) {
                $urlsBloquees[] = $url;
            }
        }

        if ($urlsBloquees !== []) {
            $nombre = count($urlsBloquees);
            $total = count($urlsCritiques);

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nombre}/{$total} URL(s) critique(s) bloquee(s) par le robots.txt",
                valeurAttendue: '0 URL critique bloquee',
                valeurObtenue: "{$nombre} bloquee(s)",
                details: [
                    'urls_bloquees' => $urlsBloquees,
                    'urls_verifiees' => $urlsCritiques,
                ],
            );
        }

        $total = count($urlsCritiques);

        return ResultatVerification::succes(
            message: "Toutes les {$total} URL(s) critique(s) sont accessibles aux robots",
            valeurObtenue: "0/{$total} bloquee(s)",
            details: ['urls_verifiees' => $urlsCritiques],
        );
    }

    /**
     * Verifie que la taille du robots.txt ne depasse pas un seuil.
     *
     * @param array<string, mixed> $config
     */
    private function verifierTaille(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $tailleMaxOctets = (int) ($config['taille_max_octets'] ?? 512000); // 500 Ko par defaut (limite Google)
        $taille = $contexte->tailleOctets;

        if ($taille > $tailleMaxOctets) {
            return ResultatVerification::echec(
                severite: $severite,
                message: sprintf(
                    'Le robots.txt depasse la taille maximale (%s > %s)',
                    $this->formaterOctets($taille),
                    $this->formaterOctets($tailleMaxOctets),
                ),
                valeurAttendue: "<= {$this->formaterOctets($tailleMaxOctets)}",
                valeurObtenue: $this->formaterOctets($taille),
            );
        }

        return ResultatVerification::succes(
            message: "Taille du robots.txt conforme ({$this->formaterOctets($taille)})",
            valeurObtenue: $this->formaterOctets($taille),
            details: [
                'taille_octets' => $taille,
                'taille_max_octets' => $tailleMaxOctets,
            ],
        );
    }

    /**
     * Compare le contenu actuel avec un contenu de reference.
     *
     * @param array<string, mixed> $config
     */
    private function verifierComparaison(
        ContexteVerification $contexte,
        array $config,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenuReference = $config['contenu_reference'] ?? null;

        if ($contenuReference === null || $contenuReference === '') {
            return ResultatVerification::echec(
                severite: NiveauSeverite::Avertissement,
                message: 'Aucun contenu de reference configure pour la comparaison',
            );
        }

        $contenuActuel = $this->normaliserContenu($contexte->corpsReponse);
        $contenuRef = $this->normaliserContenu($contenuReference);

        if ($contenuActuel === $contenuRef) {
            return ResultatVerification::succes(
                message: 'Le robots.txt est identique a la reference',
            );
        }

        // Calculer les differences ligne par ligne
        $lignesActuelles = explode("\n", $contenuActuel);
        $lignesReference = explode("\n", $contenuRef);

        $ajoutees = array_diff($lignesActuelles, $lignesReference);
        $supprimees = array_diff($lignesReference, $lignesActuelles);

        return ResultatVerification::echec(
            severite: $severite,
            message: sprintf(
                'Le robots.txt a ete modifie (%d ligne(s) ajoutee(s), %d ligne(s) supprimee(s))',
                count($ajoutees),
                count($supprimees),
            ),
            valeurAttendue: 'identique a la reference',
            valeurObtenue: 'contenu modifie',
            details: [
                'lignes_ajoutees' => array_values($ajoutees),
                'lignes_supprimees' => array_values($supprimees),
            ],
        );
    }

    /**
     * Parse le robots.txt en blocs User-Agent avec leurs regles.
     *
     * @return list<array{user_agent: string, regles: list<array{type: string, valeur: string}>}>
     */
    private function parserBlocs(string $contenu): array
    {
        $blocs = [];
        $blocCourant = null;
        $lignes = explode("\n", $contenu);

        foreach ($lignes as $ligne) {
            $ligne = trim($ligne);

            // Ignorer les commentaires et lignes vides
            if ($ligne === '' || str_starts_with($ligne, '#')) {
                continue;
            }

            // Separer directive et valeur
            $position = strpos($ligne, ':');
            if ($position === false) {
                continue;
            }

            $directive = strtolower(trim(substr($ligne, 0, $position)));
            $valeur = trim(substr($ligne, $position + 1));

            if ($directive === 'user-agent') {
                // Nouveau bloc
                $blocCourant = ['user_agent' => $valeur, 'regles' => []];
                $blocs[] = &$blocCourant;
            } elseif ($blocCourant !== null && in_array($directive, ['disallow', 'allow', 'crawl-delay'], true)) {
                $blocCourant['regles'][] = ['type' => $directive, 'valeur' => $valeur];
            }
        }

        unset($blocCourant);

        return $blocs;
    }

    /**
     * Extrait les URLs de sitemap du robots.txt.
     *
     * @return list<string>
     */
    private function extraireSitemaps(string $contenu): array
    {
        $sitemaps = [];
        $lignes = explode("\n", $contenu);

        foreach ($lignes as $ligne) {
            $ligne = trim($ligne);
            if (str_starts_with(strtolower($ligne), 'sitemap:')) {
                $url = trim(substr($ligne, 8));
                if ($url !== '') {
                    $sitemaps[] = $url;
                }
            }
        }

        return $sitemaps;
    }

    /**
     * Verifie si une URL est bloquee pour le User-Agent * (Googlebot par defaut).
     *
     * @param list<array{user_agent: string, regles: list<array{type: string, valeur: string}>}> $blocs
     */
    private function urlEstBloquee(string $url, array $blocs): bool
    {
        // On verifie pour User-Agent: * (regles generales)
        foreach ($blocs as $bloc) {
            if ($bloc['user_agent'] !== '*') {
                continue;
            }

            $estBloquee = false;

            foreach ($bloc['regles'] as $regleTxt) {
                $type = strtolower($regleTxt['type']);
                $motif = $regleTxt['valeur'];

                if ($motif === '') {
                    continue;
                }

                if ($this->cheminCorrespond($url, $motif)) {
                    if ($type === 'disallow') {
                        $estBloquee = true;
                    } elseif ($type === 'allow') {
                        $estBloquee = false;
                    }
                }
            }

            return $estBloquee;
        }

        // Pas de bloc User-Agent: * => pas de blocage
        return false;
    }

    /**
     * Verifie si un chemin correspond a un motif robots.txt.
     * Gere les wildcards (*) et les ancres ($).
     */
    private function cheminCorrespond(string $chemin, string $motif): bool
    {
        // Si le motif est vide, il ne bloque rien
        if ($motif === '') {
            return false;
        }

        // Convertir le motif robots.txt en regex
        $regex = '';
        $longueur = strlen($motif);

        for ($i = 0; $i < $longueur; $i++) {
            $caractere = $motif[$i];

            if ($caractere === '*') {
                $regex .= '.*';
            } elseif ($caractere === '$' && $i === $longueur - 1) {
                $regex .= '$';
            } else {
                $regex .= preg_quote($caractere, '#');
            }
        }

        // Si le motif ne finit pas par $, on ajoute .* pour matcher les sous-chemins
        if (!str_ends_with($motif, '$')) {
            $regex .= '.*';
        }

        return preg_match('#^' . $regex . '#i', $chemin) === 1;
    }

    /**
     * Normalise le contenu pour la comparaison (supprime espaces superflus, lignes vides).
     */
    private function normaliserContenu(string $contenu): string
    {
        $lignes = explode("\n", str_replace("\r\n", "\n", $contenu));
        $lignes = array_map('trim', $lignes);
        $lignes = array_filter($lignes, static fn (string $ligne): bool => $ligne !== '');

        return implode("\n", $lignes);
    }

    /**
     * Formate une taille en octets en format lisible.
     */
    private function formaterOctets(int $octets): string
    {
        if ($octets < 1024) {
            return "{$octets} o";
        }

        if ($octets < 1048576) {
            return round($octets / 1024, 1) . ' Ko';
        }

        return round($octets / 1048576, 1) . ' Mo';
    }
}
