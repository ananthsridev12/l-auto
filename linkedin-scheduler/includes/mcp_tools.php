<?php
// Tool definitions + handlers for the /mcp endpoint. Every handler is
// (array $args, int $userId): array and reuses this app's own existing
// functions rather than new query logic — see each tool's comment for
// which one. Throws McpToolError for a validation/not-found problem
// (turned into an isError result by includes/mcp_server.php, never a
// JSON-RPC protocol error); let any other exception propagate so the
// server logs and reports it generically.
require_once __DIR__ . '/helpers.php'; // ALL_POST_FORMATS
require_once __DIR__ . '/post_helpers.php';
require_once __DIR__ . '/social_publish.php';
require_once __DIR__ . '/zip_import.php'; // MAX_SLIDES_PER_CAMPAIGN

class McpToolError extends RuntimeException
{
}

const MCP_PLATFORMS = ['linkedin', 'facebook', 'instagram', 'pinterest', 'google_business'];

// V1 deliberately always resolves the user's Personal workspace rather
// than honoring a session-based "current workspace" (there is no PHP
// session on a bearer-token-authenticated request) — a user with
// multiple company workspaces only gets MCP tools over their personal
// one for now. Documented scope limitation, not an oversight.
function mcp_resolve_workspace_id(int $userId): ?int
{
    $stmt = db()->prepare("SELECT id FROM workspaces WHERE user_id = ? AND type = 'personal' LIMIT 1");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function mcp_require_string(array $args, string $key): string
{
    $value = $args[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new McpToolError("\"{$key}\" is required.");
    }
    return $value;
}

function mcp_require_post_id(array $args): int
{
    $postId = (int) ($args['post_id'] ?? 0);
    if ($postId <= 0) {
        throw new McpToolError('"post_id" is required.');
    }
    return $postId;
}

// First http(s) URL found in free text, if any — drives create_post's
// "a link needs a look before it goes out" confirmation requirement.
function mcp_detect_link(string $text): ?string
{
    return preg_match('#https?://\S+#i', $text, $m) ? rtrim($m[0], '.,)>]"\'') : null;
}

function mcp_ip_is_public(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Fetches an externally-supplied file URL server-side (create_post's
// image_urls/video_url) with basic SSRF guards: scheme must be
// http/https, the resolved IP must be public (rules out loopback/
// private/link-local ranges), redirects are not followed (a public URL
// could otherwise redirect to an internal one), and the response must
// actually declare one of $allowedContentTypes under $maxBytes.
function mcp_fetch_remote_file(string $url, array $allowedContentTypes, int $maxBytes, string $kind, int $timeoutSeconds = 10): string
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
        throw new McpToolError("\"{$url}\" is not a valid http(s) {$kind} URL.");
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!mcp_ip_is_public($ip)) {
        throw new McpToolError("\"{$url}\" resolves to a private/internal address and can't be fetched.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status !== 200 || !$body) {
        throw new McpToolError("Could not download the {$kind} at \"{$url}\" (HTTP {$status}).");
    }
    if (strlen($body) > $maxBytes) {
        throw new McpToolError("The {$kind} at \"{$url}\" is too large (max " . round($maxBytes / 1024 / 1024) . "MB).");
    }
    if (!in_array($contentType, $allowedContentTypes, true)) {
        throw new McpToolError("\"{$url}\" did not return a " . implode('/', $allowedContentTypes) . " {$kind} (got \"{$contentType}\").");
    }
    return $body;
}

function mcp_fetch_remote_image(string $url): string
{
    return mcp_fetch_remote_file($url, ['image/png', 'image/jpeg', 'image/jpg'], 15 * 1024 * 1024, 'image');
}

// 200MB practical cap (not a LinkedIn limit) + a longer timeout than an
// image fetch, since a video this size takes real time to download.
function mcp_fetch_remote_video(string $url): string
{
    return mcp_fetch_remote_file($url, ['video/mp4'], 200 * 1024 * 1024, 'video', 60);
}

// Decodes an inline file for create_post's image_base64/video_base64 —
// for a file the caller already has the bytes for (e.g. one attached in
// chat), as opposed to the "fetch this public URL" path above. Expects
// a data: URI exactly like the ones this app's own UI already sends for
// AI-generated/pasted images (see pages/new_post.php, pages/post.php).
function mcp_decode_base64_file(string $dataUri, array $allowedMimeTypes, int $maxBytes, string $kind): string
{
    $pattern = '#^data:(' . implode('|', array_map(fn ($m) => preg_quote($m, '#'), $allowedMimeTypes)) . ');base64,(.+)$#';
    if (!preg_match($pattern, trim($dataUri), $m)) {
        // Temporary diagnostic (safe to leave: never echoes more than a
        // short prefix) — a client sent something that didn't match the
        // expected data: URI shape; show enough of it to tell why.
        $preview = substr($dataUri, 0, 60);
        throw new McpToolError("{$kind}_base64 entries must be a data URI like \"data:{$allowedMimeTypes[0]};base64,...\". Got (" . strlen($dataUri) . " chars, starts with): " . var_export($preview, true));
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false) {
        throw new McpToolError("{$kind}_base64 entry is not valid base64.");
    }
    if (strlen($bytes) > $maxBytes) {
        throw new McpToolError("A {$kind}_base64 entry is too large (max " . round($maxBytes / 1024 / 1024) . "MB).");
    }
    return $bytes;
}

function mcp_decode_base64_image(string $dataUri): string
{
    return mcp_decode_base64_file($dataUri, ['image/png', 'image/jpeg', 'image/jpg'], 15 * 1024 * 1024, 'image');
}

// 50MB practical cap for inline base64 — a JSON-RPC payload much larger
// than this is impractical regardless of what LinkedIn itself allows;
// use video_url for a larger file.
function mcp_decode_base64_video(string $dataUri): string
{
    return mcp_decode_base64_file($dataUri, ['video/mp4'], 50 * 1024 * 1024, 'video');
}

// Resolves which connected account a create_post call should use.
// $accountId omitted + exactly one connected account for the platform ->
// auto-selected. Zero or ambiguous (and no explicit id given) -> a clear
// McpToolError telling the caller to use list_accounts first, same
// "look up ids before acting" guidance as the rest of this tool set.
function mcp_resolve_account(string $platform, ?int $accountId, int $userId, ?int $workspaceId): array
{
    if ($platform === 'linkedin') {
        $accounts = fetch_user_accounts($userId, $workspaceId);
        $idKey = 'linkedin_account_id';
    } else {
        $accounts = fetch_user_social_accounts($userId, $platform);
        $idKey = 'social_account_id';
    }

    if ($accountId !== null) {
        $usable = $platform === 'linkedin'
            ? account_usable_in_workspace($accountId, $userId, $workspaceId)
            : social_account_usable($accountId, $userId);
        if (!$usable) {
            throw new McpToolError("account_id {$accountId} isn't a connected {$platform} account for this user.");
        }
        return [$idKey, $accountId];
    }
    if (count($accounts) === 1) {
        return [$idKey, (int) $accounts[0]['id']];
    }
    if (empty($accounts)) {
        throw new McpToolError("No connected {$platform} account — connect one first in Accounts.");
    }
    $names = implode(', ', array_map(fn ($a) => "{$a['id']} ({$a['display_name']})", $accounts));
    throw new McpToolError("Multiple {$platform} accounts connected — pass account_id. Options: {$names}. Use list_accounts to see them again.");
}

// --- today --------------------------------------------------------

function mcp_tool_today(array $args, int $userId): array
{
    $workspaceId = mcp_resolve_workspace_id($userId);
    $stmt = db()->prepare(
        "SELECT id, campaign_id, title, platform, format, status, scheduled_at
         FROM posts
         WHERE (workspace_id = ? OR (user_id = ? AND workspace_id IS NULL))
           AND status = 'scheduled' AND DATE(scheduled_at) = CURDATE()
         ORDER BY scheduled_at ASC"
    );
    $stmt->execute([$workspaceId, $userId]);
    return [
        'today'      => date('Y-m-d'),
        'due_today'  => $stmt->fetchAll(),
    ];
}

// --- list_posts -----------------------------------------------------

function mcp_tool_list_posts(array $args, int $userId): array
{
    $workspaceId = mcp_resolve_workspace_id($userId);
    $status = $args['status'] ?? null;
    $platform = $args['platform'] ?? null;

    $sql = 'SELECT id, campaign_id, title, caption, platform, format, status, scheduled_at, posted_at, error_message
            FROM posts WHERE (workspace_id = ? OR (user_id = ? AND workspace_id IS NULL))';
    $params = [$workspaceId, $userId];
    if (is_string($status) && $status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    if (is_string($platform) && $platform !== '') {
        $sql .= ' AND platform = ?';
        $params[] = $platform;
    }
    $sql .= ' ORDER BY updated_at DESC LIMIT 50';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return ['posts' => $stmt->fetchAll()];
}

// --- get_post ---------------------------------------------------------

function mcp_tool_get_post(array $args, int $userId): array
{
    $post = fetch_post(mcp_require_post_id($args), $userId);
    if (!$post) {
        throw new McpToolError('Post not found. Use list_posts to look up a valid post_id.');
    }
    return $post;
}

// --- create_post --------------------------------------------------

function mcp_tool_create_post(array $args, int $userId): array
{
    $caption = mcp_require_string($args, 'caption');
    $title = trim((string) ($args['title'] ?? ''));
    $platform = (string) ($args['platform'] ?? 'linkedin');
    if (!in_array($platform, MCP_PLATFORMS, true)) {
        throw new McpToolError('platform must be one of: ' . implode(', ', MCP_PLATFORMS) . '.');
    }
    // Two ways to supply an image: image_urls (fetch a public URL
    // server-side) or image_base64 (the caller already has the bytes —
    // e.g. a file attached in chat — as a data: URI). Combined in the
    // order given: every image_urls entry, then every image_base64
    // entry, so slide order is predictable regardless of which path a
    // given image came through.
    $imageUrls = array_values(array_filter((array) ($args['image_urls'] ?? []), 'is_string'));
    $imageBase64 = array_values(array_filter((array) ($args['image_base64'] ?? []), 'is_string'));
    $imageCount = count($imageUrls) + count($imageBase64);

    // Video — singular (one video per post, no multi-video concept),
    // LinkedIn-only. video_url/video_base64 mirror image_urls/
    // image_base64's two supply paths.
    $videoUrl = is_string($args['video_url'] ?? null) ? trim($args['video_url']) : '';
    $videoBase64 = is_string($args['video_base64'] ?? null) ? trim($args['video_base64']) : '';
    $hasVideo = $videoUrl !== '' || $videoBase64 !== '';
    if ($videoUrl !== '' && $videoBase64 !== '') {
        throw new McpToolError('Pass only one of video_url or video_base64, not both.');
    }
    if ($hasVideo && $imageCount > 0) {
        throw new McpToolError('A post can have images or a video, not both.');
    }
    if ($hasVideo && $platform !== 'linkedin') {
        throw new McpToolError('Video posts are only supported on LinkedIn for now.');
    }

    if ($imageCount > MAX_SLIDES_PER_CAMPAIGN) {
        throw new McpToolError('A post can have at most ' . MAX_SLIDES_PER_CAMPAIGN . ' images.');
    }
    $format = $hasVideo ? 'Video Post' : ($imageCount === 0 ? 'Text Post' : ($imageCount === 1 ? 'Single Image' : 'Carousel'));
    if ($platform === 'instagram' && $format === 'Text Post') {
        throw new McpToolError('Instagram requires at least one image — pass image_urls or image_base64.');
    }
    if (in_array($platform, ['pinterest', 'google_business'], true) && $imageCount > 1) {
        throw new McpToolError(ucfirst(str_replace('_', ' ', $platform)) . ' only supports a single image per post — pass exactly one image_urls/image_base64 entry.');
    }
    if ($format === 'Video Post' && !in_array($format, ALL_POST_FORMATS, true)) {
        // Unreachable once ALL_POST_FORMATS includes it, but a clear
        // failure rather than a confusing one if that ever regresses.
        throw new McpToolError('Video Post support is not available on this server.');
    }

    $workspaceId = mcp_resolve_workspace_id($userId);
    [$accountIdKey, $accountId] = mcp_resolve_account($platform, isset($args['account_id']) ? (int) $args['account_id'] : null, $userId, $workspaceId);

    $campaignId = 'MCP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $linkedinAccountId = $accountIdKey === 'linkedin_account_id' ? $accountId : null;
    $socialAccountId = $accountIdKey === 'social_account_id' ? $accountId : null;

    $stmt = db()->prepare(
        'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, platform, social_account_id, campaign_id, title, format, caption, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
    );
    $stmt->execute([$userId, $workspaceId, $linkedinAccountId, $platform, $socialAccountId, $campaignId, $title, $format, $caption]);
    $postId = (int) db()->lastInsertId();

    $previewUrls = [];
    if ($imageCount > 0) {
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        $slideIndex = 0;
        foreach ([...array_map(fn ($u) => ['type' => 'url', 'value' => $u], $imageUrls), ...array_map(fn ($b) => ['type' => 'base64', 'value' => $b], $imageBase64)] as $source) {
            try {
                $bytes = $source['type'] === 'url' ? mcp_fetch_remote_image($source['value']) : mcp_decode_base64_image($source['value']);
            } catch (McpToolError $e) {
                db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
                throw $e;
            }
            $slideIndex++;
            $filename = 'slide' . $slideIndex . '.png';
            $filepath = $destDir . '/' . $filename;
            file_put_contents($filepath, $bytes);
            $insertSlide->execute([$postId, $slideIndex, $filename, $filepath]);
            $previewUrls[] = slide_public_url($filepath);
        }
    }

    $videoPreviewUrl = null;
    if ($hasVideo) {
        try {
            $bytes = $videoUrl !== '' ? mcp_fetch_remote_video($videoUrl) : mcp_decode_base64_video($videoBase64);
        } catch (McpToolError $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            throw $e;
        }
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $filename = 'video.mp4';
        $filepath = $destDir . '/' . $filename;
        file_put_contents($filepath, $bytes);
        db()->prepare('UPDATE posts SET video_filename = ?, video_filepath = ? WHERE id = ?')->execute([$filename, $filepath, $postId]);
        $videoPreviewUrl = slide_public_url($filepath);
    }

    $detectedLink = mcp_detect_link($caption);
    // A video ALWAYS requires confirmation, regardless of caption
    // content — unlike images/links, this isn't conditional. Per the
    // user's explicit requirement: approval/preview for video is
    // mandatory, monitored through this MCP connection itself.
    $needsConfirmation = $imageCount > 0 || $hasVideo || $detectedLink !== null;

    if (!$needsConfirmation) {
        $result = publish_social_post_now($postId, $userId);
        return [
            'post_id'  => $postId,
            'status'   => $result['success'] ? 'posted' : 'failed',
            'posted'   => $result['success'],
            'error'    => $result['success'] ? null : $result['error'],
        ];
    }

    return [
        'post_id'               => $postId,
        'status'                => 'draft',
        'posted'                => false,
        'preview_urls'          => $previewUrls,
        'video_preview_url'     => $videoPreviewUrl,
        'detected_link'         => $detectedLink,
        'confirmation_required' => true,
        'message'               => 'Saved as a draft, not posted yet. Show the preview_urls/video_preview_url/detected_link to the user, and only call post_now with this post_id once they confirm it looks right.',
    ];
}

// --- update_post --------------------------------------------------

function mcp_tool_update_post(array $args, int $userId): array
{
    $postId = mcp_require_post_id($args);
    $post = fetch_post($postId, $userId);
    if (!$post) {
        throw new McpToolError('Post not found. Use list_posts to look up a valid post_id.');
    }
    if ($post['status'] === 'posted') {
        throw new McpToolError('This post has already been published and can no longer be edited.');
    }

    $caption = array_key_exists('caption', $args) ? (string) $args['caption'] : $post['caption'];
    $title = array_key_exists('title', $args) ? (string) $args['title'] : $post['title'];
    $status = $post['status'];
    $scheduledAt = $post['scheduled_at'];

    if (array_key_exists('scheduled_date', $args) && is_string($args['scheduled_date']) && $args['scheduled_date'] !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['scheduled_date'])) {
            throw new McpToolError('scheduled_date must be in YYYY-MM-DD format.');
        }
        $time = '09:00';
        if (array_key_exists('scheduled_time', $args) && is_string($args['scheduled_time']) && $args['scheduled_time'] !== '') {
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $args['scheduled_time'])) {
                throw new McpToolError('scheduled_time must be 24-hour HH:MM, e.g. "18:30".');
            }
            $time = $args['scheduled_time'];
        }
        $scheduledAt = $args['scheduled_date'] . ' ' . $time . ':00';
        $status = 'scheduled';
    }
    if (array_key_exists('status', $args) && $args['status'] === 'draft') {
        $status = 'draft';
        $scheduledAt = null;
    }

    db()->prepare('UPDATE posts SET caption = ?, title = ?, status = ?, scheduled_at = ? WHERE id = ?')
        ->execute([$caption, $title, $status, $scheduledAt, $postId]);

    return fetch_post($postId, $userId);
}

