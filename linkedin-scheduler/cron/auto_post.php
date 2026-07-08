<?php
// Run via cPanel Cron Job every 15 minutes, e.g.:
//   */15 * * * *   php /home/{username}/public_html/linkedin-scheduler/cron/auto_post.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/linkedin_api.php';

// Anything "scheduled" more than this many hours in the past is treated
// as stale rather than posted — protects against a backlog of overdue
// rows (e.g. cron was off for a while, or a CSV date had already passed
// at import time) firing all at once the moment cron next runs.
const STALE_HOURS = 24;

$pdo = db();

$stmt = $pdo->query(
    "SELECT p.*, la.id AS la_id, la.access_token, la.target_urn, la.status AS account_status
     FROM posts p
     JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
     WHERE p.status = 'scheduled' AND p.scheduled_at <= NOW()"
);
$due = $stmt->fetchAll();

$posted = 0;
$failed = 0;
$skippedStale = 0;
$skippedNoAccount = (int) $pdo->query(
    "SELECT COUNT(*) FROM posts WHERE status = 'scheduled' AND scheduled_at <= NOW() AND linkedin_account_id IS NULL"
)->fetchColumn();

foreach ($due as $post) {
    $hoursOverdue = (time() - strtotime($post['scheduled_at'])) / 3600;
    if ($hoursOverdue > STALE_HOURS) {
        $upd = $pdo->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
        $upd->execute([
            sprintf('Skipped: was scheduled for %s, more than %dh ago — review and reschedule manually.', $post['scheduled_at'], STALE_HOURS),
            $post['id'],
        ]);
        $skippedStale++;
        echo "[stale, skipped] #{$post['id']} {$post['campaign_id']} was due {$post['scheduled_at']}\n";
        continue;
    }

    if ($post['account_status'] !== 'active') {
        $upd = $pdo->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
        $upd->execute(['Connected LinkedIn account needs to be reconnected.', $post['id']]);
        $failed++;
        continue;
    }

    $slideStmt = $pdo->prepare('SELECT filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
    $slideStmt->execute([$post['id']]);
    $slidePaths = array_column($slideStmt->fetchAll(), 'filepath');

    try {
        $postUrn = li_publish_post(
            $post['access_token'],
            $post['target_urn'],
            $post['format'],
            $post['caption'] ?? '',
            $post['campaign_id'] ?? '',
            $slidePaths
        );
        $upd = $pdo->prepare('UPDATE posts SET status = "posted", posted_at = NOW(), li_post_urn = ?, error_message = NULL WHERE id = ?');
        $upd->execute([$postUrn, $post['id']]);
        $posted++;
        echo "[posted] #{$post['id']} {$post['campaign_id']} -> {$postUrn}\n";
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $upd = $pdo->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
        $upd->execute([$message, $post['id']]);
        $failed++;
        echo "[failed] #{$post['id']} {$post['campaign_id']}: {$message}\n";

        if (str_contains($message, ' 401') || str_contains($message, ' 403')) {
            $accUpd = $pdo->prepare('UPDATE linkedin_accounts SET status = "expired" WHERE id = ?');
            $accUpd->execute([$post['la_id']]);
            echo "  -> marked linkedin_accounts #{$post['la_id']} as expired\n";
        }
    }
}

echo "Done. posted={$posted} failed={$failed} skipped_stale={$skippedStale} skipped_no_account={$skippedNoAccount}\n";
