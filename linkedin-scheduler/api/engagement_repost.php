<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/linkedin_api.php';
require_once __DIR__ . '/../includes/modules.php';
require_once __DIR__ . '/../includes/engagement.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!module_enabled('engagement')) {
    json_response(['success' => false, 'error' => 'Engagement is not enabled for your organization.'], 403);
}

$input = read_json_body();
$targetPostId = (int) ($input['target_post_id'] ?? 0);
$accountId    = (int) ($input['linkedin_account_id'] ?? 0);
$commentary   = (string) ($input['commentary'] ?? '');
$userId       = current_user_id();

$result = engagement_repost($targetPostId, $userId, $accountId, $commentary);
$statusCode = $result['status_code'] ?? ($result['success'] ? 200 : 500);
unset($result['status_code']);
json_response($result, $statusCode);
