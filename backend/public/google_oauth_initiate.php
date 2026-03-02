<?php
/**
 * Google Calendar OAuth 2.0 – Initiate Flow
 *
 * Redirects the logged-in admin user to Google's OAuth consent page.
 * After authorisation, Google redirects to google_oauth_callback.php.
 *
 * Access: Admin panel only (requires login).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';

requireLogin();

$client_id    = Settings::get('google_oauth_client_id', '');
$redirect_uri = Settings::get('google_oauth_redirect_uri', '');

if (empty($client_id) || empty($redirect_uri)) {
    setFlashMessage('Google OAuth is not configured. Please set the OAuth Client ID, Client Secret, and Redirect URI in Settings → Calendar.', 'danger');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

// Generate a random state token to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = http_build_query([
    'client_id'             => $client_id,
    'redirect_uri'          => $redirect_uri,
    'response_type'         => 'code',
    'scope'                 => 'https://www.googleapis.com/auth/calendar openid email',
    'access_type'           => 'offline',
    'prompt'                => 'consent',   // force refresh_token even on re-auth
    'state'                 => $state,
    'include_granted_scopes' => 'true',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
