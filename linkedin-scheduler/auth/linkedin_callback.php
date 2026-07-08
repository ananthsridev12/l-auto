<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/linkedin_oauth.php';

require_login();

$code  = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (!$code || !$state || empty($_SESSION['li_oauth_state']) || !hash_equals($_SESSION['li_oauth_state'], $state)) {
    flash('error', 'LinkedIn sign-in failed or expired. Please try again.');
    redirect('pages/accounts.php');
}

$type = $_SESSION['li_oauth_type'] ?? 'personal';
unset($_SESSION['li_oauth_state'], $_SESSION['li_oauth_type']);

try {
    $token = li_exchange_code($code);
    $info  = li_get_userinfo($token['access_token']);
} catch (Throwable $e) {
    flash('error', 'Could not connect to LinkedIn: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$expiresAt = isset($token['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $token['expires_in']) : null;
$personUrn = 'urn:li:person:' . $info['sub'];
$userId    = current_user_id();

// A personal-profile row is always safe to save (or refresh) — the token
// includes profile access regardless of which "type" flow was started.
li_upsert_account($userId, 'personal', $personUrn, $info['name'] ?? 'Personal Profile', $info['name'] ?? '', $token['access_token'], $expiresAt, LI_SCOPES);

if ($type === 'company') {
    $_SESSION['li_pending_token']      = $token['access_token'];
    $_SESSION['li_pending_expires_at'] = $expiresAt;
    redirect('auth/linkedin_accounts_select.php');
}

flash('success', 'LinkedIn profile connected.');
redirect('pages/accounts.php');
