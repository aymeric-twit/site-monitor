<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de changement de contenu.
 *
 * Extrait le contenu d'une zone specifique (body, head, xpath, json-ld, texte visible, regex),
 * calcule un hash et un pourcentage de changement. Ne stocke pas les snapshots directement :
 * retourne le contenu extrait et le hash dans les details pour que le moteur gere le stockage.
 */
final class VerificateurChangementContenu implements InterfaceVerificateur
{
    /** Seuil de taille (en caracteres) pour basculer vers la comparaison ligne par ligne. */
    private const int SEUIL_COMPARAISON_LIGNES = 10000;

    #[\Override]
    public function typeGere(): string
    {
        return 'changement_contenu';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $zone = $config['zone'] ?? 'body';

        // Extraire le contenu de la zone demandee
        $contenuExtrait = $this->extraireContenu($zone, $config, $contexte);

        if ($contenuExtrait === null) {
            return ResultatVerification::echec(
                severite: $regle->severite,
                message: "Impossible d'extraire le contenu de la zone '{$zone}'",
                details: [
                    'zone' => $zone,
                    'contenu_extrait' => null,
                    'hash' => null,
                ],
            );
        }

        $hash = hash('sha256', $contenuExtrait);

        // Calculer le pourcentage de changement par rapport au snapshot precedent
        $contenuPrecedent = $config['_contenu_precedent'] ?? null;
        $hashPrecedent = $config['_hash_precedent'] ?? null;
        $seuilChangement = (float) ($config['seuil_changement_pourcent'] ?? 0.0);
        $alerterSiIdentiqueJours = isset($config['alerter_si_identique_jours'])
            ? (int) $config['alerter_si_identique_jours']
            : null;

        // Pas de snapshot precedent : premier passage
        if ($hashPrecedent === null) {
            return ResultatVerification::succes(
                message: 'Premier passage — snapshot de reference enregistre',
                valeurObtenue: '0',
                details: [
                    'zone' => $zone,
                    'contenu_extrait' => $contenuExtrait,
                    'hash' => $hash,
                    'premier_passage' => true,
                    'taille_contenu' => strlen($contenuExtrait),
                ],
            );
        }

        // Contenu identique
        if ($hash === $hashPrecedent) {
            return ResultatVerification::succes(
                message: 'Aucun changement detecte',
                valeurObtenue: '0',
                details: [
                    'zone' => $zone,
                    'contenu_extrait' => $contenuExtrait,
                    'hash' => $hash,
                    'changement_pourcent' => 0.0,
                    'identique' => true,
                ],
            );
        }

        // Calculer le pourcentage de changement
        $pourcentageChangement = $this->calculerPourcentageChangement(
            $contenuPrecedent ?? '',
            $contenuExtrait,
        );

        $details = [
            'zone' => $zone,
            'contenu_extrait' => $contenuExtrait,
            'hash' => $hash,
            'hash_precedent' => $hashPrecedent,
            'changement_pourcent' => $pourcentageChangement,
            'taille_contenu' => strlen($contenuExtrait),
        ];

        // Si un seuil est defini et que le changement le depasse
        if ($seuilChangement > 0 && $pourcentageChangement > $seuilChangement) {
            return ResultatVerification::echec(
                severite: $regle->severite,
                message: sprintf(
                    'Changement de contenu important detecte : %.1f%% (seuil : %.1f%%)',
                    $pourcentageChangement,
                    $seuilChangement,
                ),
                valeurAttendue: "<= {$seuilChangement}%",
                valeurObtenue: sprintf('%.1f%%', $pourcentageChangement),
                details: $details,
            );
        }

        return ResultatVerification::succes(
            message: sprintf('Changement detecte : %.1f%%', $pourcentageChangement),
            valeurObtenue: sprintf('%.1f', $pourcentageChangement),
            details: $details,
        );
    }

    /**
     * Extrait le contenu de la zone demandee depuis le contexte.
     *
     * @param array<string, mixed> $config
     */
    private function extraireContenu(
        string $zone,
        array $config,
        ContexteVerification $contexte,
    ): ?string {
        return match ($zone) {
            'body' => $this->extraireBody($contexte),
            'head' => $this->extraireHead($contexte),
            'xpath' => $this->extraireXpath($config, $contexte),
            'json_ld' => $this->extraireJsonLd($contexte),
            'texte_visible' => $this->extraireTexteVisible($contexte),
            'regex' => $this->extraireRegex($config, $contexte),
            default => null,
        };
    }

    /**
     * Extrait le contenu de la balise <body>.
     */
    private function extraireBody(ContexteVerification $contexte): ?string
    {
        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return null;
        }

        $body = $xpath->query('//body');
        if ($body === false || $body->length === 0) {
            return null;
        }

        $dom = $contexte->dom();
        if ($dom === null) {
            return null;
        }

