<?php

function fetch_post_with_slides(int $postId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*, la.display_name AS account_name, la.status AS account_status
         FROM posts p
         LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
         WHERE p.id = ? AND p.user_id = ?'
    );
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();
    if (!$post) {
        return null;
    }

    $slideStmt = db()->prepare('SELECT filename, filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
    $slideStmt->execute([$postId]);
    $post['slides'] = array_map(fn ($s) => [
        'filename' => $s['filename'],
        'url'      => slide_public_url($s['filepath']),
    ], $slideStmt->fetchAll());

    return $post;
}

function slide_public_url(string $filepath): string
{
    $relative = ltrim(str_replace(UPLOAD_DIR, '', $filepath), '/');
    return app_path('uploads/' . $relative);
}

function fetch_user_accounts(int $userId): array
{
    $stmt = db()->prepare('SELECT id, display_name, account_type FROM linkedin_accounts WHERE user_id = ? AND status = "active" ORDER BY display_name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
