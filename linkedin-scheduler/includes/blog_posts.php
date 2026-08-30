<?php
// CRUD for blog_posts (Phase F) — same shape as includes/post_helpers.php
// but for long-form blog content instead of LinkedIn creatives.

// Merges this workspace's own published posts with sitemap-discovered
// pages (includes/sitemap.php, must already be loaded by the caller)
// into one internal-linking candidate list for build_blog_prompt()'s
// $existingPosts param (includes/blog_generate.php) — used by both
// pages/blog_studio.php and pages/news_studio.php so a post can link to
// pages that exist on the site but weren't created through this app.
function blog_internal_link_candidates(int $userId, int $workspaceId): array
{
    $posts = array_map(
        fn ($p) => ['title' => $p['title'], 'slug' => $p['slug']],
        fetch_blog_posts($userId, $workspaceId, 'published')
    );
    $sitemap = array_map(
        fn ($s) => ['title' => $s['title'] ?: $s['url'], 'url' => $s['url'], 'category' => $s['category']],
        fetch_sitemap_links($workspaceId)
    );
    return array_merge($posts, $sitemap);
}

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

function create_blog_post(int $userId, int $workspaceId, array $creative, ?int $newsItemId = null, ?int $contentPillarId = null, ?string $contentType = null, ?int $collectionId = null, ?string $gravCategory = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO blog_posts (user_id, workspace_id, news_item_id, content_pillar_id, collection_id, title, slug, meta_description, keywords, content_html, content_type, grav_category, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
    );
    $stmt->execute([
        $userId, $workspaceId, $newsItemId, $contentPillarId, $collectionId,
        mb_substr($creative['title'], 0, 500),
        mb_substr($creative['slug'], 0, 500),
        $creative['meta_description'] !== '' ? mb_substr($creative['meta_description'], 0, 500) : null,
        $creative['keywords'] !== '' ? mb_substr($creative['keywords'], 0, 500) : null,
        $creative['content_html'],
        $contentType,
        $gravCategory,
    ]);
    return (int) db()->lastInsertId();
}

function update_blog_post(int $userId, int $id, array $fields): void
{
    $allowed = ['title', 'slug', 'meta_description', 'keywords', 'content_html', 'publish_target', 'content_pillar_id', 'content_type', 'collection_id', 'grav_category', 'grav_service', 'grav_industry'];
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

// On-page SEO checklist, computed fresh from the post's own fields on
// every view rather than stored — cheap enough (a handful of string
// checks over what's already loaded) that persisting/staleness isn't
// worth the tradeoff. Deliberately a plain rules checklist (title/meta
// length, keyword usage, heading/link structure, word count), not an
// AI judgment call — every check is something a human could verify by
// eye, this just saves counting characters by hand. Returns
// ['score' => 0-100, 'checks' => [['label','pass','detail'], ...]].
function blog_post_seo_score(array $post): array
{
    $title = trim((string) ($post['title'] ?? ''));
    $meta = trim((string) ($post['meta_description'] ?? ''));
    $keywords = array_values(array_filter(array_map('trim', explode(',', (string) ($post['keywords'] ?? '')))));
    $html = (string) ($post['content_html'] ?? '');
    $plainText = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
    $wordCount = $plainText === '' ? 0 : str_word_count($plainText);
    $titleLen = mb_strlen($title);
    $metaLen = mb_strlen($meta);

    $checks = [
        [
            'label' => 'Title length (30-65 characters)',
            'pass'  => $titleLen >= 30 && $titleLen <= 65,
            'detail' => "{$titleLen} characters",
        ],
        [
            'label' => 'Meta description (120-155 characters)',
            'pass'  => $metaLen >= 120 && $metaLen <= 155,
            'detail' => $meta === '' ? 'Not set' : "{$metaLen} characters",
        ],
        [
            'label' => 'Target keywords set',
            'pass'  => count($keywords) > 0,
            'detail' => $keywords ? implode(', ', $keywords) : 'Not set',
        ],
        [
            'label' => 'Primary keyword appears in the title',
            'pass'  => $keywords && $title !== '' && mb_stripos($title, $keywords[0]) !== false,
            'detail' => $keywords ? "Checked against \"{$keywords[0]}\"" : 'No keywords set to check',
        ],
        [
            'label' => 'Has subheadings (at least one <h2>)',
            'pass'  => (bool) preg_match('/<h2[\s>]/i', $html),
            'detail' => preg_match_all('/<h2[\s>]/i', $html) . ' found',
        ],
        [
            'label' => 'Has at least one link',
            'pass'  => (bool) preg_match('/<a\s[^>]*href=/i', $html),
            'detail' => preg_match_all('/<a\s[^>]*href=/i', $html) . ' found',
        ],
        [
            'label' => 'Word count is at least 300',
            'pass'  => $wordCount >= 300,
            'detail' => "{$wordCount} words",
        ],
    ];

    $passed = count(array_filter($checks, fn ($c) => $c['pass']));
    $score = $checks ? (int) round($passed / count($checks) * 100) : 0;

    return ['score' => $score, 'checks' => $checks];
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

// Soft-hidden from Grav (header.published = false, see
// grav_set_published() in includes/grav_api.php) — external_post_id/
// external_url are kept so a later "Republish" targets the same page
// rather than creating a duplicate. mark_blog_post_published() already
// covers republishing (it just sets status back to 'published').
function mark_blog_post_unpublished(int $id): void
{
    db()->prepare('UPDATE blog_posts SET status = "unpublished" WHERE id = ?')->execute([$id]);
}

// A real DELETE against Grav (grav_delete_post()) — the remote page is
// actually gone, so unlike unpublish this clears external_post_id/
// external_url too: a later "Publish Now" must create a brand new page,
// not PUT to a route that no longer exists.
function mark_blog_post_deleted_from_platform(int $id): void
{
    db()->prepare('UPDATE blog_posts SET status = "draft", external_post_id = NULL, external_url = NULL, published_at = NULL WHERE id = ?')->execute([$id]);
}
