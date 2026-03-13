<?php
/**
 * Google Calendar OAuth 2.0 – Callback Handler
 *
 * Google redirects here after the user grants (or denies) calendar access.
 * Exchanges the authorisation code for access/refresh tokens and stores them.
 *
 * Access: Admin panel only (requires login).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/google_calendar.php';

requireLogin();

// Validate state to prevent CSRF
$state         = scalar_string($_GET['state'] ?? '');
$session_state = scalar_string($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if (empty($state) || !hash_equals($session_state, $state)) {
    setFlashMessage('Invalid OAuth state. Please try connecting again.', 'danger');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

// Handle denied access
if (isset($_GET['error'])) {
    $err = htmlspecialchars(scalar_string($_GET['error']), ENT_QUOTES, 'UTF-8');
    setFlashMessage('Google Calendar authorisation was denied: ' . $err, 'warning');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

$code = scalar_string($_GET['code'] ?? '');
if (empty($code)) {
    setFlashMessage('No authorisation code received from Google.', 'danger');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

$client_id     = Settings::get('google_oauth_client_id', '');
$client_secret = Settings::get('google_oauth_client_secret', '');
$redirect_uri  = Settings::get('google_oauth_redirect_uri', '');

// Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code'          => $code,
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code',
]));
$result   = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token_data = decode_json_assoc($result ?: '{}');

if (empty($token_data['access_token'])) {
    $error_desc = array_string_value($token_data, 'error_description', array_string_value($token_data, 'error', 'Unknown error'));
    setFlashMessage('Failed to obtain access token from Google: ' . htmlspecialchars($error_desc, ENT_QUOTES, 'UTF-8'), 'danger');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

// Fetch the Google account email for display
$google_email = '';
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . array_string_value($token_data, 'access_token')]);
$ui_result = curl_exec($ch);
curl_close($ch);
$user_info = decode_json_assoc($ui_result ?: '{}');
$user_email = array_string_value($user_info, 'email');
if ($user_email !== '') {
    $google_email = $user_email;
}

// Persist token – preserve any previously-selected calendar_id so that
// re-authentication does not silently revert to 'primary'.
$admin_user_id = safe_int($_SESSION['admin_id'] ?? 0);
$existing_token = GoogleCalendarIntegration::getOAuthToken($admin_user_id);
$calendar_id    = is_array($existing_token) ? array_string_value($existing_token, 'calendar_id', 'primary') : 'primary';
GoogleCalendarIntegration::saveOAuthToken(
    $admin_user_id,
    array_string_value($token_data, 'access_token'),
    array_string_value($token_data, 'refresh_token'),
    array_int_value($token_data, 'expires_in', 3600),
    $google_email,
    $calendar_id
);

$account_label = $google_email ?: 'Google account';
setFlashMessage('Google Calendar connected successfully (' . htmlspecialchars($account_label, ENT_QUOTES, 'UTF-8') . '). You can now select which calendar to sync bookings to.', 'success');
redirect(ADMIN_URL . 'settings.php?category=calendar');