// --- delete_post --------------------------------------------------

function mcp_tool_delete_post(array $args, int $userId): array
{
    $postId = mcp_require_post_id($args);
    $post = fetch_post($postId, $userId);
    if (!$post) {
        throw new McpToolError('Post not found. Use list_posts to look up a valid post_id.');
    }
    if ($post['status'] === 'posted') {
        throw new McpToolError('This post has already been published and can no longer be deleted.');
    }
    db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
    return ['post_id' => $postId, 'deleted' => true];
}

// --- post_now -----------------------------------------------------

function mcp_tool_post_now(array $args, int $userId): array
{
    $postId = mcp_require_post_id($args);
    $post = fetch_post($postId, $userId);
    if (!$post) {
        throw new McpToolError('Post not found. Use list_posts to look up a valid post_id.');
    }
    if ($post['status'] === 'posted') {
        throw new McpToolError('This post has already been published.');
    }
    $result = publish_social_post_now($postId, $userId);
    return [
        'post_id' => $postId,
        'posted'  => $result['success'],
        'error'   => $result['success'] ? null : $result['error'],
    ];
}

// --- list_accounts --------------------------------------------------

function mcp_tool_list_accounts(array $args, int $userId): array
{
    $workspaceId = mcp_resolve_workspace_id($userId);
    $accounts = [];
    foreach (fetch_user_accounts($userId, $workspaceId) as $a) {
        $accounts[] = ['platform' => 'linkedin', 'account_id' => (int) $a['id'], 'display_name' => $a['display_name']];
    }
    foreach (['facebook', 'instagram', 'pinterest', 'google_business'] as $platform) {
        foreach (fetch_user_social_accounts($userId, $platform) as $a) {
            $accounts[] = ['platform' => $platform, 'account_id' => (int) $a['id'], 'display_name' => $a['display_name']];
        }
    }
    return ['accounts' => $accounts];
}

