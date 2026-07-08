<?php
// Run daily via cPanel Cron Job, e.g.:
//   0 9 * * *   php /home/{username}/public_html/linkedin-scheduler/cron/auto_post.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/linkedin_api.php';

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
$skippedNoAccount = (int) $pdo->query(
    "SELECT COUNT(*) FROM posts WHERE status = 'scheduled' AND scheduled_at <= NOW() AND linkedin_account_id IS NULL"
)->fetchColumn();

foreach ($due as $post) {
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

echo "Done. posted={$posted} failed={$failed} skipped_no_account={$skippedNoAccount}\n";
