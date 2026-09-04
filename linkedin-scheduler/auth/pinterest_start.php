<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pinterest_oauth.php';

require_login();

if (empty(PINTEREST_CLIENT_ID) || empty(PINTEREST_CLIENT_SECRET)) {
    flash('error', "Pinterest isn't configured on this server yet — ask your admin to set PINTEREST_CLIENT_ID/PINTEREST_CLIENT_SECRET in config.php.");
    redirect('pages/accounts.php');
}

$state = bin2hex(random_bytes(16));
$_SESSION['pinterest_oauth_state'] = $state;

header('Location: ' . pinterest_build_auth_url($state));
exit;
