<?php
// Bulk-approves the rendered images for selected posts. Once every
// content-approved post in the batch also has image_approved_at set
// (Text Post rows already got it set automatically when their content
// was approved — see api/calendar_approve_content.php), the batch moves
// to "ready" for the final schedule step.

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

$postIds = array_map('intval', $_POST['post_ids'] ?? []);
if ($postIds) {
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $update = db()->prepare(
        "UPDATE posts SET image_approved_at = NOW()
         WHERE calendar_batch_id = ? AND user_id = ? AND id IN ($placeholders)"
    );
    $update->execute(array_merge([$batchId, $userId], $postIds));
}

$remainingStmt = db()->prepare(
    'SELECT COUNT(*) FROM posts WHERE calendar_batch_id = ? AND content_approved_at IS NOT NULL AND image_approved_at IS NULL'
);
$remainingStmt->execute([$batchId]);
$remaining = (int) $remainingStmt->fetchColumn();

if ($remaining === 0) {
    db()->prepare('UPDATE calendar_batches SET status = "ready" WHERE id = ?')->execute([$batchId]);
}

json_response(['success' => true, 'remaining' => $remaining, 'batch_advanced' => $remaining === 0]);
