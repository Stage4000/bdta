<?php
/**
 * Redirects to the unified Credit & Package Management page.
 * All package management is now handled by credits_manage.php.
 */
$client_id = safe_int($_GET['client_id'] ?? 0);
$qs = $client_id ? '?client_id=' . $client_id : '';
header('Location: credits_manage.php' . $qs, true, 301);
exit;
