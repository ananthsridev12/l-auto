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
    $allowed = ['title', 'slug', 'meta_description', 'keywords', 'content_html'];
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
// "Publish Now" button and cron/auto_publish_blog.php).
function mark_blog_post_published(int $id, string $externalPostId, ?string $externalUrl): void
{
    db()->prepare(
        'UPDATE blog_posts SET status = "published", published_at = NOW(), external_post_id = ?, external_url = ?, error_message = NULL WHERE id = ?'
    )->execute([$externalPostId, $externalUrl, $id]);
}

function mark_blog_post_failed(int $id, string $error): void
{
    db()->prepare('UPDATE blog_posts SET status = "failed", error_message = ? WHERE id = ?')->execute([$error, $id]);
}
