<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/oauth_server.php';

oauth_cors_headers();
oauth_json_response(oauth_authorization_server_metadata());
