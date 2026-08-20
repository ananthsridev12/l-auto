<?php
// Content Collections — group related LinkedIn posts + blog posts
// together (e.g. a product launch, a themed content week) so they can
// be viewed and managed as one set from Knowledge Hub. Same
// user_id/workspace_id-nullable scoping convention as
// fetch_content_pillars() — a NULL workspace_id row stays visible in
// every workspace. Named "Collection" specifically to avoid colliding
// with two other existing, unrelated concepts already in this
// codebase: posts.campaign_id (a per-post unique slug/upload-folder
// name) and creative_json.series_label (a per-slide cosmetic
// eyebrow-text field).

function fetch_content_collections(int $userId, ?int $workspaceId = null, string $status = 'active'): array
{
    if ($workspaceId === null) {
        $stmt = db()->prepare('SELECT * FROM content_collections WHERE user_id = ? AND status = ? ORDER BY name');
        $stmt->execute([$userId, $status]);
    } else {
        // Trusts workspace_id alone (not ANDed with user_id) once a
        // workspace is given — access to it is already gated upstream,
        // same reasoning as fetch_content_pillars().
        $stmt = db()->prepare('SELECT * FROM content_collections WHERE (workspace_id = ? OR (user_id = ? AND workspace_id IS NULL)) AND status = ? ORDER BY name');
        $stmt->execute([$workspaceId, $userId, $status]);
    }
    return $stmt->fetchAll();
}

// Same owns-OR-granted authorization join as fetch_content_pillar().
function fetch_content_collection(int $userId, int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT cc.* FROM content_collections cc
         LEFT JOIN workspaces w ON w.id = cc.workspace_id
         LEFT JOIN workspace_members wm ON wm.workspace_id = cc.workspace_id AND wm.user_id = ?
         WHERE cc.id = ? AND (
             (cc.workspace_id IS NULL AND cc.user_id = ?)
             OR w.user_id = ?
             OR wm.user_id IS NOT NULL
         )'
    );
    $stmt->execute([$userId, $id, $userId, $userId]);
    return $stmt->fetch() ?: null;
}

// A name matching an existing (active-or-archived) collection for this
// user/workspace re-activates and updates it rather than creating a
// duplicate — same UPSERT convention as add_news_topic()/pillar_add.
function add_content_collection(int $userId, ?int $workspaceId, string $name, string $description = ''): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO content_collections (user_id, workspace_id, name, description) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description), status = "active"'
    );
    $stmt->execute([$userId, $workspaceId, $name, trim($description) ?: null]);
}

function archive_content_collection(int $userId, int $id): void
{
    db()->prepare('UPDATE content_collections SET status = "archived" WHERE id = ? AND (user_id = ? OR workspace_id IN (SELECT id FROM workspaces WHERE user_id = ?))')
        ->execute([$id, $userId, $userId]);
}

// Posts/blog posts referencing a deleted collection keep existing
// (collection_id FKs are ON DELETE SET NULL) — deleting the grouping
// never touches the content itself.
function delete_content_collection(int $userId, int $id): void
{
    db()->prepare('DELETE FROM content_collections WHERE id = ? AND (user_id = ? OR workspace_id IN (SELECT id FROM workspaces WHERE user_id = ?))')
        ->execute([$id, $userId, $userId]);
}

// A short preview of what's actually inside a collection — both
// LinkedIn posts and blog posts — for the Knowledge Hub summary card.
// Not a full paginated listing; 'label' is whichever field reads as
// this item's name (campaign_id for a LinkedIn post, title for a blog
// post).
function content_collection_items(int $workspaceId, int $collectionId): array
{
    $postsStmt = db()->prepare(
        "SELECT id, campaign_id AS label, format, status, 'linkedin' AS kind
         FROM posts WHERE collection_id = ? AND (workspace_id = ? OR workspace_id IS NULL)
         ORDER BY updated_at DESC"
    );
    $postsStmt->execute([$collectionId, $workspaceId]);

    $blogStmt = db()->prepare(
        "SELECT id, title AS label, 'Blog Post' AS format, status, 'blog' AS kind
         FROM blog_posts WHERE collection_id = ? AND workspace_id = ?
         ORDER BY updated_at DESC"
    );
    $blogStmt->execute([$collectionId, $workspaceId]);

    return array_merge($postsStmt->fetchAll(), $blogStmt->fetchAll());
}
