<?php

declare(strict_types=1);

namespace SiteMonitor\Regle;

use SiteMonitor\Core\ResultatVerification;
use SiteMonitor\Entite\Regle;

/**
 * Interface commune a tous les verificateurs de regles.
 *
 * Chaque type de regle (code HTTP, en-tete, XPath, etc.) implemente cette interface.
 */
interface InterfaceVerificateur
{
    /**
     * Execute la verification sur les donnees de reponse HTTP.
     *
     * @param Regle $regle La regle a verifier avec sa configuration
     * @param ContexteVerification $contexte Les donnees de la reponse HTTP
     *
     * @return ResultatVerification Le resultat de la verification
     */
    public function verifier(Regle $regle, ContexteVerification $contexte): ResultatVerification;

    /**
     * Retourne la cle du type de regle gere (doit correspondre a TypeRegle::value).
     */
    public function typeGere(): string;
}
