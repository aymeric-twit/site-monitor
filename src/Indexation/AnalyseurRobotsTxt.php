<?php

declare(strict_types=1);

namespace SiteMonitor\Indexation;

/**
 * Analyseur de fichier robots.txt conforme a la specification Google.
 *
 * Supporte :
 * - Directives User-agent, Allow, Disallow, Sitemap
 * - Wildcards * et ancre de fin $
 * - Resolution de conflits par longueur de chemin (Allow gagne en cas d'egalite)
 * - BOM UTF-8, commentaires, lignes vides
 * - Limite de 500 Kio
 * - Groupes multiples et fusion des regles pour un meme User-Agent
 * - Detection de directives non-standard (Crawl-delay, Host, Clean-param)
 * - Suppression des fragments (#) dans les URLs
 * - Decodage/re-encodage correct des caracteres %xx
 *
 * @see https://developers.google.com/search/docs/crawling-indexing/robots/robots_txt
 */

enum TypeRegleRobots: string
{
    case ALLOW = 'Allow';
    case DISALLOW = 'Disallow';
}

enum NiveauAnomalieRobots: string
{
    case INFO = 'info';
    case ATTENTION = 'attention';
    case CRITIQUE = 'critique';
}

readonly class RegleRobots
{
    public function __construct(
        public TypeRegleRobots $type,
        public string $chemin,
        public string $directiveBrute,
        public int $numeroLigne,
    ) {}

    /**
     * @return array{type: string, chemin: string, directive_brute: string, numero_ligne: int}
     */
    public function versTableau(): array
    {
        return [
            'type' => $this->type->value,
            'chemin' => $this->chemin,
            'directive_brute' => $this->directiveBrute,
            'numero_ligne' => $this->numeroLigne,
        ];
    }
}

class GroupeReglesRobots
{
    /**
     * @param string[]        $userAgents
     * @param RegleRobots[]   $regles
     * @param int[]           $lignesUA
     */
    public function __construct(
        public array $userAgents = [],
        public array $regles = [],
        public array $lignesUA = [],
    ) {}

    /**
     * @return array{user_agents: string[], regles: array<int, array{type: string, chemin: string, directive_brute: string, numero_ligne: int}>, lignes_ua: int[]}
     */
    public function versTableau(): array
    {
        return [
            'user_agents' => $this->userAgents,
            'regles' => array_map(fn(RegleRobots $r): array => $r->versTableau(), $this->regles),
            'lignes_ua' => $this->lignesUA,
        ];
    }
}

readonly class ResultatVerificationRobots
{
    public function __construct(
        public string $url,
        public bool $autorise,
        public ?RegleRobots $regleAppliquee,
        public string $userAgentConcerne,
        public string $raison,
    ) {}

    /**
     * @return array{url: string, autorise: bool, regle_appliquee: ?array{type: string, chemin: string, directive_brute: string, numero_ligne: int}, user_agent_concerne: string, raison: string}
     */
    public function versTableau(): array
    {
        return [
            'url' => $this->url,
            'autorise' => $this->autorise,
            'regle_appliquee' => $this->regleAppliquee?->versTableau(),
            'user_agent_concerne' => $this->userAgentConcerne,
            'raison' => $this->raison,
        ];
    }
}

readonly class DirectiveNonStandard
{
    public function __construct(
        public string $nom,
        public string $valeur,
        public int $numeroLigne,
    ) {}

    /**
     * @return array{nom: string, valeur: string, numero_ligne: int}
     */
    public function versTableau(): array
    {
        return [
            'nom' => $this->nom,
            'valeur' => $this->valeur,
            'numero_ligne' => $this->numeroLigne,
        ];
    }
}

class AnalyseurRobotsTxt
{
    /** @var GroupeReglesRobots[] */
    private array $groupes = [];

    /** @var string[] */
    private array $sitemaps = [];

    /** @var DirectiveNonStandard[] */
    private array $directivesNonStandard = [];

    public function __construct(string $contenu)
    {
        $this->analyser($contenu);
    }

    /**
     * @return GroupeReglesRobots[]
     */
    public function obtenirGroupes(): array
    {
        return $this->groupes;
    }

    /**
     * @return string[]
     */
    public function obtenirSitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * @return DirectiveNonStandard[]
     */
    public function obtenirDirectivesNonStandard(): array
    {
        return $this->directivesNonStandard;
    }

