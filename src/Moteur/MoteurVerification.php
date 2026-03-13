<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Core\StatutExecution;
use SiteMonitor\Core\TypeRegle;
use SiteMonitor\Entite\MetriqueHttp;
use SiteMonitor\Entite\Regle;
use SiteMonitor\Entite\ResultatRegle;
use SiteMonitor\Entite\Snapshot;
use SiteMonitor\Entite\Url;
use SiteMonitor\Regle\ContexteVerification;
use SiteMonitor\Stockage\DepotExecution;
use SiteMonitor\Stockage\DepotMetriqueHttp;
use SiteMonitor\Stockage\DepotRegle;
use SiteMonitor\Stockage\DepotResultatRegle;
use SiteMonitor\Stockage\DepotSnapshot;
use SiteMonitor\Stockage\DepotUrl;

/**
 * Moteur principal d'execution des verifications.
 *
 * Orchestre la recuperation HTTP, l'application des regles,
 * le stockage des resultats, des snapshots, des metriques
 * et la mise a jour de la progression.
 */
final class MoteurVerification
{
    /** @var ?callable(int, int, string): void */
    private ?\Closure $callbackProgression = null;

    public function __construct(
        private readonly RegistreVerificateurs $registre,
        private readonly ClientHttp $clientHttp,
        private readonly DepotUrl $depotUrl,
        private readonly DepotRegle $depotRegle,
        private readonly DepotExecution $depotExecution,
        private readonly DepotResultatRegle $depotResultat,
        private readonly DepotSnapshot $depotSnapshot,
        private readonly DepotMetriqueHttp $depotMetrique,
    ) {}

    /**
     * Definit un callback appele a chaque URL traitee.
     *
     * @param callable(int $urlsTraitees, int $urlsTotal, string $urlCourante): void $callback
     */
    public function surProgression(\Closure $callback): self
    {
        $this->callbackProgression = $callback;
        return $this;
    }

    /**
     * Execute les verifications pour un ensemble d'URLs.
     *
     * @param Url[] $urls
     * @param int $executionId ID de l'execution en cours
     * @param int $delaiEntreRequetesMs Delai entre les requetes (throttle)
     */
    public function executer(array $urls, int $executionId, int $delaiEntreRequetesMs = 1000): void
    {
        $tempsDebut = hrtime(true);
        $totalSucces = 0;
        $totalEchecs = 0;
        $totalAvertissements = 0;
        $totalRegles = 0;
        $urlsTraitees = 0;

        foreach ($urls as $url) {
            if (!$url->actif) {
                continue;
            }

            // Recuperer les regles associees a cette URL
            $regles = $this->depotRegle->trouverActivesParUrl($url->id);
            if (empty($regles)) {
                $urlsTraitees++;
                continue;
            }

            // Effectuer la requete HTTP
            $contexte = $this->clientHttp->recuperer($url->url);

            // Persister les metriques HTTP
            $metrique = MetriqueHttp::depuisContexte($contexte, $executionId, $url->id);
            $this->depotMetrique->creer($metrique);

            // Persister le snapshot HTML (gzip-compresse)
            $snapshotId = $this->persisterSnapshot($contexte, $executionId, $url->id);

            // Injecter la baseline dans les regles changement_contenu
            $regles = $this->injecterBaselines($regles, $url->id);

            // Appliquer chaque regle
            $resultatsLot = [];
            $resultatsVerification = [];
            foreach ($regles as $regle) {
                $verificateur = $this->registre->obtenir($regle->typeRegle->value);
                if ($verificateur === null) {
                    continue;
                }

                try {
                    $resultat = $verificateur->verifier($regle, $contexte);
                } catch (\Throwable $e) {
                    $resultat = ResultatVerification::echec(
                        severite: NiveauSeverite::Erreur,
                        message: 'Erreur lors de la verification : ' . $e->getMessage(),
                    );
                }

                $totalRegles++;

                if ($resultat->succes) {
                    $totalSucces++;
                } elseif ($resultat->severite === NiveauSeverite::Avertissement) {
                    $totalAvertissements++;
                } else {
                    $totalEchecs++;
                }

                $resultatsLot[] = new ResultatRegle(
                    id: null,
                    executionId: $executionId,
                    urlId: $url->id,
                    regleId: $regle->id,
                    succes: $resultat->succes,
                    severite: $resultat->severite,
                    valeurAttendue: $resultat->valeurAttendue,
                    valeurObtenue: $resultat->valeurObtenue,
                    message: $resultat->message,
                    dureeMs: $resultat->dureeMs,
                    detailsJson: !empty($resultat->details)
                        ? json_encode($resultat->details, JSON_UNESCAPED_UNICODE)
                        : null,
                    verifieLe: null,
                );

                // Garder le lien regle → resultat pour la mise a jour des baselines
                if ($regle->typeRegle === TypeRegle::ChangementContenu) {
                    $resultatsVerification[] = [
                        'regle' => $regle,
                        'resultat' => $resultat,
                    ];
                }
            }

            // Stocker les resultats en lot
            $this->depotResultat->creerEnLot($resultatsLot);

            // Mettre a jour les baselines pour les regles changement_contenu
            if ($snapshotId !== null) {
                $this->mettreAJourBaselines($resultatsVerification, $snapshotId);
            }

            // Mettre a jour le statut de l'URL
            $statutUrl = ($totalEchecs > 0) ? 'echec' : 'succes';
            $this->depotUrl->mettreAJourStatut($url->id, $statutUrl);

            $urlsTraitees++;

            // Mettre a jour la progression de l'execution
            $this->depotExecution->mettreAJourProgression(
                $executionId,
                $urlsTraitees,
                $totalRegles,
                $totalSucces,
                $totalEchecs,
                $totalAvertissements,
            );

            // Callback de progression
            if ($this->callbackProgression !== null) {
                ($this->callbackProgression)($urlsTraitees, count($urls), $url->url);
            }

            // Throttle entre les requetes
            if ($delaiEntreRequetesMs > 0 && $urlsTraitees < count($urls)) {
                usleep($delaiEntreRequetesMs * 1000);
            }
        }

        // Finaliser l'execution
        $dureeMs = (int) ((hrtime(true) - $tempsDebut) / 1_000_000);
        $statut = $totalEchecs > 0 ? StatutExecution::Termine : StatutExecution::Termine;
        $this->depotExecution->terminer($executionId, $statut, $dureeMs);
    }

