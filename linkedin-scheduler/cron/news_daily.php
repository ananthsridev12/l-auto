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
require_once __DIR__ . '/../includes/embeddings.php';
require_once __DIR__ . '/../includes/content_memory.php';
require_once __DIR__ . '/../includes/news_fetch.php';

// One pass per workspace with auto-drafting on — each workspace has its
// own topics, trusted sources, knowledge, and daily cap.
$workspaces = db()->query(
    'SELECT id, user_id, name, news_drafts_per_day FROM workspaces WHERE news_auto_enabled = 1'
)->fetchAll();

foreach ($workspaces as $w) {
    $userId = (int) $w['user_id'];
    $wsId = (int) $w['id'];
    $tag = "[ws {$wsId} \"{$w['name']}\"]";
    echo "{$tag} refreshing feeds...\n";

    $result = news_refresh($userId, $wsId);
    echo "{$tag} {$result['fetched']} queries, {$result['stored']} new headlines\n";
    foreach ($result['errors'] as $err) {
        echo "{$tag} feed error: {$err}\n";
    }

    $aiConfig = resolve_ai_config($userId);
    if (!ai_configured($aiConfig)) {
        echo "{$tag} no AI provider configured — headlines stored, drafts skipped\n";
        continue;
    }

    $doneStmt = db()->prepare(
        "SELECT COUNT(*) FROM posts WHERE user_id = ? AND (workspace_id <=> ?) AND campaign_id LIKE 'NEWS-%' AND created_at >= CURDATE()"
    );
    $doneStmt->execute([$userId, $wsId]);
    $doneToday = (int) $doneStmt->fetchColumn();
    $wanted = max(0, min(10, (int) $w['news_drafts_per_day']) - $doneToday);
    if ($wanted === 0) {
        echo "{$tag} daily draft cap already reached ({$doneToday})\n";
        continue;
    }

    foreach (news_pick_items_for_drafts($userId, $wanted, $wsId) as $item) {
        try {
            $postId = news_generate_draft($userId, $item, $aiConfig);
            echo "{$tag} draft #{$postId} from \"{$item['title']}\"\n";
        } catch (Throwable $e) {
            echo "{$tag} generation failed for \"{$item['title']}\": {$e->getMessage()}\n";
        }
    }
}

echo "done\n";
