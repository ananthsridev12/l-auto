<?php
// Tool definitions + handlers for the /mcp endpoint. Every handler is
// (array $args, int $userId): array and reuses this app's own existing
// functions rather than new query logic — see each tool's comment for
// which one. Throws McpToolError for a validation/not-found problem
// (turned into an isError result by includes/mcp_server.php, never a
// JSON-RPC protocol error); let any other exception propagate so the
// server logs and reports it generically.
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

// Fetches an externally-supplied image URL server-side (for create_post's
// image_urls) with basic SSRF guards: scheme must be http/https, the
// resolved IP must be public (rules out loopback/private/link-local
// ranges), redirects are not followed (a public URL could otherwise
// redirect to an internal one), and the response must actually be a
// PNG/JPEG under a sane size cap.
function mcp_fetch_remote_image(string $url): string
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
        throw new McpToolError("\"{$url}\" is not a valid http(s) image URL.");
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!mcp_ip_is_public($ip)) {
        throw new McpToolError("\"{$url}\" resolves to a private/internal address and can't be fetched.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status !== 200 || !$body) {
        throw new McpToolError("Could not download the image at \"{$url}\" (HTTP {$status}).");
    }
    if (strlen($body) > 15 * 1024 * 1024) {
        throw new McpToolError("The image at \"{$url}\" is too large (max 15MB).");
    }
    if (!in_array($contentType, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
        throw new McpToolError("\"{$url}\" did not return a PNG/JPEG image (got \"{$contentType}\").");
    }
    return $body;
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
    $imageUrls = array_values(array_filter((array) ($args['image_urls'] ?? []), 'is_string'));
    if (count($imageUrls) > MAX_SLIDES_PER_CAMPAIGN) {
        throw new McpToolError('A post can have at most ' . MAX_SLIDES_PER_CAMPAIGN . ' images.');
    }
    $format = count($imageUrls) === 0 ? 'Text Post' : (count($imageUrls) === 1 ? 'Single Image' : 'Carousel');
    if ($platform === 'instagram' && $format === 'Text Post') {
        throw new McpToolError('Instagram requires at least one image — pass image_urls.');
    }
    if (in_array($platform, ['pinterest', 'google_business'], true) && count($imageUrls) > 1) {
        throw new McpToolError(ucfirst(str_replace('_', ' ', $platform)) . ' only supports a single image per post — pass exactly one image_urls entry.');
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
    if ($imageUrls) {
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        foreach ($imageUrls as $i => $url) {
            try {
                $bytes = mcp_fetch_remote_image($url);
            } catch (McpToolError $e) {
                db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
                throw $e;
            }
            $filename = 'slide' . ($i + 1) . '.png';
            $filepath = $destDir . '/' . $filename;
            file_put_contents($filepath, $bytes);
            $insertSlide->execute([$postId, $i + 1, $filename, $filepath]);
            $previewUrls[] = slide_public_url($filepath);
        }
    }

    $detectedLink = mcp_detect_link($caption);
    $needsConfirmation = !empty($imageUrls) || $detectedLink !== null;

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
        'post_id'             => $postId,
        'status'              => 'draft',
        'posted'              => false,
        'preview_urls'        => $previewUrls,
        'detected_link'       => $detectedLink,
        'confirmation_required' => true,
        'message'             => 'Saved as a draft, not posted yet. Show the preview_urls/detected_link to the user, and only call post_now with this post_id once they confirm it looks right.',
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
        $scheduledAt = $args['scheduled_date'] . ' 09:00:00';
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
                'description' => 'Creates a post. A plain text post with no images and no link in the caption is published immediately. A post with image_urls and/or a link in the caption is saved as a draft and returns preview_urls/detected_link for the user to review before calling post_now.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'caption'    => ['type' => 'string', 'description' => 'The post text.'],
                    'title'      => ['type' => 'string', 'description' => 'Internal title, optional.'],
                    'platform'   => ['type' => 'string', 'enum' => MCP_PLATFORMS, 'description' => 'Defaults to linkedin.'],
                    'account_id' => ['type' => 'integer', 'description' => 'Which connected account/page/board to use. Omit if only one is connected for the platform.'],
                    'image_urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '0 = Text Post, 1 = Single Image, 2+ = Carousel. Each must be a public http(s) PNG/JPEG URL.'],
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
                    'scheduled_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Setting this schedules the post for 9am that day.'],
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
    ];
}
