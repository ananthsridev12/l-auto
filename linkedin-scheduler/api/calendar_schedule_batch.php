<?php
// Final step: assigns one LinkedIn account to every post in the batch
// and flips status draft -> scheduled (scheduled_at was already fixed at
// plan time in api/calendar_plan.php, so cron picks these up normally
// from here on, same as any other scheduled post).

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
$accountId = (int) ($_POST['linkedin_account_id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM calendar_batches WHERE id = ? AND user_id = ? AND status = "ready"');
$stmt->execute([$batchId, $userId]);
if (!$stmt->fetch()) {
    json_response(['success' => false, 'error' => 'This calendar is not ready to schedule yet.'], 422);
}

$acctStmt = db()->prepare('SELECT id FROM linkedin_accounts WHERE id = ? AND user_id = ? AND status = "active"');
$acctStmt->execute([$accountId, $userId]);
if (!$acctStmt->fetch()) {
    json_response(['success' => false, 'error' => 'Choose a valid, active LinkedIn account.'], 422);
}

$update = db()->prepare(
    "UPDATE posts SET linkedin_account_id = ?, status = 'scheduled'
     WHERE calendar_batch_id = ? AND user_id = ? AND content_approved_at IS NOT NULL AND image_approved_at IS NOT NULL"
);
$update->execute([$accountId, $batchId, $userId]);
$count = $update->rowCount();

db()->prepare('UPDATE calendar_batches SET status = "scheduled" WHERE id = ?')->execute([$batchId]);

json_response(['success' => true, 'scheduled_count' => $count]);
