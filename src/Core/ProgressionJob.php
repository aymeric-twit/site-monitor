<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Gestion atomique de la progression d'un job worker.
 *
 * Ecrit dans data/jobs/{jobId}/progress.json de facon atomique
 * (ecriture .tmp puis rename) pour eviter les lectures partielles.
 */
final class ProgressionJob
{
    private string $cheminProgression;
    private int $tempsDebut;

    public function __construct(string $dossierJob)
    {
        $this->cheminProgression = $dossierJob . '/progress.json';
        $this->tempsDebut = time();
    }

    /**
     * Ecrit la progression de facon atomique.
     *
     * @param array<string, mixed> $donnees
     */
    public function ecrire(array $donnees): void
    {
        $donnees['elapsed_sec'] = time() - $this->tempsDebut;
        $contenu = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $cheminTemp = $this->cheminProgression . '.tmp';
        file_put_contents($cheminTemp, $contenu, LOCK_EX);
        rename($cheminTemp, $this->cheminProgression);
    }

    /**
     * Met a jour la progression avec un pourcentage et une etape.
     */
    public function avancer(int $pourcentage, string $etape, string $statut = 'running'): void
    {
        $this->ecrire([
            'status' => $statut,
            'percent' => min(100, max(0, $pourcentage)),
            'step' => $etape,
        ]);
    }

    /**
     * Marque le job comme termine.
     *
     * @param array<string, mixed> $resume
     */
    public function terminer(array $resume = []): void
    {
        $this->ecrire([
            'status' => 'done',
            'percent' => 100,
            'step' => 'Termine',
            'resume' => $resume,
        ]);
    }

    /**
     * Marque le job comme en erreur.
     */
    public function erreur(string $message): void
    {
        $this->ecrire([
            'status' => 'error',
            'step' => $message,
            'message' => $message,
        ]);
    }
}
