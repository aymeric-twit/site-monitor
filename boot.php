<?php

/**
 * Site Monitor — Boot : autoload PSR-4 et propagation des variables d'environnement.
 */

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Propagation des cles SMTP depuis l'env plateforme
foreach (['SITE_MONITOR_SMTP_HOST', 'SITE_MONITOR_SMTP_USER', 'SITE_MONITOR_SMTP_PASS'] as $cle) {
    if (!empty($_ENV[$cle])) {
        putenv("{$cle}={$_ENV[$cle]}");
    }
}