    /**
     * Verifie si une URL est autorisee pour un User-Agent donne.
     *
     * Algorithme (spec Google) :
     * 1. Trouver le groupe le plus specifique pour le UA
     * 2. Parmi les regles du groupe, trouver celle dont le chemin matche et est le plus long
     * 3. En cas d'egalite de longueur, Allow l'emporte sur Disallow
     * 4. Si aucune regle ne matche, l'URL est autorisee par defaut
     */
    public function estAutorise(string $url, string $userAgent): ResultatVerificationRobots
    {
        $chemin = $this->extraireChemin($url);
        $groupe = $this->trouverGroupeCorrespondant($userAgent);

        if ($groupe === null) {
            return new ResultatVerificationRobots(
                url: $url,
                autorise: true,
                regleAppliquee: null,
                userAgentConcerne: '(aucun)',
                raison: 'Aucun groupe de regles applicable',
            );
        }

        $uaConcerne = implode(', ', $groupe->userAgents);

        $meilleureRegle = null;
        $meilleureLongueur = -1;

        foreach ($groupe->regles as $regle) {
            // Disallow vide = tout autoriser, on l'ignore dans le matching
            if ($regle->chemin === '') {
                continue;
            }

            if ($this->cheminCorrespond($chemin, $regle->chemin)) {
                $longueur = strlen($regle->chemin);

                // La regle avec le chemin le plus long gagne
                // En cas d'egalite, Allow l'emporte (regle la moins restrictive)
                if ($longueur > $meilleureLongueur
                    || ($longueur === $meilleureLongueur && $regle->type === TypeRegleRobots::ALLOW)) {
                    $meilleureRegle = $regle;
                    $meilleureLongueur = $longueur;
                }
            }
        }

        if ($meilleureRegle === null) {
            return new ResultatVerificationRobots(
                url: $url,
                autorise: true,
                regleAppliquee: null,
                userAgentConcerne: $uaConcerne,
                raison: 'Aucune regle ne correspond — autorise par defaut',
            );
        }

        $autorise = $meilleureRegle->type === TypeRegleRobots::ALLOW;

        return new ResultatVerificationRobots(
            url: $url,
            autorise: $autorise,
            regleAppliquee: $meilleureRegle,
            userAgentConcerne: $uaConcerne,
            raison: $autorise ? 'Autorise par regle Allow' : 'Bloque par regle Disallow',
        );
    }

    // --- Parsing ---------------------------------------------------------

    private function analyser(string $contenu): void
    {
        // Supprimer le BOM UTF-8
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }

        // Tronquer a 500 Kio selon la specification Google
        if (strlen($contenu) > 512_000) {
            $contenu = substr($contenu, 0, 512_000);
        }

        // Separer en lignes (CR, LF, ou CR/LF)
        /** @var string[] $lignes */
        $lignes = preg_split('/\r\n|\r|\n/', $contenu);

        $groupeActuel = null;
        $dernierTypeDirective = null; // 'ua' ou 'regle'

