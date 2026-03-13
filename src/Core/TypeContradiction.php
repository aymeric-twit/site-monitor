<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

enum TypeContradiction: string
{
    case SitemapPlusNoindex = 'sitemap_plus_noindex';
    case RobotsBloquePlusSitemap = 'robots_bloque_plus_sitemap';
    case CanonicalAutrePlusSitemap = 'canonical_autre_plus_sitemap';
    case RedirectionPlusSitemap = 'redirection_plus_sitemap';
    case ErreurPlusSitemap = 'erreur_plus_sitemap';
    case NoindexPlusCanonicalSelf = 'noindex_plus_canonical_self';
    case DoubleBlocage = 'double_blocage';
    case IndexableHorsSitemap = 'indexable_hors_sitemap';

    public function severite(): string
    {
        return match ($this) {
            self::SitemapPlusNoindex,
            self::RobotsBloquePlusSitemap,
            self::ErreurPlusSitemap => 'critique',
            self::CanonicalAutrePlusSitemap,
            self::RedirectionPlusSitemap,
            self::IndexableHorsSitemap => 'attention',
            self::NoindexPlusCanonicalSelf,
            self::DoubleBlocage => 'info',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::SitemapPlusNoindex => 'URL dans le sitemap mais contient une directive noindex',
            self::RobotsBloquePlusSitemap => 'URL bloquée par robots.txt mais présente dans le sitemap',
            self::CanonicalAutrePlusSitemap => 'URL dans le sitemap mais le canonical pointe vers une autre page',
            self::RedirectionPlusSitemap => 'URL dans le sitemap mais redirige (301/302)',
            self::ErreurPlusSitemap => 'URL dans le sitemap mais retourne une erreur (404/410/5xx)',
            self::NoindexPlusCanonicalSelf => 'URL avec noindex et canonical auto-référent (canonical inutile)',
            self::DoubleBlocage => 'URL bloquée par robots.txt et contient noindex (double blocage)',
            self::IndexableHorsSitemap => 'URL indexable mais absente du sitemap',
        };
    }
}
