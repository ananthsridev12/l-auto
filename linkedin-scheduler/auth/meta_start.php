<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/meta_oauth.php';

require_login();

if (empty(FB_APP_ID) || empty(FB_APP_SECRET)) {
    flash('error', 'Facebook/Instagram isn\'t configured on this server yet — ask your admin to set FB_APP_ID/FB_APP_SECRET in config.php.');
    redirect('pages/accounts.php');
}

$state = bin2hex(random_bytes(16));
$_SESSION['meta_oauth_state'] = $state;

header('Location: ' . meta_build_auth_url($state));
exit;
