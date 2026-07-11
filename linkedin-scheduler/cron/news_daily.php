<?php
// Run via cPanel Cron Job once a day (morning, before you'd review), e.g.:
//   0 7 * * *   php /home/{username}/public_html/linkedin-scheduler/cron/news_daily.php
//
// For every user with news auto-drafting enabled in Settings: fetch the
// latest Google News headlines for their Content Pillars + custom
// keywords, then turn the freshest unused ones into complete draft
// posts (AI-written in their voice, image rendered) — up to their
// per-day cap, counting drafts already generated today so re-running
// the cron never over-produces. Drafts land in the normal Drafts list
// and News Studio for review; nothing is scheduled or posted without
// the user's approval.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/creative_builder.php'; // creative_series_label() etc., used by generate_creative_via_ai()
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/news_fetch.php';

$users = db()->query(
    'SELECT id, news_drafts_per_day FROM users WHERE news_auto_enabled = 1'
)->fetchAll();

foreach ($users as $u) {
    $userId = (int) $u['id'];
    echo "[user {$userId}] refreshing feeds...\n";

    $result = news_refresh($userId);
    echo "[user {$userId}] {$result['fetched']} queries, {$result['stored']} new headlines\n";
    foreach ($result['errors'] as $err) {
        echo "[user {$userId}] feed error: {$err}\n";
    }

    $aiConfig = resolve_ai_config($userId);
    if (!ai_configured($aiConfig)) {
        echo "[user {$userId}] no AI provider configured — headlines stored, drafts skipped\n";
        continue;
    }

    $doneStmt = db()->prepare(
        "SELECT COUNT(*) FROM posts WHERE user_id = ? AND campaign_id LIKE 'NEWS-%' AND created_at >= CURDATE()"
    );
    $doneStmt->execute([$userId]);
    $doneToday = (int) $doneStmt->fetchColumn();
    $wanted = max(0, min(10, (int) $u['news_drafts_per_day']) - $doneToday);
    if ($wanted === 0) {
        echo "[user {$userId}] daily draft cap already reached ({$doneToday})\n";
        continue;
    }

    foreach (news_pick_items_for_drafts($userId, $wanted) as $item) {
        try {
            $postId = news_generate_draft($userId, $item, $aiConfig);
            echo "[user {$userId}] draft #{$postId} from \"{$item['title']}\"\n";
        } catch (Throwable $e) {
            echo "[user {$userId}] generation failed for \"{$item['title']}\": {$e->getMessage()}\n";
        }
    }
}

echo "done\n";
