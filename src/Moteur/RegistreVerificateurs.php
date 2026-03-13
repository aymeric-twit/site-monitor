<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Regle\InterfaceVerificateur;

/**
 * Registre des verificateurs de regles.
 *
 * Permet d'enregistrer et recuperer les verificateurs par type de regle.
 * Facilite l'ajout de nouveaux types sans modifier le moteur.
 */
final class RegistreVerificateurs
{
    /** @var array<string, InterfaceVerificateur> */
    private array $verificateurs = [];

    public function enregistrer(InterfaceVerificateur $verificateur): self
    {
        $this->verificateurs[$verificateur->typeGere()] = $verificateur;
        return $this;
    }

    public function obtenir(string $typeRegle): ?InterfaceVerificateur
    {
        return $this->verificateurs[$typeRegle] ?? null;
    }

    public function existe(string $typeRegle): bool
    {
        return isset($this->verificateurs[$typeRegle]);
    }

    /**
     * @return string[]
     */
    public function typesDisponibles(): array
    {
        return array_keys($this->verificateurs);
    }

    /**
     * Enregistre tous les verificateurs par defaut.
     */
    public static function parDefaut(): self
    {
        $registre = new self();

        $classes = [
            \SiteMonitor\Regle\VerificateurCodeHttp::class,
            \SiteMonitor\Regle\VerificateurEnTeteHttp::class,
            \SiteMonitor\Regle\VerificateurPerformance::class,
            \SiteMonitor\Regle\VerificateurXPath::class,
            \SiteMonitor\Regle\VerificateurBaliseHtml::class,
            \SiteMonitor\Regle\VerificateurMetaSeo::class,
            \SiteMonitor\Regle\VerificateurOpenGraph::class,
            \SiteMonitor\Regle\VerificateurTwitterCard::class,
            \SiteMonitor\Regle\VerificateurStructureTitres::class,
            \SiteMonitor\Regle\VerificateurDonneesStructurees::class,
            \SiteMonitor\Regle\VerificateurLiensSeo::class,
            \SiteMonitor\Regle\VerificateurImagesSeo::class,
            \SiteMonitor\Regle\VerificateurPerformanceFront::class,
            \SiteMonitor\Regle\VerificateurRobotsTxt::class,
            \SiteMonitor\Regle\VerificateurSitemapXml::class,
            \SiteMonitor\Regle\VerificateurSecurityTxt::class,
            \SiteMonitor\Regle\VerificateurAdsTxt::class,
            \SiteMonitor\Regle\VerificateurFavicon::class,
            \SiteMonitor\Regle\VerificateurSsl::class,
            \SiteMonitor\Regle\VerificateurChangementContenu::class,
            \SiteMonitor\Regle\VerificateurComptageOccurrences::class,
            \SiteMonitor\Regle\VerificateurRessourcesExternes::class,
            \SiteMonitor\Regle\VerificateurDisponibilite::class,
        ];

        foreach ($classes as $classe) {
            if (class_exists($classe)) {
                $registre->enregistrer(new $classe());
            }
        }

        return $registre;
    }
}
