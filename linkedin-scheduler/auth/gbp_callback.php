<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gbp_oauth.php';

require_login();

$code  = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state || empty($_SESSION['gbp_oauth_state']) || !hash_equals($_SESSION['gbp_oauth_state'], $state)) {
    flash('error', 'Google sign-in failed or expired. Please try again.');
    redirect('pages/accounts.php');
}
unset($_SESSION['gbp_oauth_state']);

try {
    $token = gbp_exchange_code($code);
} catch (Throwable $e) {
    flash('error', 'Could not connect to Google: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$expiresAt = isset($token['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $token['expires_in']) : null;

$_SESSION['gbp_pending_token']         = $token['access_token'];
$_SESSION['gbp_pending_refresh_token'] = $token['refresh_token'] ?? null;
$_SESSION['gbp_pending_expires_at']    = $expiresAt;
$_SESSION['gbp_pending_scope']         = $token['scope'] ?? 'https://www.googleapis.com/auth/business.manage';

redirect('auth/gbp_locations_select.php');
