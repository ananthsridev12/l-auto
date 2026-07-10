<?php
// Saves any edits made in the content review cards and marks the
// selected posts as content-approved in one call. Once every post in the
// batch has content_approved_at set, the batch moves to image_review.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$batchId = (int) ($_POST['batch_id'] ?? 0);
$stmt = db()->prepare('SELECT id FROM calendar_batches WHERE id = ? AND user_id = ?');
$stmt->execute([$batchId, $userId]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'Calendar not found.'], 404);
}

$postsData = json_decode($_POST['posts_json'] ?? '[]', true) ?: [];

// Text Post has no image step to skip to — approving its content also
// approves it as "image approved" immediately so it doesn't block the
// batch waiting for a render step that will never happen.
$update = db()->prepare(
    "UPDATE posts SET title = ?, caption = ?, creative_json = ?, content_approved_at = NOW(),
       image_approved_at = IF(format = 'Text Post', NOW(), image_approved_at)
     WHERE id = ? AND user_id = ? AND calendar_batch_id = ?"
);
$approved = 0;
foreach ($postsData as $p) {
    $postId = (int) ($p['post_id'] ?? 0);
    if (!$postId) {
        continue;
    }
    $creative = [
        'title'    => $p['title'] ?? '',
        'caption'  => $p['caption'] ?? '',
        'slides'   => $p['slides'] ?? [],
        'template' => $p['template'] ?? null,
    ];
    // Preserve format/hashtags/series_label from the existing creative_json
    // rather than losing them — merge on top of what's already stored.
    $existingStmt = db()->prepare('SELECT creative_json FROM posts WHERE id = ? AND user_id = ?');
    $existingStmt->execute([$postId, $userId]);
    $existing = json_decode((string) $existingStmt->fetchColumn(), true) ?: [];
    $merged = array_merge($existing, $creative);

    $update->execute([$creative['title'], $creative['caption'], json_encode($merged), $postId, $userId, $batchId]);
    $approved++;
}

$remainingStmt = db()->prepare('SELECT COUNT(*) FROM posts WHERE calendar_batch_id = ? AND content_approved_at IS NULL');
$remainingStmt->execute([$batchId]);
$remaining = (int) $remainingStmt->fetchColumn();

if ($remaining === 0) {
    db()->prepare('UPDATE calendar_batches SET status = "image_review" WHERE id = ?')->execute([$batchId]);
}

json_response(['success' => true, 'approved' => $approved, 'remaining' => $remaining, 'batch_advanced' => $remaining === 0]);