// --- list_content_pillars -------------------------------------------

function mcp_tool_list_content_pillars(array $args, int $userId): array
{
    $workspaceId = mcp_resolve_workspace_id($userId);
    return ['content_pillars' => fetch_content_pillars($userId, $workspaceId)];
}

// --- upload_media -----------------------------------------------------

// Decouples "give us the file" from "create a post" — a caller that
// already has the bytes (e.g. an AI-generated video) can upload once
// here and reuse the returned media_url across several create_post
// calls (different captions, retries, scheduling the same asset twice)
// instead of resending the same base64 payload every time. Reuses the
// exact same decode/size-cap helpers create_post's image_base64/
// video_base64 already use — this isn't a new way to get bytes in, just
// a way to not have to repeat it. Not tied to any post_id/campaign, so
// the file is saved under its own per-user folder rather than
// UPLOAD_DIR/{user}/{campaign}.
function mcp_tool_upload_media(array $args, int $userId): array
{
    $kind = (string) ($args['kind'] ?? '');
    if (!in_array($kind, ['image', 'video'], true)) {
        throw new McpToolError('"kind" must be "image" or "video".');
    }
    $dataUri = mcp_require_string($args, 'data_base64');
    $bytes = $kind === 'image' ? mcp_decode_base64_image($dataUri) : mcp_decode_base64_video($dataUri);

    $ext = $kind === 'image' ? 'png' : 'mp4';
    if (preg_match('#^data:[a-z]+/([a-z0-9.+-]+);base64,#i', trim($dataUri), $m)) {
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    }

    $destDir = UPLOAD_DIR . '/' . $userId . '/mcp-uploads';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    $filename = strtoupper(bin2hex(random_bytes(8))) . '.' . $ext;
    $filepath = $destDir . '/' . $filename;
    file_put_contents($filepath, $bytes);

    return [
        'media_url'  => slide_public_url($filepath),
        'kind'       => $kind,
        'size_bytes' => strlen($bytes),
        'message'    => 'Pass this media_url as an image_urls entry (image) or as video_url (video) on create_post. It stays available to reuse across multiple create_post calls without resending the file.',
    ];
}

