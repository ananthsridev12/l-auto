<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/meta_oauth.php';

require_login();

$code  = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state || empty($_SESSION['meta_oauth_state']) || !hash_equals($_SESSION['meta_oauth_state'], $state)) {
    flash('error', 'Facebook sign-in failed or expired. Please try again.');
    redirect('pages/accounts.php');
}
unset($_SESSION['meta_oauth_state']);

try {
    $token = meta_exchange_code($code);
    $longLived = meta_get_long_lived_token($token['access_token']);
} catch (Throwable $e) {
    flash('error', 'Could not connect to Facebook: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$_SESSION['meta_pending_token'] = $longLived['access_token'];
redirect('auth/meta_accounts_select.php');
