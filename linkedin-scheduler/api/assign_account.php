<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = read_json_body();
$postId = (int) ($input['post_id'] ?? 0);
$accountId = isset($input['linkedin_account_id']) && $input['linkedin_account_id'] !== null
    ? (int) $input['linkedin_account_id']
    : null;

$post = fetch_post($postId, $userId);
if (!$post) {
    json_response(['success' => false, 'error' => 'Post not found'], 404);
}
if ($post['status'] === 'posted') {
    json_response(['success' => false, 'error' => 'This post has already been published.'], 422);
}

if ($accountId !== null) {
    $wsId = $post['workspace_id'] ? (int) $post['workspace_id'] : null;
    if (!account_usable_in_workspace($accountId, $userId, $wsId)) {
        json_response(['success' => false, 'error' => 'Invalid LinkedIn account'], 422);
    }
}

$upd = db()->prepare('UPDATE posts SET linkedin_account_id = ? WHERE id = ?');
$upd->execute([$accountId, $postId]);

json_response(['success' => true]);
