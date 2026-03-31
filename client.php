<?php
// Deprecie — redirige vers le dashboard avec le client selectionne
$clientId = (int) ($_GET['id'] ?? 0);
$base = defined('PLATFORM_EMBEDDED') ? '' : '.';
if ($clientId > 0) {
    header('Location: ' . $base . '/index.php?client_id=' . $clientId);
} else {
    header('Location: ' . $base . '/index.php');
}
exit;