        foreach ($lignes as $index => $ligne) {
            $numeroLigne = $index + 1;

            // Supprimer les commentaires
            $posCommentaire = strpos($ligne, '#');
            if ($posCommentaire !== false) {
                $ligne = substr($ligne, 0, $posCommentaire);
            }

            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            // Parser la directive (nom: valeur)
            $posDeuxPoints = strpos($ligne, ':');
            if ($posDeuxPoints === false) {
                continue;
            }

            $directive = strtolower(trim(substr($ligne, 0, $posDeuxPoints)));
            $valeur = trim(substr($ligne, $posDeuxPoints + 1));

            switch ($directive) {
                case 'user-agent':
                    // Un nouveau User-agent apres des regles demarre un nouveau groupe
                    if ($dernierTypeDirective === 'regle' || $groupeActuel === null) {
                        $groupeActuel = new GroupeReglesRobots();
                        $this->groupes[] = $groupeActuel;
                    }
                    $groupeActuel->userAgents[] = $valeur;
                    $groupeActuel->lignesUA[] = $numeroLigne;
                    $dernierTypeDirective = 'ua';
                    break;

                case 'allow':
                    if ($groupeActuel !== null) {
                        $groupeActuel->regles[] = new RegleRobots(
                            type: TypeRegleRobots::ALLOW,
                            chemin: $valeur,
                            directiveBrute: "Allow: {$valeur}",
                            numeroLigne: $numeroLigne,
                        );
                        $dernierTypeDirective = 'regle';
                    }
                    break;

                case 'disallow':
                    if ($groupeActuel !== null) {
                        $groupeActuel->regles[] = new RegleRobots(
                            type: TypeRegleRobots::DISALLOW,
                            chemin: $valeur,
                            directiveBrute: "Disallow: {$valeur}",
                            numeroLigne: $numeroLigne,
                        );
                        $dernierTypeDirective = 'regle';
                    }
                    break;

                case 'sitemap':
                    $this->sitemaps[] = $valeur;
                    break;

                default:
                    // Capturer les directives non-standard connues
                    $directivesConnues = ['crawl-delay', 'clean-param', 'host', 'request-rate', 'visit-time', 'noindex'];
                    if (in_array($directive, $directivesConnues, true)) {
                        $this->directivesNonStandard[] = new DirectiveNonStandard(
                            nom: trim(substr($ligne, 0, $posDeuxPoints)),
                            valeur: $valeur,
                            numeroLigne: $numeroLigne,
                        );
                    }
                    break;
            }
        }
    }

    // --- Selection du groupe User-Agent ----------------------------------

    /**
     * Trouve le groupe de regles le plus specifique pour un User-Agent.
     *
     * Algorithme :
     * 1. Normaliser le UA (minuscules, sans version, sans * final)
     * 2. Chercher les groupes dont le UA normalise est un prefixe du UA recherche
     * 3. Garder le(s) groupe(s) avec le UA le plus long (le plus specifique)
     * 4. Fusionner les regles si plusieurs groupes correspondent
     * 5. Fallback sur le groupe * si aucun groupe specifique ne matche
     */
    private function trouverGroupeCorrespondant(string $userAgent): ?GroupeReglesRobots
    {
        $uaNormalise = $this->normaliserUserAgent($userAgent);
        $groupesSpecifiques = [];
        $meilleureLongueur = 0;
        $groupesWildcard = [];

        foreach ($this->groupes as $groupe) {
            foreach ($groupe->userAgents as $ua) {
                $uaGroupe = $this->normaliserUserAgent($ua);

                if ($uaGroupe === '*') {
                    $groupesWildcard[] = $groupe;
                    continue;
                }

                // Le UA recherche commence-t-il par le UA du groupe ?
                if (str_starts_with($uaNormalise, $uaGroupe)) {
                    $longueur = strlen($uaGroupe);
                    if ($longueur > $meilleureLongueur) {
                        $meilleureLongueur = $longueur;
                        $groupesSpecifiques = [$groupe];
                    } elseif ($longueur === $meilleureLongueur) {
                        $groupesSpecifiques[] = $groupe;
                    }
                }
            }
        }

        $groupes = !empty($groupesSpecifiques) ? $groupesSpecifiques : $groupesWildcard;

        if (empty($groupes)) {
            return null;
        }

        // Fusionner les regles de tous les groupes correspondants (spec Google)
        $groupeFusionne = new GroupeReglesRobots();
        $uasVus = [];

        foreach ($groupes as $g) {
            foreach ($g->userAgents as $ua) {
                $uaMin = strtolower($ua);
                if (!in_array($uaMin, $uasVus, true)) {
                    $groupeFusionne->userAgents[] = $ua;
                    $uasVus[] = $uaMin;
                }
            }
            $groupeFusionne->regles = array_merge($groupeFusionne->regles, $g->regles);
            $groupeFusionne->lignesUA = array_merge($groupeFusionne->lignesUA, $g->lignesUA);
        }

        return $groupeFusionne;
    }

    /**
     * Normalise un User-Agent : minuscules, sans version (/x.y), sans * final.
     */
    private function normaliserUserAgent(string $ua): string
    {
        $ua = strtolower(trim($ua));

        // Supprimer la version (/x.y.z)
        $posSlash = strpos($ua, '/');
        if ($posSlash !== false) {
            $ua = substr($ua, 0, $posSlash);
        }

        // Supprimer le * final (googlebot* == googlebot)
        return rtrim($ua, '*');
    }

    // --- Matching de chemin ----------------------------------------------

    /**
     * Extrait le chemin (+ query string) d'une URL pour le matching.
     * Supprime le fragment (#) et gere l'encodage %xx conformement a la spec Google.
     */
    private function extraireChemin(string $url): string
    {
        // Supprimer le fragment (#...) avant tout traitement
        $url = strtok($url, '#') ?: $url;

        // Si c'est deja un chemin (commence par /)
        if (str_starts_with($url, '/')) {
            return $this->normaliserEncodageChemin($url);
        }

        $parties = parse_url($url);
        $chemin = $parties['path'] ?? '/';

        if (isset($parties['query'])) {
            $chemin .= '?' . $parties['query'];
        }

        $chemin = $chemin !== '' ? $chemin : '/';

        return $this->normaliserEncodageChemin($chemin);
    }

    /**
     * Normalise l'encodage percent (%xx) d'un chemin.
     *
     * Decode les caracteres percent-encodes, puis re-encode uniquement
     * ceux qui ne sont pas des caracteres reserves URI (RFC 3986).
     * Cela permet le matching correct entre /path/%7Euser et /path/~user.
     */
    private function normaliserEncodageChemin(string $chemin): string
    {
        // Decoder les sequences %xx
        $decode = rawurldecode($chemin);

        // Re-encoder uniquement les caracteres non-reserves
        // Les caracteres reserves URI restent tels quels : / ? & = % # + @ : ; , ! $ ' ( ) *
        $resultat = '';
        $longueur = strlen($decode);

        for ($i = 0; $i < $longueur; $i++) {
            $c = $decode[$i];
            $ord = ord($c);

            // Caracteres non encodes : lettres, chiffres, et caracteres reserves/speciaux
            if (($ord >= 0x41 && $ord <= 0x5A)    // A-Z
                || ($ord >= 0x61 && $ord <= 0x7A)  // a-z
                || ($ord >= 0x30 && $ord <= 0x39)  // 0-9
                || strpos('-._~/!$&\'()*+,;=:@?%#', $c) !== false
            ) {
                $resultat .= $c;
            } else {
                $resultat .= rawurlencode($c);
            }
        }

        return $resultat;
    }

    /**
     * Verifie si un chemin correspond a un motif robots.txt.
     *
     * Le matching est sensible a la casse et base sur les prefixes.
     * Supporte les wildcards * (0+ caracteres) et l'ancre $ (fin d'URL).
     * Le motif est normalise (encodage %xx) avant comparaison.
     */
    private function cheminCorrespond(string $chemin, string $motif): bool
    {
        // Normaliser le motif en preservant les wildcards * et l'ancre $
        $motifNormalise = $this->normaliserMotif($motif);
        $regex = $this->convertirEnRegex($motifNormalise);

        return (bool) preg_match($regex, $chemin);
    }

    /**
     * Normalise l'encodage d'un motif robots.txt en preservant * et $.
     */
    private function normaliserMotif(string $motif): string
    {
        // Extraire l'ancre $ finale
        $ancreFinale = str_ends_with($motif, '$');
        if ($ancreFinale) {
            $motif = substr($motif, 0, -1);
        }

        // Separer autour des * pour preserver les wildcards
        $parties = explode('*', $motif);
        $partiesNormalisees = array_map(
            fn(string $p): string => $this->normaliserEncodageChemin($p),
            $parties
        );

        $resultat = implode('*', $partiesNormalisees);

        if ($ancreFinale) {
            $resultat .= '$';
        }

        return $resultat;
    }

    /**
     * Convertit un motif robots.txt en expression reguliere.
     *
     * - * -> .* (zero ou plusieurs caracteres quelconques)
     * - $ en fin de motif -> $ (ancre de fin)
     * - Le reste est un matching par prefixe (pas de $ implicite)
     */
    private function convertirEnRegex(string $motif): string
    {
        if ($motif === '') {
            return '#(?!)#'; // Ne matche jamais
        }

        // Verifier l'ancre de fin $
        $ancreFinale = str_ends_with($motif, '$');
        if ($ancreFinale) {
            $motif = substr($motif, 0, -1);
        }

        // Reduire les wildcards consecutifs (*** -> *) pour eviter le ReDoS
        $motif = preg_replace('/\*+/', '*', $motif);

        // Separer autour des * pour gerer les wildcards
        $parties = explode('*', $motif);
        $regex = '';

        foreach ($parties as $i => $partie) {
            $regex .= preg_quote($partie, '#');
            if ($i < count($parties) - 1) {
                $regex .= '.*';
            }
        }

        return '#^' . $regex . ($ancreFinale ? '$' : '') . '#u';
    }
}
