<?php

declare(strict_types=1);

namespace SiteMonitor\Indexation;

use SiteMonitor\Core\StatutIndexation;
use SiteMonitor\Core\TypeContradiction;

/**
 * Detecte les contradictions entre les signaux d'indexation d'une URL.
 *
 * Prend tous les signaux et retourne le statut d'indexation et la liste
 * des contradictions detectees.
 */
final class DetecteurContradictions
{
    /**
     * Analyse les signaux d'une URL et retourne le statut + contradictions.
     *
     * @return array{statut: StatutIndexation, contradictions: TypeContradiction[]}
     */
    public function analyser(
        int $codeHttp,
        bool $estNoindex,
        ?string $canonical,
        bool $canonicalAutoReference,
        bool $robotsTxtAutorise,
        bool $presentSitemap,
    ): array {
        $contradictions = [];

        $estRedirection = $codeHttp >= 300 && $codeHttp < 400;
        $estErreur = $codeHttp >= 400 || $codeHttp === 0;
        $estOk = $codeHttp >= 200 && $codeHttp < 300;
        $aCanonicalAutre = $canonical !== null && !$canonicalAutoReference;

        // Regle 1 : Sitemap + noindex (CRITIQUE)
        if ($presentSitemap && $estNoindex) {
            $contradictions[] = TypeContradiction::SitemapPlusNoindex;
        }

        // Regle 2 : Robots.txt bloque + sitemap (CRITIQUE)
        if (!$robotsTxtAutorise && $presentSitemap) {
            $contradictions[] = TypeContradiction::RobotsBloquePlusSitemap;
        }

        // Regle 3 : Canonical autre + sitemap (ATTENTION)
        if ($aCanonicalAutre && $presentSitemap) {
            $contradictions[] = TypeContradiction::CanonicalAutrePlusSitemap;
        }

        // Regle 4 : Redirection + sitemap (ATTENTION)
        if ($estRedirection && $presentSitemap) {
            $contradictions[] = TypeContradiction::RedirectionPlusSitemap;
        }

        // Regle 5 : Erreur HTTP + sitemap (CRITIQUE)
        if ($estErreur && $presentSitemap) {
            $contradictions[] = TypeContradiction::ErreurPlusSitemap;
        }

        // Regle 6 : noindex + canonical auto-ref (INFO)
        if ($estNoindex && $canonicalAutoReference) {
            $contradictions[] = TypeContradiction::NoindexPlusCanonicalSelf;
        }

        // Regle 7 : Double blocage robots.txt + noindex (INFO)
        if (!$robotsTxtAutorise && $estNoindex) {
            $contradictions[] = TypeContradiction::DoubleBlocage;
        }

        // Regle 8 : Indexable mais hors sitemap (ATTENTION)
        $estIndexable = $estOk && !$estNoindex && $robotsTxtAutorise && !$aCanonicalAutre;
        if ($estIndexable && !$presentSitemap) {
            $contradictions[] = TypeContradiction::IndexableHorsSitemap;
        }

        // Determiner le statut global
        $statut = $this->determinerStatut($codeHttp, $estNoindex, $robotsTxtAutorise, $aCanonicalAutre, $contradictions);

        return [
            'statut' => $statut,
            'contradictions' => $contradictions,
        ];
    }

    /**
     * @param TypeContradiction[] $contradictions
     */
    private function determinerStatut(
        int $codeHttp,
        bool $estNoindex,
        bool $robotsTxtAutorise,
        bool $aCanonicalAutre,
        array $contradictions,
    ): StatutIndexation {
        // Erreur HTTP → statut erreur
        if ($codeHttp >= 400 || $codeHttp === 0) {
            return $contradictions !== [] ? StatutIndexation::Contradictoire : StatutIndexation::Erreur;
        }

        // Contradictions detectees
        if ($contradictions !== []) {
            // Si uniquement des contradictions INFO, pas forcément "contradictoire"
            $aSevereCritique = false;
            $aSeveAttention = false;
            foreach ($contradictions as $c) {
                if ($c->severite() === 'critique') {
                    $aSevereCritique = true;
                }
                if ($c->severite() === 'attention') {
                    $aSeveAttention = true;
                }
            }
            if ($aSevereCritique || $aSeveAttention) {
                return StatutIndexation::Contradictoire;
            }
        }

        // Non indexable (noindex, robots bloque, canonical autre, redirection)
        if ($estNoindex || !$robotsTxtAutorise || $aCanonicalAutre || ($codeHttp >= 300 && $codeHttp < 400)) {
            return StatutIndexation::NonIndexable;
        }

        return StatutIndexation::Indexable;
    }
}
