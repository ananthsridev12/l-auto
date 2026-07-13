<?php
// CRUD for blog_posts (Phase F) — same shape as includes/post_helpers.php
// but for long-form blog content instead of LinkedIn creatives.

function fetch_blog_posts(int $userId, int $workspaceId, ?string $status = null): array
{
    if ($status !== null) {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE user_id = ? AND workspace_id = ? AND status = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId, $workspaceId, $status]);
    } else {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE user_id = ? AND workspace_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId, $workspaceId]);
    }
    return $stmt->fetchAll();
}

function fetch_blog_post(int $userId, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    return $stmt->fetch() ?: null;
}

// Slugs are unique enough for display/linking purposes within a
// workspace — collisions just get a numeric suffix, no hard DB
// constraint (a WordPress site is the real source of truth for slugs).
function blog_slugify(string $title): string
{
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
    return trim($slug, '-') ?: 'post';
}

function create_blog_post(int $userId, int $workspaceId, array $creative, ?int $newsItemId = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO blog_posts (user_id, workspace_id, news_item_id, title, slug, meta_description, keywords, content_html, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft")'
    );
    $stmt->execute([
        $userId, $workspaceId, $newsItemId,
        mb_substr($creative['title'], 0, 500),
        mb_substr($creative['slug'], 0, 500),
        $creative['meta_description'] !== '' ? mb_substr($creative['meta_description'], 0, 500) : null,
        $creative['keywords'] !== '' ? mb_substr($creative['keywords'], 0, 500) : null,
        $creative['content_html'],
    ]);
    return (int) db()->lastInsertId();
}

function update_blog_post(int $userId, int $id, array $fields): void
{
    $allowed = ['title', 'slug', 'meta_description', 'keywords', 'content_html', 'publish_target'];
    $sets = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $fields)) {
            $sets[] = "{$col} = ?";
            $params[] = $fields[$col];
        }
    }
    if (!$sets) {
        return;
    }
    $params[] = $id;
    $params[] = $userId;
    db()->prepare('UPDATE blog_posts SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);
}

// Resolves which platform to actually publish a post to. If a
// workspace has 2+ of WordPress/Jekyll/Grav configured, the post's own
// publish_target (user-selected in the Blog Studio editor) decides,
// falling back to the first configured platform if it isn't one of
// them. If only one platform is configured, that one is used
// regardless of the column's value — every post defaults to
// 'wordpress' at creation whether or not WordPress is even set up for
// this workspace, so that default can't be trusted blindly. Returns
// null if none are configured. Callers must have required
// wordpress_api.php, jekyll_api.php, and grav_api.php already.
function blog_resolve_publish_target(array $workspace, array $post): ?string
{
    $configured = [];
    if (wordpress_configured($workspace)) {
        $configured[] = 'wordpress';
    }
    if (jekyll_configured($workspace)) {
        $configured[] = 'jekyll';
    }
    if (grav_configured($workspace)) {
        $configured[] = 'grav';
    }
    if (!$configured) {
        return null;
    }
    if (count($configured) === 1) {
        return $configured[0];
    }
    $stored = $post['publish_target'] ?? null;
    return in_array($stored, $configured, true) ? $stored : $configured[0];
}

function delete_blog_post(int $userId, int $id): void
{
    db()->prepare('DELETE FROM blog_posts WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

function set_blog_post_schedule(int $userId, int $id, string $scheduledAt): void
{
    db()->prepare('UPDATE blog_posts SET status = "scheduled", scheduled_at = ?, error_message = NULL WHERE id = ? AND user_id = ?')
        ->execute([$scheduledAt, $id, $userId]);
}

// Marks the outcome of an actual publish attempt (used by both the
// "Publish Now" button and cron/auto_publish_blog.php). $publishTarget
// is the platform actually used (from blog_resolve_publish_target()) —
// persisted here so a post published while only one platform was
// configured still shows the right platform later, even though the
// column's own default ('wordpress') may not have been explicitly set
// on this row before now.
function mark_blog_post_published(int $id, string $externalPostId, ?string $externalUrl, string $publishTarget): void
{
    db()->prepare(
        'UPDATE blog_posts SET status = "published", published_at = NOW(), external_post_id = ?, external_url = ?, error_message = NULL, publish_target = ? WHERE id = ?'
    )->execute([$externalPostId, $externalUrl, $publishTarget, $id]);
}

function mark_blog_post_failed(int $id, string $error): void
{
    db()->prepare('UPDATE blog_posts SET status = "failed", error_message = ? WHERE id = ?')->execute([$error, $id]);
}
