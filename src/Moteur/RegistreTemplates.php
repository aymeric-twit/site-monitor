<?php

declare(strict_types=1);

namespace SiteMonitor\Moteur;

use SiteMonitor\Core\NiveauSeverite;
use SiteMonitor\Core\TypeRegle;

/**
 * Registre des templates de regles predefinies.
 *
 * Chaque template contient un ensemble de regles pretes a etre
 * appliquees lors de la creation d'un modele de verification.
 */
final class RegistreTemplates
{
    /**
     * @return array<string, array{nom: string, description: string, regles: array}>
     */
    public static function lister(): array
    {
        return [
            'audit_seo' => [
                'nom' => 'Audit SEO standard',
                'description' => '12 regles couvrant les fondamentaux SEO',
                'nb_regles' => 12,
            ],
            'securite' => [
                'nom' => 'Securite & infrastructure',
                'description' => '8 regles securite et infrastructure',
                'nb_regles' => 8,
            ],
            'contenu' => [
                'nom' => 'Contenu & changements',
                'description' => '7 regles suivi de contenu et changements',
                'nb_regles' => 7,
            ],
            'ecommerce' => [
                'nom' => 'E-commerce complet',
                'description' => '10 regles pour sites e-commerce',
                'nb_regles' => 10,
            ],
            'fichiers_techniques' => [
                'nom' => 'Fichiers techniques',
                'description' => '6 regles fichiers techniques (robots, sitemap, favicon)',
                'nb_regles' => 6,
            ],
        ];
    }

    /**
     * Retourne les regles d'un template pret a etre inserees.
     *
     * @return array<int, array{type_regle: TypeRegle, nom: string, configuration: array, severite: NiveauSeverite}>|null
     */
    public static function obtenir(string $cle): ?array
    {
        return match ($cle) {
            'audit_seo' => self::auditSeo(),
            'securite' => self::securite(),
            'contenu' => self::contenu(),
            'ecommerce' => self::ecommerce(),
            'fichiers_techniques' => self::fichiersTechniques(),
            default => null,
        };
    }

