<?php

/**
 * Site Monitor — Boot : autoload PSR-4 et propagation des variables d'environnement.
 */

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

