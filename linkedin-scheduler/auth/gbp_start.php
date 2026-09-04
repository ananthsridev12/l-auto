<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gbp_oauth.php';

require_login();

if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    flash('error', "Google Business Profile isn't configured on this server yet — ask your admin to set GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET in config.php.");
    redirect('pages/accounts.php');
}

$state = bin2hex(random_bytes(16));
$_SESSION['gbp_oauth_state'] = $state;

header('Location: ' . gbp_build_auth_url($state));
exit;