    private static function auditSeo(): array
    {
        return [
            ['type_regle' => TypeRegle::CodeHttp, 'nom' => 'HTTP 200', 'configuration' => ['code_attendu' => 200], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::Performance, 'nom' => 'TTFB < 800ms', 'configuration' => ['metrique' => 'ttfb', 'seuil_max' => 800, 'unite' => 'ms'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::Ssl, 'nom' => 'SSL valide', 'configuration' => ['verification' => 'validite'], 'severite' => NiveauSeverite::Critique],
            ['type_regle' => TypeRegle::MetaSeo, 'nom' => 'Title (30-60 car.)', 'configuration' => ['verification' => 'title', 'longueur_min' => 30, 'longueur_max' => 60], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::MetaSeo, 'nom' => 'Meta description (120-160 car.)', 'configuration' => ['verification' => 'meta_description', 'longueur_min' => 120, 'longueur_max' => 160], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::MetaSeo, 'nom' => 'Canonical presente', 'configuration' => ['verification' => 'canonical'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::StructureTitres, 'nom' => 'H1 unique', 'configuration' => ['verification' => 'h1_unique'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::OpenGraph, 'nom' => 'OG complet', 'configuration' => ['verification' => 'og_complet'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::ImagesSeo, 'nom' => 'Images avec alt', 'configuration' => ['verification' => 'alt_manquant'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::RobotsTxt, 'nom' => 'Robots.txt accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::SitemapXml, 'nom' => 'Sitemap XML accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::DonneesStructurees, 'nom' => 'JSON-LD present', 'configuration' => ['verification' => 'presence_json_ld'], 'severite' => NiveauSeverite::Avertissement],
        ];
    }

    private static function securite(): array
    {
        return [
            ['type_regle' => TypeRegle::CodeHttp, 'nom' => 'HTTP 200', 'configuration' => ['code_attendu' => 200], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::Ssl, 'nom' => 'SSL valide', 'configuration' => ['verification' => 'validite'], 'severite' => NiveauSeverite::Critique],
            ['type_regle' => TypeRegle::Ssl, 'nom' => 'SSL expire dans +30j', 'configuration' => ['verification' => 'expiration', 'jours_avant_expiration' => 30], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::EnTeteHttp, 'nom' => 'X-Frame-Options present', 'configuration' => ['nom_entete' => 'X-Frame-Options', 'operation' => 'present'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::EnTeteHttp, 'nom' => 'CSP present', 'configuration' => ['nom_entete' => 'Content-Security-Policy', 'operation' => 'present'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::EnTeteHttp, 'nom' => 'X-Content-Type-Options nosniff', 'configuration' => ['nom_entete' => 'X-Content-Type-Options', 'operation' => 'egal', 'valeur_attendue' => 'nosniff'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::SecurityTxt, 'nom' => 'Security.txt accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::Disponibilite, 'nom' => 'Detection soft 404', 'configuration' => ['detecter_soft_404' => true], 'severite' => NiveauSeverite::Erreur],
        ];
    }

    private static function contenu(): array
    {
        return [
            ['type_regle' => TypeRegle::ChangementContenu, 'nom' => 'Changement body > 20%', 'configuration' => ['zone' => 'body', 'seuil_changement_pourcent' => 20], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::ChangementContenu, 'nom' => 'Changement main > 10%', 'configuration' => ['zone' => 'xpath', 'expression_xpath' => '//main', 'seuil_changement_pourcent' => 10], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::ComptageOccurrences, 'nom' => 'Mot-cle present (>3)', 'configuration' => ['type_recherche' => 'texte', 'motif' => 'mot-cle', 'operateur' => 'superieur', 'valeur_attendue' => 3], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::BaliseHtml, 'nom' => 'Fil d\'Ariane present', 'configuration' => ['selecteur' => '.breadcrumb', 'operation' => 'existe'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::XPath, 'nom' => 'H1 existe', 'configuration' => ['expression' => '//h1', 'operation' => 'existe'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::RessourcesExternes, 'nom' => 'Analytics present', 'configuration' => ['verification' => 'presence_pattern', 'patterns' => ['analytics.js', 'gtm.js']], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::LiensSeo, 'nom' => 'Liens internes (5-100)', 'configuration' => ['verification' => 'comptage_internes', 'nombre_min' => 5, 'nombre_max' => 100], 'severite' => NiveauSeverite::Info],
        ];
    }

    private static function ecommerce(): array
    {
        return [
            ['type_regle' => TypeRegle::DonneesStructurees, 'nom' => 'Schema Product', 'configuration' => ['verification' => 'type_schema', 'type_attendu' => 'Product'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::DonneesStructurees, 'nom' => 'Champs Product obligatoires', 'configuration' => ['verification' => 'champs_obligatoires', 'type_attendu' => 'Product', 'champs' => ['name', 'price', 'image']], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::MetaSeo, 'nom' => 'Title (30-70 car.)', 'configuration' => ['verification' => 'title', 'longueur_min' => 30, 'longueur_max' => 70], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::OpenGraph, 'nom' => 'OG Image presente', 'configuration' => ['verification' => 'og_image'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::Performance, 'nom' => 'Temps total < 3s', 'configuration' => ['metrique' => 'temps_total', 'seuil_max' => 3000, 'unite' => 'ms'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::BaliseHtml, 'nom' => 'Prix produit present', 'configuration' => ['selecteur' => '.product-price', 'operation' => 'existe'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::AdsTxt, 'nom' => 'Ads.txt accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::Favicon, 'nom' => 'Favicon present', 'configuration' => ['verification' => 'present'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::TwitterCard, 'nom' => 'Twitter Card large image', 'configuration' => ['verification' => 'card_type', 'contenu_attendu' => 'summary_large_image'], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::PerformanceFront, 'nom' => 'CSS bloquants < 3', 'configuration' => ['verification' => 'css_bloquants', 'max_css' => 3], 'severite' => NiveauSeverite::Avertissement],
        ];
    }

    private static function fichiersTechniques(): array
    {
        return [
            ['type_regle' => TypeRegle::RobotsTxt, 'nom' => 'Robots.txt accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::RobotsTxt, 'nom' => 'Sitemap dans robots.txt', 'configuration' => ['verification' => 'sitemap_present'], 'severite' => NiveauSeverite::Avertissement],
            ['type_regle' => TypeRegle::RobotsTxt, 'nom' => 'URLs critiques non bloquees', 'configuration' => ['verification' => 'urls_critiques', 'urls_critiques' => ['/page', '/produit']], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::SitemapXml, 'nom' => 'Sitemap XML accessible', 'configuration' => ['verification' => 'accessible'], 'severite' => NiveauSeverite::Erreur],
            ['type_regle' => TypeRegle::SitemapXml, 'nom' => 'Comptage URLs (100-50000)', 'configuration' => ['verification' => 'comptage_urls', 'nombre_urls_min' => 100, 'nombre_urls_max' => 50000], 'severite' => NiveauSeverite::Info],
            ['type_regle' => TypeRegle::Favicon, 'nom' => 'Favicon present', 'configuration' => ['verification' => 'present'], 'severite' => NiveauSeverite::Avertissement],
        ];
    }
}
