<?php
// Run via cPanel Cron Job every 15 minutes, e.g.:
//   */15 * * * *   php /home/{username}/public_html/linkedin-scheduler/cron/auto_publish_blog.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/workspace.php';
require_once __DIR__ . '/../includes/blog_posts.php';
require_once __DIR__ . '/../includes/wordpress_api.php';
require_once __DIR__ . '/../includes/jekyll_api.php';

// Same stale-guard as cron/auto_post.php — a cron outage or a schedule
// set far in the past shouldn't fire a backlog all at once.
const BLOG_STALE_HOURS = 24;

$pdo = db();

$stmt = $pdo->query(
    "SELECT bp.*, w.wordpress_url, w.wordpress_username, w.wordpress_app_password,
            w.jekyll_repo, w.jekyll_branch, w.jekyll_token, w.jekyll_posts_path, w.jekyll_site_url
     FROM blog_posts bp
     JOIN workspaces w ON w.id = bp.workspace_id
     WHERE bp.status = 'scheduled' AND bp.scheduled_at <= NOW()"
);
$due = $stmt->fetchAll();

$published = 0;
$failed = 0;
$skippedStale = 0;

foreach ($due as $post) {
    $hoursOverdue = (time() - strtotime($post['scheduled_at'])) / 3600;
    if ($hoursOverdue > BLOG_STALE_HOURS) {
        mark_blog_post_failed((int) $post['id'], sprintf(
            'Skipped: was scheduled for %s, more than %dh ago — review and reschedule manually.',
            $post['scheduled_at'], BLOG_STALE_HOURS
        ));
        $skippedStale++;
        echo "[stale, skipped] #{$post['id']} \"{$post['title']}\" was due {$post['scheduled_at']}\n";
        continue;
    }

    $workspace = [
        'wordpress_url'          => $post['wordpress_url'],
        'wordpress_username'     => $post['wordpress_username'],
        'wordpress_app_password' => $post['wordpress_app_password'],
        'jekyll_repo'            => $post['jekyll_repo'],
        'jekyll_branch'          => $post['jekyll_branch'],
        'jekyll_token'           => $post['jekyll_token'],
        'jekyll_posts_path'      => $post['jekyll_posts_path'],
        'jekyll_site_url'        => $post['jekyll_site_url'],
    ];
    $target = blog_resolve_publish_target($workspace, $post);
    if ($target === null) {
        mark_blog_post_failed((int) $post['id'], 'No WordPress or Jekyll connection configured for this workspace.');
        $failed++;
        echo "[failed] #{$post['id']} \"{$post['title']}\": no publish target configured\n";
        continue;
    }
    $result = $target === 'jekyll'
        ? jekyll_publish_post($workspace, $post)
        : wordpress_publish_post($workspace, $post);
    if ($result['success']) {
        mark_blog_post_published((int) $post['id'], $result['external_post_id'], $result['external_url'] ?? null, $target);
        $published++;
        echo "[published:{$target}] #{$post['id']} \"{$post['title']}\" -> {$result['external_url']}\n";
    } else {
        mark_blog_post_failed((int) $post['id'], $result['error']);
        $failed++;
        echo "[failed] #{$post['id']} \"{$post['title']}\": {$result['error']}\n";
    }
}

echo "Done. published={$published} failed={$failed} skipped_stale={$skippedStale}\n";
