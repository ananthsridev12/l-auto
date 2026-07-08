<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/linkedin_oauth.php';

require_login();

$type = ($_GET['type'] ?? '') === 'company' ? 'company' : 'personal';

$state = bin2hex(random_bytes(16));
$_SESSION['li_oauth_state'] = $state;
$_SESSION['li_oauth_type']  = $type;

header('Location: ' . li_build_auth_url($state));
exit;
