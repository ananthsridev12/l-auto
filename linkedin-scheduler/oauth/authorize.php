<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/oauth_server.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    oauth_handle_authorize_post($_POST);
} else {
    oauth_handle_authorize_get($_GET);
}
