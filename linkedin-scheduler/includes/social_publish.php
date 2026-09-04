<?php
// Platform router sitting in front of the existing LinkedIn-only
// publish_post_now() (includes/linkedin_api.php) and the new
// Facebook/Instagram/Pinterest/Google Business Profile publishers.
// api/post_now.php and cron/auto_post.php both call this instead of
// publish_post_now() directly so every platform shares one entry
// point — see the multi-platform posting plan in this session for why.
require_once __DIR__ . '/facebook_api.php';
require_once __DIR__ . '/instagram_api.php';

// Shared by api/post_now.php and cron/auto_post.php for every non-
// LinkedIn platform. Mirrors publish_post_now()'s validation/record-
// keeping shape exactly (post_helpers.php fetch_post(), the same
// enabled-formats/org-limit checks, the same status/error_message
// update on success or failure) so the two publish paths stay
// consistent — see includes/linkedin_api.php publish_post_now() for
// the LinkedIn equivalent, which this function defers to unchanged for
// platform === 'linkedin'.
function publish_social_post_now(int $postId, int $userId): array
{
    $post = fetch_post($postId, $userId);
    if (!$post) {
        return ['success' => false, 'error' => 'Post not found', 'status_code' => 404];
    }

    if (($post['platform'] ?? 'linkedin') === 'linkedin') {
        return publish_post_now($postId, $userId);
    }

    if (!$post['social_account_id']) {
        return ['success' => false, 'error' => 'Assign an account to this post before posting.', 'status_code' => 422];
    }

    $acctStmt = db()->prepare('SELECT * FROM social_accounts WHERE id = ? AND platform = ?');
    $acctStmt->execute([$post['social_account_id'], $post['platform']]);
    $account = $acctStmt->fetch();
    if (!$account || $account['status'] !== 'active') {
        return ['success' => false, 'error' => 'The connected account needs to be reconnected.', 'status_code' => 422];
    }
    if (!in_array($post['format'], get_enabled_formats($userId), true)) {
        return ['success' => false, 'error' => "\"{$post['format']}\" posting is disabled in Settings.", 'status_code' => 422];
    }
    $orgId = user_organization_id($userId);
    if ($orgId && !org_within_limit($orgId, 'posts')) {
        return ['success' => false, 'error' => "Your organization's plan has reached its monthly post limit.", 'status_code' => 422];
    }

    $slideStmt = db()->prepare('SELECT filename, filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
    $slideStmt->execute([$postId]);
    $slides = $slideStmt->fetchAll();

    try {
        $externalId = social_publish_dispatch($account, $post['format'], $post['caption'] ?? '', $slides);

        $upd = db()->prepare('UPDATE posts SET status = "posted", posted_at = NOW(), scheduled_at = COALESCE(scheduled_at, NOW()), external_post_id = ?, error_message = NULL WHERE id = ?');
        $upd->execute([$externalId, $postId]);

        return ['success' => true, 'external_post_id' => $externalId];
    } catch (Throwable $e) {
        $upd = db()->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
        $upd->execute([$e->getMessage(), $postId]);

        return ['success' => false, 'error' => $e->getMessage(), 'status_code' => 500];
    }
}

function social_publish_dispatch(array $account, string $format, string $caption, array $slides): string
{
    switch ($account['platform']) {
        case 'facebook':
            return fb_publish_post($account, $format, $caption, $slides);
        case 'instagram':
            return ig_publish_post($account, $format, $caption, $slides);
        default:
            throw new RuntimeException("Publishing to \"{$account['platform']}\" isn't supported yet.");
    }
}
