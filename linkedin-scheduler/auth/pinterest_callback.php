<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pinterest_oauth.php';

require_login();

$code  = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state || empty($_SESSION['pinterest_oauth_state']) || !hash_equals($_SESSION['pinterest_oauth_state'], $state)) {
    flash('error', 'Pinterest sign-in failed or expired. Please try again.');
    redirect('pages/accounts.php');
}
unset($_SESSION['pinterest_oauth_state']);

try {
    $token = pinterest_exchange_code($code);
} catch (Throwable $e) {
    flash('error', 'Could not connect to Pinterest: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$expiresAt = isset($token['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $token['expires_in']) : null;

$_SESSION['pinterest_pending_token']         = $token['access_token'];
$_SESSION['pinterest_pending_refresh_token'] = $token['refresh_token'] ?? null;
$_SESSION['pinterest_pending_expires_at']    = $expiresAt;
$_SESSION['pinterest_pending_scope']         = $token['scope'] ?? 'boards:read,pins:read,pins:write';

redirect('auth/pinterest_boards_select.php');
