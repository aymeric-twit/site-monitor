<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur du fichier ads.txt.
 *
 * Controle l'accessibilite du fichier /ads.txt et valide la syntaxe
 * des enregistrements (format IAB : domaine, identifiant, relation, autorite).
 */
final class VerificateurAdsTxt implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'ads_txt';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $severite = $regle->severite;
        $verification = $config['verification'] ?? 'accessible';

        return match ($verification) {
            'accessible' => $this->verifierAccessible($contexte, $severite),
            'syntaxe_valide' => $this->verifierSyntaxeValide($contexte, $severite),
            default => ResultatVerification::echec(
                severite: NiveauSeverite::Erreur,
                message: "Type de verification ads.txt inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie que le ads.txt est accessible (code 200) et non vide.
     */
    private function verifierAccessible(ContexteVerification $contexte, NiveauSeverite $severite): ResultatVerification
    {
        if ($contexte->codeHttp !== 200) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "Le ads.txt n'est pas accessible (code HTTP {$contexte->codeHttp})",
                valeurAttendue: '200',
                valeurObtenue: (string) $contexte->codeHttp,
            );
        }

        $contenu = trim($contexte->corpsReponse);

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Le ads.txt est vide',
                valeurAttendue: 'contenu non vide',
                valeurObtenue: 'fichier vide',
            );
        }

        $nombreLignes = count(explode("\n", $contenu));

        return ResultatVerification::succes(
            message: "Le ads.txt est accessible ({$nombreLignes} ligne(s))",
            valeurObtenue: "{$nombreLignes} lignes",
            details: [
                'taille_octets' => strlen($contenu),
                'nombre_lignes' => $nombreLignes,
            ],
        );
    }

    /**
     * Valide la syntaxe du fichier ads.txt selon le format IAB.
     *
     * Format attendu par ligne : domaine, identifiant_compte, relation[, id_autorite]
     * Relation : DIRECT ou RESELLER
     * Les commentaires (#) et les variables (contact=, subdomain=) sont acceptes.
     */
    private function verifierSyntaxeValide(
        ContexteVerification $contexte,
        NiveauSeverite $severite,
    ): ResultatVerification {
        $contenu = $contexte->corpsReponse;
        $lignes = explode("\n", str_replace("\r\n", "\n", $contenu));

        $enregistrementsValides = 0;
        $enregistrementsInvalides = 0;
        $variables = 0;
        $commentaires = 0;
        $lignesVides = 0;
        $erreurs = [];

        foreach ($lignes as $numero => $ligne) {
            $ligne = trim($ligne);
            $numeroLigne = $numero + 1;

            // Ligne vide
            if ($ligne === '') {
                $lignesVides++;
                continue;
            }

            // Commentaire
            if (str_starts_with($ligne, '#')) {
                $commentaires++;
                continue;
            }

            // Supprimer les commentaires en fin de ligne
            $positionCommentaire = strpos($ligne, '#');
            if ($positionCommentaire !== false) {
                $ligne = trim(substr($ligne, 0, $positionCommentaire));
            }

            // Variable (contact=, subdomain=)
            if (preg_match('/^(contact|subdomain)\s*=\s*.+$/i', $ligne)) {
                $variables++;
                continue;
            }

            // Enregistrement ads.txt : domaine, identifiant, relation[, autorite]
            $champs = array_map('trim', explode(',', $ligne));

            if (count($champs) < 3 || count($champs) > 4) {
                $enregistrementsInvalides++;
                $erreurs[] = "Ligne {$numeroLigne} : nombre de champs incorrect (" . count($champs) . ')';
                continue;
            }

            $domaine = $champs[0];
            $relation = strtoupper($champs[2]);

            // Valider le domaine (format basique)
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domaine)) {
                $enregistrementsInvalides++;
                $erreurs[] = "Ligne {$numeroLigne} : domaine invalide '{$domaine}'";
                continue;
            }

            // Valider la relation
            if ($relation !== 'DIRECT' && $relation !== 'RESELLER') {
                $enregistrementsInvalides++;
                $erreurs[] = "Ligne {$numeroLigne} : relation invalide '{$relation}' (attendu DIRECT ou RESELLER)";
                continue;
            }

            $enregistrementsValides++;
        }

        $totalEnregistrements = $enregistrementsValides + $enregistrementsInvalides;

        if ($totalEnregistrements === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun enregistrement ads.txt trouve dans le fichier',
                valeurAttendue: '>= 1 enregistrement',
                valeurObtenue: '0',
                details: [
                    'commentaires' => $commentaires,
                    'variables' => $variables,
                ],
            );
        }

        if ($enregistrementsInvalides > 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: "{$enregistrementsInvalides}/{$totalEnregistrements} enregistrement(s) avec une syntaxe invalide",
                valeurAttendue: '0 erreur de syntaxe',
                valeurObtenue: "{$enregistrementsInvalides} erreur(s)",
                details: [
                    'enregistrements_valides' => $enregistrementsValides,
                    'enregistrements_invalides' => $enregistrementsInvalides,
                    'erreurs' => array_slice($erreurs, 0, 20),
                    'variables' => $variables,
                    'commentaires' => $commentaires,
                ],
            );
        }

        return ResultatVerification::succes(
            message: "Syntaxe ads.txt valide ({$enregistrementsValides} enregistrement(s))",
            valeurObtenue: "{$enregistrementsValides} enregistrement(s)",
            details: [
                'enregistrements_valides' => $enregistrementsValides,
                'variables' => $variables,
                'commentaires' => $commentaires,
            ],
        );
    }
}