        return $dom->saveHTML($body->item(0)) ?: null;
    }

    /**
     * Extrait le contenu de la balise <head>.
     */
    private function extraireHead(ContexteVerification $contexte): ?string
    {
        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return null;
        }

        $head = $xpath->query('//head');
        if ($head === false || $head->length === 0) {
            return null;
        }

        $dom = $contexte->dom();
        if ($dom === null) {
            return null;
        }

        return $dom->saveHTML($head->item(0)) ?: null;
    }

    /**
     * Extrait le contenu via une expression XPath personnalisee.
     *
     * @param array<string, mixed> $config
     */
    private function extraireXpath(array $config, ContexteVerification $contexte): ?string
    {
        $expressionXpath = $config['expression_xpath'] ?? null;

        if ($expressionXpath === null || $expressionXpath === '') {
            return null;
        }

        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return null;
        }

        $resultats = $xpath->query($expressionXpath);
        if ($resultats === false || $resultats->length === 0) {
            return null;
        }

        $dom = $contexte->dom();
        if ($dom === null) {
            return null;
        }

        $contenu = '';
        foreach ($resultats as $noeud) {
            $contenu .= $dom->saveHTML($noeud) . "\n";
        }

        return trim($contenu) !== '' ? trim($contenu) : null;
    }

    /**
     * Extrait les blocs JSON-LD de la page.
     */
    private function extraireJsonLd(ContexteVerification $contexte): ?string
    {
        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return null;
        }

        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($scripts === false || $scripts->length === 0) {
            return null;
        }

        $blocsJsonLd = [];
        foreach ($scripts as $script) {
            $contenu = trim($script->textContent);
            if ($contenu !== '') {
                $blocsJsonLd[] = $contenu;
            }
        }

        if ($blocsJsonLd === []) {
            return null;
        }

        return implode("\n---\n", $blocsJsonLd);
    }

    /**
     * Extrait le texte visible de la page (sans balises HTML ni scripts).
     */
    private function extraireTexteVisible(ContexteVerification $contexte): ?string
    {
        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return null;
        }

        $dom = $contexte->dom();
        if ($dom === null) {
            return null;
        }

        // Supprimer les elements non visibles
        $elementsASupprimer = $xpath->query('//script | //style | //noscript | //template');
        if ($elementsASupprimer !== false) {
            $noeudsASupprimer = [];
            foreach ($elementsASupprimer as $noeud) {
                $noeudsASupprimer[] = $noeud;
            }
            foreach ($noeudsASupprimer as $noeud) {
                $noeud->parentNode?->removeChild($noeud);
            }
        }

        $body = $xpath->query('//body');
        if ($body === false || $body->length === 0) {
            return null;
        }

        $texte = $body->item(0)?->textContent;
        if ($texte === null) {
            return null;
        }

        // Normaliser les espaces
        $texte = preg_replace('/\s+/', ' ', $texte);
        $texte = trim($texte ?? '');

        return $texte !== '' ? $texte : null;
    }

    /**
     * Extrait le contenu correspondant a une expression reguliere.
     *
     * @param array<string, mixed> $config
     */
    private function extraireRegex(array $config, ContexteVerification $contexte): ?string
    {
        $expressionRegex = $config['expression_regex'] ?? null;

        if ($expressionRegex === null || $expressionRegex === '') {
            return null;
        }

        $resultat = preg_match_all($expressionRegex, $contexte->corpsReponse, $correspondances);

        if ($resultat === false || $resultat === 0) {
            return null;
        }

        return implode("\n", $correspondances[0]);
    }

    /**
     * Calcule le pourcentage de changement entre deux contenus.
     */
    private function calculerPourcentageChangement(string $ancien, string $nouveau): float
    {
        if ($ancien === '' && $nouveau === '') {
            return 0.0;
        }

        if ($ancien === '' || $nouveau === '') {
            return 100.0;
        }

        // Pour les petits contenus : utiliser similar_text
        if (strlen($ancien) < self::SEUIL_COMPARAISON_LIGNES && strlen($nouveau) < self::SEUIL_COMPARAISON_LIGNES) {
            similar_text($ancien, $nouveau, $pourcentageSimilarite);
            return round(100.0 - $pourcentageSimilarite, 2);
        }

        // Pour les gros contenus : comparaison ligne par ligne
        return $this->comparaisonLigneParLigne($ancien, $nouveau);
    }

    /**
     * Comparaison ligne par ligne pour les gros contenus.
     * Retourne le pourcentage de lignes modifiees.
     */
    private function comparaisonLigneParLigne(string $ancien, string $nouveau): float
    {
        $lignesAnciennes = explode("\n", $ancien);
        $lignesNouvelles = explode("\n", $nouveau);

        $totalLignes = max(count($lignesAnciennes), count($lignesNouvelles));

        if ($totalLignes === 0) {
            return 0.0;
        }

        $lignesModifiees = 0;

        // Lignes ajoutees
        $ajoutees = array_diff($lignesNouvelles, $lignesAnciennes);
        $lignesModifiees += count($ajoutees);

        // Lignes supprimees
        $supprimees = array_diff($lignesAnciennes, $lignesNouvelles);
        $lignesModifiees += count($supprimees);

        // Eviter de depasser 100%
        $pourcentage = ($lignesModifiees / ($totalLignes * 2)) * 100.0;

        return round(min($pourcentage, 100.0), 2);
    }
}
