<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Verificateur de la structure des titres Hn (h1 a h6).
 *
 * Controle l'unicite du h1, la hierarchie sans sauts de niveaux,
 * le comptage des titres et le contenu du h1.
 */
final class VerificateurStructureTitres implements InterfaceVerificateur
{
    #[\Override]
    public function typeGere(): string
    {
        return 'structure_titres';
    }

    #[\Override]
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification
    {
        $config = $regle->configuration;
        $verification = $config['verification'] ?? '';
        $severite = $regle->severite;

        $xpath = $contexte->xpath();
        if ($xpath === null) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Impossible de parser le DOM de la page',
            );
        }

        return match ($verification) {
            'h1_unique' => $this->verifierH1Unique($xpath, $severite),
            'hierarchie' => $this->verifierHierarchie($xpath, $severite),
            'comptage' => $this->verifierComptage($xpath, $severite),
            'contenu_h1' => $this->verifierContenuH1($xpath, $config, $severite),
            default => ResultatVerification::echec(
                severite: $severite,
                message: "Type de verification titres inconnu : {$verification}",
            ),
        };
    }

    /**
     * Verifie qu'il y a exactement un seul h1 dans la page.
     */
    private function verifierH1Unique(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//h1');
        $nombre = $noeuds !== false ? $noeuds->length : 0;

        if ($nombre === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun h1 detecte sur la page',
                valeurAttendue: '1',
                valeurObtenue: '0',
            );
        }

        if ($nombre > 1) {
            $contenus = [];
            for ($i = 0; $i < $noeuds->length; $i++) {
                $contenus[] = trim($noeuds->item($i)?->textContent ?? '');
            }

            return ResultatVerification::echec(
                severite: $severite,
                message: "{$nombre} balises h1 detectees (une seule recommandee)",
                valeurAttendue: '1',
                valeurObtenue: (string) $nombre,
                details: ['contenus_h1' => $contenus],
            );
        }

        $contenu = trim($noeuds->item(0)?->textContent ?? '');

        return ResultatVerification::succes(
            message: 'H1 unique conforme',
            valeurObtenue: $contenu,
        );
    }

    /**
     * Verifie la hierarchie des titres : pas de saut de niveau (h1 -> h3 sans h2).
     */
    private function verifierHierarchie(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $titres = $this->extraireTitres($xpath);

        if (empty($titres)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun titre detecte sur la page',
                valeurAttendue: 'Au moins un titre Hn',
                valeurObtenue: '0 titre',
            );
        }

        $sauts = [];
        $niveauPrecedent = 0;

        foreach ($titres as $titre) {
            $niveau = $titre['niveau'];

            // Le premier titre devrait etre un h1
            if ($niveauPrecedent === 0 && $niveau !== 1) {
                $sauts[] = "Premier titre est un h{$niveau} au lieu de h1";
            }

            // Detection des sauts de niveaux (h1 -> h3 sans h2 intermediaire)
            if ($niveauPrecedent > 0 && $niveau > $niveauPrecedent + 1) {
                $sauts[] = "Saut de h{$niveauPrecedent} a h{$niveau} (h" . ($niveauPrecedent + 1) . " manquant)";
            }

            $niveauPrecedent = $niveau;
        }

        if (!empty($sauts)) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Hierarchie des titres incorrecte : ' . implode(' ; ', $sauts),
                valeurObtenue: $this->formaterArborescence($titres),
                details: ['sauts' => $sauts, 'titres' => $titres],
            );
        }

        return ResultatVerification::succes(
            message: sprintf('Hierarchie des titres conforme (%d titres)', count($titres)),
            valeurObtenue: $this->formaterArborescence($titres),
            details: ['titres' => $titres],
        );
    }

    /**
     * Compte les titres par niveau (h1 a h6).
     */
    private function verifierComptage(\DOMXPath $xpath, NiveauSeverite $severite): ResultatVerification
    {
        $compteurs = [];
        for ($niveau = 1; $niveau <= 6; $niveau++) {
            $noeuds = $xpath->query("//h{$niveau}");
            $compteurs["h{$niveau}"] = $noeuds !== false ? $noeuds->length : 0;
        }

        $total = array_sum($compteurs);
        $resume = [];
        foreach ($compteurs as $balise => $nombre) {
            if ($nombre > 0) {
                $resume[] = "{$balise}: {$nombre}";
            }
        }

        return ResultatVerification::succes(
            message: sprintf(
                '%d titre(s) detecte(s) : %s',
                $total,
                empty($resume) ? 'aucun' : implode(', ', $resume),
            ),
            valeurObtenue: (string) $total,
            details: ['compteurs' => $compteurs],
        );
    }

    /**
     * Verifie le contenu du h1 par rapport a une valeur attendue.
     *
     * @param array<string, mixed> $config
     */
    private function verifierContenuH1(\DOMXPath $xpath, array $config, NiveauSeverite $severite): ResultatVerification
    {
        $noeuds = $xpath->query('//h1');

        if ($noeuds === false || $noeuds->length === 0) {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'Aucun h1 detecte pour verifier le contenu',
                valeurAttendue: $config['contenu_attendu'] ?? '(non defini)',
                valeurObtenue: 'Absent',
            );
        }

        $contenu = trim($noeuds->item(0)?->textContent ?? '');

        if ($contenu === '') {
            return ResultatVerification::echec(
                severite: $severite,
                message: 'H1 present mais vide',
                valeurAttendue: $config['contenu_attendu'] ?? 'Contenu non vide',
                valeurObtenue: '(vide)',
            );
        }

        if (!empty($config['contenu_attendu'])) {
            $attendu = $config['contenu_attendu'];
            if (mb_stripos($contenu, $attendu) === false) {
                return ResultatVerification::echec(
                    severite: $severite,
                    message: "H1 ne contient pas le texte attendu : \"{$attendu}\"",
                    valeurAttendue: $attendu,
                    valeurObtenue: $contenu,
                );
            }
        }

        return ResultatVerification::succes(
            message: "Contenu du h1 conforme",
            valeurObtenue: $contenu,
        );
    }

    /**
     * Extrait tous les titres (h1-h6) dans l'ordre du document.
     *
     * @return array<int, array{niveau: int, contenu: string}>
     */
    private function extraireTitres(\DOMXPath $xpath): array
    {
        $noeuds = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');

        if ($noeuds === false) {
            return [];
        }

        $titres = [];
        for ($i = 0; $i < $noeuds->length; $i++) {
            $noeud = $noeuds->item($i);
            if ($noeud === null) {
                continue;
            }

            $nom = strtolower($noeud->nodeName);
            $niveau = (int) substr($nom, 1);

            $titres[] = [
                'niveau' => $niveau,
                'contenu' => trim($noeud->textContent),
            ];
        }

        return $titres;
    }

    /**
     * Formate l'arborescence des titres pour l'affichage.
     *
     * @param array<int, array{niveau: int, contenu: string}> $titres
     */
    private function formaterArborescence(array $titres): string
    {
        $lignes = [];
        foreach ($titres as $titre) {
            $indentation = str_repeat('  ', $titre['niveau'] - 1);
            $contenuCourt = mb_strimwidth($titre['contenu'], 0, 60, '...');
            $lignes[] = "{$indentation}h{$titre['niveau']}: {$contenuCourt}";
        }

        return implode("\n", $lignes);
    }
}
