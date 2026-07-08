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

$stmt = db()->prepare(
    'SELECT p.*, la.access_token, la.target_urn, la.status AS account_status
     FROM posts p
     LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
     WHERE p.id = ? AND p.user_id = ?'
);
$stmt->execute([$postId, $userId]);
$post = $stmt->fetch();

if (!$post) {
    json_response(['success' => false, 'error' => 'Post not found'], 404);
}
if (!$post['linkedin_account_id']) {
    json_response(['success' => false, 'error' => 'Assign a LinkedIn account to this post before posting.'], 422);
}
if ($post['account_status'] !== 'active') {
    json_response(['success' => false, 'error' => 'The connected LinkedIn account needs to be reconnected.'], 422);
}
if (!in_array($post['format'], get_enabled_formats($userId), true)) {
    json_response(['success' => false, 'error' => "\"{$post['format']}\" posting is disabled in Settings."], 422);
}

$slideStmt = db()->prepare('SELECT filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
$slideStmt->execute([$postId]);
$slidePaths = array_column($slideStmt->fetchAll(), 'filepath');

try {
    $postUrn = li_publish_post(
        $post['access_token'],
        $post['target_urn'],
        $post['format'],
        $post['caption'] ?? '',
        $post['campaign_id'] ?? '',
        $slidePaths,
        $post['title'] ?? '',
        get_mention_candidates($userId)
    );

    $upd = db()->prepare('UPDATE posts SET status = "posted", posted_at = NOW(), li_post_urn = ?, error_message = NULL WHERE id = ?');
    $upd->execute([$postUrn, $postId]);

    json_response(['success' => true, 'post_urn' => $postUrn]);
} catch (Throwable $e) {
    $upd = db()->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
    $upd->execute([$e->getMessage(), $postId]);

    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
