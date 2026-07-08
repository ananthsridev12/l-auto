<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/linkedin_oauth.php';

require_login();

$type  = ($_GET['type'] ?? '') === 'company' ? 'company' : 'personal';
$scope = $type === 'company' ? LI_SCOPES_COMPANY : LI_SCOPES_PERSONAL;

$state = bin2hex(random_bytes(16));
$_SESSION['li_oauth_state'] = $state;
$_SESSION['li_oauth_type']  = $type;
$_SESSION['li_oauth_scope'] = $scope;

header('Location: ' . li_build_auth_url($state, $scope));
exit;