// --- registry -----------------------------------------------------

function mcp_tool_registry(): array
{
    return [
        'today' => [
            'definition' => [
                'name' => 'today', 'title' => "Today's overview",
                'description' => "Returns the current server date and today's scheduled posts. Call this first if you need to know what date it is.",
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_today',
        ],
        'list_posts' => [
            'definition' => [
                'name' => 'list_posts', 'title' => 'List posts',
                'description' => 'Lists this user\'s posts (drafts, scheduled, posted, failed), most recently updated first. Optionally filter by status or platform.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status'   => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'posted', 'failed']],
                    'platform' => ['type' => 'string', 'enum' => MCP_PLATFORMS],
                ]],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_list_posts',
        ],
        'get_post' => [
            'definition' => [
                'name' => 'get_post', 'title' => 'Get a post',
                'description' => 'Fetches one post by id, with full details.',
                'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_get_post',
        ],
        'create_post' => [
            'definition' => [
                'name' => 'create_post', 'title' => 'Create a post',
                'description' => 'Creates a post. A plain text post with no images, video, or link in the caption is published immediately. A post with image_urls/image_base64, video_url/video_base64, and/or a link in the caption is saved as a draft and returns preview_urls/video_preview_url/detected_link for the user to review before calling post_now. A video post always requires that confirmation, even with a plain caption.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'caption'      => ['type' => 'string', 'description' => 'The post text.'],
                    'title'        => ['type' => 'string', 'description' => 'Internal title, optional.'],
                    'platform'     => ['type' => 'string', 'enum' => MCP_PLATFORMS, 'description' => 'Defaults to linkedin. Required to be linkedin if video_url/video_base64 is set.'],
                    'account_id'   => ['type' => 'integer', 'description' => 'Which connected account/page/board to use. Omit if only one is connected for the platform.'],
                    'image_urls'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Public http(s) PNG/JPEG URLs to fetch and attach. Combined with image_base64 — total count: 0 = Text Post, 1 = Single Image, 2+ = Carousel. Mutually exclusive with video_url/video_base64.'],
                    'image_base64' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For an image you already have the bytes for (e.g. one attached in this chat) rather than a public URL. Each entry a data URI: "data:image/png;base64,..." or "data:image/jpeg;base64,...". Combined with image_urls for the total image count.'],
                    'video_url'    => ['type' => 'string', 'description' => 'LinkedIn only. A public http(s) MP4 URL to fetch and attach (max 200MB). Mutually exclusive with image_urls/image_base64 and with video_base64.'],
                    'video_base64' => ['type' => 'string', 'description' => 'LinkedIn only. For a video you already have the bytes for (e.g. one generated or attached in this chat) rather than a public URL — a data URI: "data:video/mp4;base64,..." (max 50MB). Mutually exclusive with image_urls/image_base64 and with video_url.'],
                ], 'required' => ['caption']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_create_post',
        ],
        'update_post' => [
            'definition' => [
                'name' => 'update_post', 'title' => 'Update a post',
                'description' => 'Partially updates a draft or scheduled post (not one already posted). Only the fields passed are changed.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'post_id'        => ['type' => 'integer'],
                    'caption'        => ['type' => 'string'],
                    'title'          => ['type' => 'string'],
                    'scheduled_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Setting this schedules the post (defaults to 9am local server time if scheduled_time is omitted).'],
                    'scheduled_time' => ['type' => 'string', 'description' => '24-hour HH:MM, e.g. "18:30" for 6:30pm. Only used together with scheduled_date; defaults to "09:00".'],
                    'status'         => ['type' => 'string', 'enum' => ['draft'], 'description' => 'Pass "draft" to unschedule back to a draft.'],
                ], 'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_update_post',
        ],
        'delete_post' => [
            'definition' => [
                'name' => 'delete_post', 'title' => 'Delete a post',
                'description' => 'Permanently deletes a draft or scheduled post. Always confirm with the user first. Cannot delete a post that has already been published.',
                'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_delete_post',
        ],
        'post_now' => [
            'definition' => [
                'name' => 'post_now', 'title' => 'Publish a post now',
                'description' => 'Publishes a draft/scheduled post immediately to its platform. Only call this for an image/carousel/link post after the user has reviewed its preview_urls/detected_link and explicitly confirmed.',
                'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true],
            ],
            'handler' => 'mcp_tool_post_now',
        ],
        'list_accounts' => [
            'definition' => [
                'name' => 'list_accounts', 'title' => 'List connected accounts',
                'description' => 'Lists every connected LinkedIn/Facebook/Instagram/Pinterest/Google Business Profile account for this user. Read-only — connecting a new account requires the Accounts page.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_list_accounts',
        ],
        'list_content_pillars' => [
            'definition' => [
                'name' => 'list_content_pillars', 'title' => 'List content pillars',
                'description' => 'Lists this user\'s Content Pillars (knowledge-base categories used to guide content).',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_list_content_pillars',
        ],
        'upload_media' => [
            'definition' => [
                'name' => 'upload_media', 'title' => 'Upload media',
                'description' => 'Uploads an image or video you already have the bytes for (e.g. one generated or attached in this chat) and returns a public media_url. Use this once, then pass that media_url as an image_urls entry or as video_url on create_post — lets you reuse the same uploaded file across multiple posts/retries without resending its bytes every time.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'kind'        => ['type' => 'string', 'enum' => ['image', 'video'], 'description' => 'What kind of file this is.'],
                    'data_base64' => ['type' => 'string', 'description' => 'The file as a data URI: "data:image/png;base64,..." or "data:video/mp4;base64,..." (max 15MB for an image, 50MB for a video).'],
                ], 'required' => ['kind', 'data_base64']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            'handler' => 'mcp_tool_upload_media',
        ],
    ];
}
