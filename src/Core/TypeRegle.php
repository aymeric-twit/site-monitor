<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

/**
 * Types de regles de verification disponibles.
 */
enum TypeRegle: string
{
    case CodeHttp = 'code_http';
    case EnTeteHttp = 'en_tete_http';
    case Performance = 'performance';
    case XPath = 'xpath';
    case BaliseHtml = 'balise_html';
    case MetaSeo = 'meta_seo';
    case OpenGraph = 'open_graph';
    case TwitterCard = 'twitter_card';
    case StructureTitres = 'structure_titres';
    case DonneesStructurees = 'donnees_structurees';
    case LiensSeo = 'liens_seo';
    case ImagesSeo = 'images_seo';
    case PerformanceFront = 'performance_front';
    case RobotsTxt = 'robots_txt';
    case SitemapXml = 'sitemap_xml';
    case SecurityTxt = 'security_txt';
    case AdsTxt = 'ads_txt';
    case Favicon = 'favicon';
    case Ssl = 'ssl';
    case ChangementContenu = 'changement_contenu';
    case ComptageOccurrences = 'comptage_occurrences';
    case RessourcesExternes = 'ressources_externes';
    case Disponibilite = 'disponibilite';

    public function libelle(): string
    {
        return match ($this) {
            self::CodeHttp => 'Code de reponse HTTP',
            self::EnTeteHttp => 'En-tetes HTTP',
            self::Performance => 'Temps de reponse',
            self::XPath => 'Verification XPath',
            self::BaliseHtml => 'Balises HTML',
            self::MetaSeo => 'Meta SEO',
            self::OpenGraph => 'Open Graph',
            self::TwitterCard => 'Twitter Card',
            self::StructureTitres => 'Structure des titres (Hn)',
            self::DonneesStructurees => 'Donnees structurees',
            self::LiensSeo => 'Liens SEO',
            self::ImagesSeo => 'Images SEO',
            self::PerformanceFront => 'Performance front',
            self::RobotsTxt => 'Robots.txt',
            self::SitemapXml => 'Sitemap XML',
            self::SecurityTxt => 'Security.txt',
            self::AdsTxt => 'Ads.txt',
            self::Favicon => 'Favicon',
            self::Ssl => 'Certificat SSL/TLS',
            self::ChangementContenu => 'Changement de contenu',
            self::ComptageOccurrences => 'Comptage d\'occurrences',
            self::RessourcesExternes => 'Ressources externes',
            self::Disponibilite => 'Disponibilite',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::CodeHttp => 'bi-globe',
            self::EnTeteHttp => 'bi-list-check',
            self::Performance => 'bi-speedometer2',
            self::XPath => 'bi-code-slash',
            self::BaliseHtml => 'bi-tags',
            self::MetaSeo => 'bi-search',
            self::OpenGraph => 'bi-share',
            self::TwitterCard => 'bi-twitter',
            self::StructureTitres => 'bi-list-ol',
            self::DonneesStructurees => 'bi-braces',
            self::LiensSeo => 'bi-link-45deg',
            self::ImagesSeo => 'bi-image',
            self::PerformanceFront => 'bi-lightning',
            self::RobotsTxt => 'bi-robot',
            self::SitemapXml => 'bi-diagram-3',
            self::SecurityTxt => 'bi-shield-lock',
            self::AdsTxt => 'bi-megaphone',
            self::Favicon => 'bi-star',
            self::Ssl => 'bi-lock',
            self::ChangementContenu => 'bi-file-diff',
            self::ComptageOccurrences => 'bi-hash',
            self::RessourcesExternes => 'bi-box-arrow-up-right',
            self::Disponibilite => 'bi-heart-pulse',
        };
    }

    public function categorie(): string
    {
        return match ($this) {
            self::CodeHttp, self::EnTeteHttp, self::Performance, self::Ssl, self::Disponibilite => 'technique',
            self::MetaSeo, self::OpenGraph, self::TwitterCard, self::StructureTitres,
            self::DonneesStructurees, self::LiensSeo, self::ImagesSeo, self::PerformanceFront => 'seo',
            self::RobotsTxt, self::SitemapXml, self::SecurityTxt, self::AdsTxt, self::Favicon => 'fichiers',
            self::XPath, self::BaliseHtml, self::ChangementContenu,
            self::ComptageOccurrences, self::RessourcesExternes => 'contenu',
        };
    }
}
