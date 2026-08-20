<?php
// Link Tracking — a short redirect link (APP_URL/go?s={slug}) for any
// destination URL, so a link pasted into a LinkedIn caption or a blog
// post can have its clicks counted. LinkedIn's API gives no read-back
// engagement data (the same wall this app has hit for Like/Comment and
// post analytics), so a self-hosted click counter is the one signal
// this app can measure on its own. Deliberately NOT tied to a specific
// post/blog_post row — a link is created once via Knowledge Hub's
// "Link Tracking" tab and pasted wherever needed, same workflow as any
// other link shortener.

const TRACKED_LINK_SLUG_LENGTH = 7;
const TRACKED_LINK_SLUG_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no 0/o/1/l/i — avoids visual ambiguity when read aloud/typed

function fetch_tracked_links(int $userId, ?int $workspaceId = null): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT * FROM tracked_links WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare('SELECT * FROM tracked_links WHERE (workspace_id = ? OR (user_id = ? AND workspace_id IS NULL)) ORDER BY created_at DESC');
        $stmt->execute([$workspaceId, $userId]);
    }
    return $stmt->fetchAll();
}

function tracked_link_generate_slug(): string
{
    $alphabet = TRACKED_LINK_SLUG_ALPHABET;
    $slug = '';
    for ($i = 0; $i < TRACKED_LINK_SLUG_LENGTH; $i++) {
        $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $slug;
}

// $targetUrl must be a full http(s) URL — go.php performs a Location
// redirect to it verbatim, so this validation is the only gate against
// someone pasting something that isn't a real link.
function create_tracked_link(int $userId, ?int $workspaceId, string $targetUrl, string $label = ''): array
{
    $targetUrl = trim($targetUrl);
    if (!filter_var($targetUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $targetUrl)) {
        throw new InvalidArgumentException('Enter a valid http(s):// URL.');
    }
    $label = trim($label);

    // Collision odds are negligible at this alphabet/length, but retry a
    // few times rather than trust that — same defensive pattern as
    // add_news_topic()'s duplicate-key handling elsewhere in this app.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $slug = tracked_link_generate_slug();
        try {
            db()->prepare('INSERT INTO tracked_links (user_id, workspace_id, label, target_url, slug) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $workspaceId, $label !== '' ? mb_substr($label, 0, 255) : null, mb_substr($targetUrl, 0, 1000), $slug]);
            return fetch_tracked_link($userId, (int) db()->lastInsertId());
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique short link — try again.');
}

function fetch_tracked_link(int $userId, int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT tl.* FROM tracked_links tl
         LEFT JOIN workspaces w ON w.id = tl.workspace_id
         LEFT JOIN workspace_members wm ON wm.workspace_id = tl.workspace_id AND wm.user_id = ?
         WHERE tl.id = ? AND (
             (tl.workspace_id IS NULL AND tl.user_id = ?)
             OR w.user_id = ?
             OR wm.user_id IS NOT NULL
         )'
    );
    $stmt->execute([$userId, $id, $userId, $userId]);
    return $stmt->fetch() ?: null;
}

function delete_tracked_link(int $userId, int $id): void
{
    db()->prepare('DELETE FROM tracked_links WHERE id = ? AND (user_id = ? OR workspace_id IN (SELECT id FROM workspaces WHERE user_id = ?))')
        ->execute([$id, $userId, $userId]);
}

function tracked_link_url(string $slug): string
{
    return app_path('go.php?s=' . $slug);
}

// go.php's only job: atomically bump the counter and hand back the
// destination, or null for an unknown slug. No auth — this endpoint is
// hit by whoever clicks the link, not the account owner.
function record_link_click(string $slug): ?string
{
    $stmt = db()->prepare('SELECT target_url FROM tracked_links WHERE slug = ?');
    $stmt->execute([$slug]);
    $target = $stmt->fetchColumn();
    if ($target === false) {
        return null;
    }
    db()->prepare('UPDATE tracked_links SET click_count = click_count + 1, last_clicked_at = NOW() WHERE slug = ?')->execute([$slug]);
    return $target;
}