    /**
     * Persiste le snapshot HTML et auto-definit la baseline si premiere capture.
     */
    private function persisterSnapshot(
        ContexteVerification $contexte,
        int $executionId,
        int $urlId,
    ): ?int {
        if ($contexte->corpsReponse === '') {
            return null;
        }

        $hash = hash('sha256', $contexte->corpsReponse);
        $snapshot = new Snapshot(
            id: null,
            urlId: $urlId,
            executionId: $executionId,
            typeContenu: 'body',
            hashContenu: $hash,
            contenuCompresse: null,
            tailleOctets: strlen($contexte->corpsReponse),
            creeLe: null,
        );
        $snapshotId = $this->depotSnapshot->creer($snapshot, $contexte->corpsReponse);

        // Auto-baseline si premiere capture pour cette URL
        $baselineExistante = $this->depotSnapshot->trouverBaseline($urlId, 'body');
        if ($baselineExistante === null) {
            $this->depotSnapshot->definirBaseline($snapshotId);
        }

        return $snapshotId;
    }

    /**
     * Injecte les donnees de baseline dans les regles changement_contenu.
     *
     * @param Regle[] $regles
     * @return Regle[]
     */
    private function injecterBaselines(array $regles, int $urlId): array
    {
        foreach ($regles as $idx => $regle) {
            if ($regle->typeRegle !== TypeRegle::ChangementContenu) {
                continue;
            }

            $zone = $regle->configuration['zone'] ?? 'body';
            $baseline = $this->depotSnapshot->trouverBaseline($urlId, $zone);

            if ($baseline === null) {
                continue;
            }

            $config = $regle->configuration;
            $config['_hash_precedent'] = $baseline->hashContenu;

            $contenuPrecedent = $this->depotSnapshot->lireContenu($baseline->id);
            if ($contenuPrecedent !== null) {
                $config['_contenu_precedent'] = $contenuPrecedent;
            }

            // Regle est readonly → reconstruire avec la config modifiee
            $regles[$idx] = new Regle(
                id: $regle->id,
                modeleId: $regle->modeleId,
                typeRegle: $regle->typeRegle,
                nom: $regle->nom,
                configuration: $config,
                severite: $regle->severite,
                ordreTri: $regle->ordreTri,
                actif: $regle->actif,
                creeLe: $regle->creeLe,
                modifieLe: $regle->modifieLe,
            );
        }

        return $regles;
    }

    /**
     * Met a jour les baselines apres les verifications changement_contenu.
     *
     * - Premier passage : baseline deja definie dans persisterSnapshot()
     * - Changement acceptable (succes) : mettre a jour la baseline
     * - Changement au-dela du seuil (echec) : ne pas toucher la baseline
     *
     * @param array<array{regle: Regle, resultat: ResultatVerification}> $resultats
     */
    private function mettreAJourBaselines(array $resultats, int $snapshotId): void
    {
        foreach ($resultats as $item) {
            $resultat = $item['resultat'];
            $details = $resultat->details;

            // Premier passage : la baseline est deja definie
            if (!empty($details['premier_passage'])) {
                continue;
            }

            // Changement dans le seuil acceptable → mettre a jour la baseline
            if ($resultat->succes && !empty($details['hash']) && empty($details['identique'])) {
                $this->depotSnapshot->definirBaseline($snapshotId);
            }
        }
    }
}
