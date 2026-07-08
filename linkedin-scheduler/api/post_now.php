<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/linkedin_api.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input  = read_json_body();
$postId = (int) ($input['post_id'] ?? 0);
$userId = current_user_id();

$result = publish_post_now($postId, $userId);
$statusCode = $result['status_code'] ?? ($result['success'] ? 200 : 500);
unset($result['status_code']);
json_response($result, $statusCode);
