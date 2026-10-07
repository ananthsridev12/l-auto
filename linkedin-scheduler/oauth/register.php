<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/oauth_server.php';

oauth_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    oauth_json_response(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405);
}

$body = read_json_body();
try {
    oauth_json_response(oauth_register_client($body), 201);
} catch (OAuthError $e) {
    oauth_error_json($e);
}
