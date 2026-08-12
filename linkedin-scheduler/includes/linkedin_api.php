<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/pdf_builder.php';
require_once __DIR__ . '/linkedin_text.php';
require_once __DIR__ . '/organizations.php';

function li_json_headers(string $accessToken): array
{
    return [
        'Authorization: Bearer ' . $accessToken,
        'LinkedIn-Version: ' . LI_VERSION,
        'X-Restli-Protocol-Version: 2.0.0',
        'Content-Type: application/json',
    ];
}

// Builds the public permalink for a post from the URN li_create_post()
// stored (e.g. "urn:li:share:712345..." or "urn:li:ugcPost:712345...")
// — LinkedIn's own "Copy link to post" feature produces this exact
// /feed/update/{urn}/ format for any post URN type. Returns null for
// anything that isn't a real URN (empty, or the 'unknown' fallback
// li_create_post() returns when its response had no x-restli-id header).
function li_post_url(?string $urn): ?string
{
    $urn = trim((string) $urn);
    if ($urn === '' || $urn === 'unknown' || !str_starts_with($urn, 'urn:li:')) {
        return null;
    }
    return 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/';
}

// LinkedIn's public "Embed this post" iframe — works for any public
// post with no API call/auth needed at all (same mechanism as a
// YouTube/Twitter embed), so this has nothing to do with scope/product
// access. Same validity guard as li_post_url().
function li_embed_url(?string $urn): ?string
{
    $urn = trim((string) $urn);
    if ($urn === '' || $urn === 'unknown' || !str_starts_with($urn, 'urn:li:')) {
        return null;
    }
    return 'https://www.linkedin.com/embed/feed/update/' . rawurlencode($urn);
}

function li_upload_image(string $accessToken, string $actingUrn, string $imagePath): string
{
    $ch = curl_init(LI_API_BASE . '/rest/images?action=initializeUpload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode(['initializeUploadRequest' => ['owner' => $actingUrn]]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status < 200 || $status >= 300 || empty($data['value']['uploadUrl'])) {
        throw new RuntimeException("Image init failed {$status}: {$body}");
    }
    $value = $data['value'];

    $ch = curl_init($value['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => file_get_contents($imagePath),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/octet-stream'],
    ]);
    curl_exec($ch);
    $putStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($putStatus < 200 || $putStatus >= 300) {
        throw new RuntimeException("Image upload PUT failed with status {$putStatus}");
    }

    return $value['image'];
}

function li_upload_document(string $accessToken, string $actingUrn, string $pdfPath): string
{
    $ch = curl_init(LI_API_BASE . '/rest/documents?action=initializeUpload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode(['initializeUploadRequest' => ['owner' => $actingUrn]]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true) ?? [];
    if ($status < 200 || $status >= 300 || empty($data['value']['uploadUrl'])) {
        throw new RuntimeException("Document init failed {$status}: {$body}");
    }
    $value = $data['value'];

    $ch = curl_init($value['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => file_get_contents($pdfPath),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/octet-stream'],
    ]);
    curl_exec($ch);
    $putStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($putStatus < 200 || $putStatus >= 300) {
        throw new RuntimeException("Document upload PUT failed with status {$putStatus}");
    }

    return $value['document'];
}

// $mentionCandidates maps connected-account display name => URN, so any
// "@[Name]" the user inserted via the "Tag a Page" toolbar button
// becomes a real LinkedIn mention. Every other reserved character in
// the commentary is escaped here too — see includes/linkedin_text.php.
function li_create_post(string $accessToken, string $actingUrn, string $commentary, ?array $content = null, array $mentionCandidates = []): string
{
    $body = [
        'author'         => $actingUrn,
        'commentary'     => li_build_commentary($commentary, $mentionCandidates),
        'visibility'     => 'PUBLIC',
        'distribution'   => ['feedDistribution' => 'MAIN_FEED'],
        'lifecycleState' => 'PUBLISHED',
    ];
    if ($content) {
        $body['content'] = $content;
    }

    $ch = curl_init(LI_API_BASE . '/rest/posts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Post failed {$status}: " . substr($response, $headerSize));
    }

    $headerText = substr($response, 0, $headerSize);
    if (preg_match('/^x-restli-id:\s*(.+)$/mi', $headerText, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

// reshareContext.parent rejects "activity" URNs outright ("Allowed URN
// types are groupPost, share, ugcPost") even though every public post
// URL/permalink — and li_parse_post_urn()'s output for one — is an
// activity URN regardless of what the post's real underlying type is.
// The numeric ID is shared across all of these; only the type prefix
// differs. There's no reliable way to know which of the two ordinary-
// post types (share vs ugcPost) a given activity ID really is without
// an extra read call this app may not have permission for either, so
// this tries both against the same numeric ID, in the order they're
// most commonly the right one, rather than guess once and fail.
// Anything already share/ugcPost/groupPost (an admin can paste those
// directly, and li_parse_post_urn() preserves them as-is) needs no
// conversion — just itself, once.
function li_reshare_parent_candidates(string $urn): array
{
    if (preg_match('#^urn:li:activity:(\d+)$#', $urn, $m)) {
        return ["urn:li:share:{$m[1]}", "urn:li:ugcPost:{$m[1]}"];
    }
    return [$urn];
}

// Reposts a target post (optionally "with your thoughts") by creating a
// new post with reshareContext.parent set to it — the exact same Posts
// API used everywhere else in this file, just with two extra fields, so
// it needs no product access this app doesn't already have. LinkedIn
// requires 'commentary' on every post (even a plain repost with nothing
// added) — omitting it entirely 422s with "field is required but not
// found" — so an empty $commentary sends an empty string rather than
// skipping the key, unlike li_create_post()'s optional 'content'. Same
// x-restli-id-header return convention as li_create_post(). See
// li_like_post()'s doc comment for the LI_ENGAGEMENT_API_OVERRIDE test
// seam — added here too since, unlike li_create_post(), this is new/
// unverified-against-real-LinkedIn code.
function li_create_repost(string $accessToken, string $actingUrn, string $targetUrn, string $commentary = '', array $mentionCandidates = []): string
{
    if (defined('LI_ENGAGEMENT_API_OVERRIDE')) {
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake_fail') {
            throw new RuntimeException('Repost failed 422: simulated failure');
        }
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake') {
            return 'urn:li:share:(fake,' . bin2hex(random_bytes(4)) . ')';
        }
    }

    $candidates = li_reshare_parent_candidates($targetUrn);
    $lastError = null;
    foreach ($candidates as $i => $parentUrn) {
        $body = [
            'author'         => $actingUrn,
            'commentary'     => li_build_commentary($commentary, $mentionCandidates),
            'visibility'     => 'PUBLIC',
            'distribution'   => ['feedDistribution' => 'MAIN_FEED'],
            'lifecycleState' => 'PUBLISHED',
            'reshareContext' => ['parent' => $parentUrn],
        ];

        $ch = curl_init(LI_API_BASE . '/rest/posts');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HEADER         => true,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            $headerText = substr($response, 0, $headerSize);
            if (preg_match('/^x-restli-id:\s*(.+)$/mi', $headerText, $m)) {
                return trim($m[1]);
            }
            return 'unknown';
        }

        $responseBody = substr($response, $headerSize);
        $lastError = "Repost failed {$status}: {$responseBody}";
        // Worth trying the next candidate type for the explicit "wrong
        // URN type" rejection (422, named field/message), AND for a
        // bare 403 — LinkedIn's generic "Accessing the resource is
        // forbidden" gives no field-level detail, so it's genuinely
        // ambiguous whether that means "this specific type+id doesn't
        // resolve to a real object" (the other candidate might) or "no
        // permission to reshare this content at all" (both candidates
        // will fail identically) — trying the second guess is cheap and
        // resolves that ambiguity; anything else (auth, rate limit, a
        // genuinely bad request) would fail identically on the next
        // candidate too, so isn't retried.
        $isWrongUrnType = $status === 422 && str_contains($responseBody, 'reshareContext/parent') && str_contains($responseBody, 'Allowed URN types');
        $isAmbiguousForbidden = $status === 403;
        if ((!$isWrongUrnType && !$isAmbiguousForbidden) || $i === count($candidates) - 1) {
            throw new RuntimeException($lastError);
        }
    }

    throw new RuntimeException($lastError ?? 'Repost failed: no candidate URN types available.');
}

// ── Dormant — not called by includes/engagement.php's current flow ──
// These hit LinkedIn's Social Actions API (/rest/socialActions), which
// turned out to require Community Management API partner approval —
// a separate, reviewed product, not covered by the self-serve
// w_member_social scope this app already has for publishing (confirmed
// via a live 403 ACCESS_DENIED / partnerApiSocialActions.CREATE — see
// the engagement feature's build history for the full investigation).
// engagement_like()/engagement_comment() in includes/engagement.php
// instead redirect the member to the post on LinkedIn and self-report
// the action, which needs no LinkedIn approval at all. Kept here,
// untouched and still correct, in case Community Management API access
// is granted later and a "verified" mode gets wired back in.
//
// LI_ENGAGEMENT_API_OVERRIDE (define in config.php, never committed) —
// same seam as includes/pdf_builder.php's PDF_ENGINE_OVERRIDE — lets
// local/test runs exercise these without ever calling api.linkedin.com.
// 'fake' pretends LinkedIn accepted the call; 'fake_fail' throws the
// same exception shape a real failure would.
function li_like_post(string $accessToken, string $actorUrn, string $targetUrn): void
{
    if (defined('LI_ENGAGEMENT_API_OVERRIDE')) {
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake_fail') {
            throw new RuntimeException('Like failed 429: rate limited (simulated)');
        }
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake') {
            return;
        }
    }

    $ch = curl_init(LI_API_BASE . '/rest/socialActions/' . rawurlencode($targetUrn) . '/likes');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode(['actor' => $actorUrn]),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Like failed {$status}: {$body}");
    }
}

// Social Actions API — post a comment on a target post/share/ugcPost.
// Returns the created comment's URN from the x-restli-id response
// header, same header-parsing convention li_create_post() uses; falls
// back to 'unknown' if LinkedIn omits it. See li_like_post() above for
// the LI_ENGAGEMENT_API_OVERRIDE test seam.
function li_create_comment(string $accessToken, string $actorUrn, string $targetUrn, string $commentText): string
{
    if (defined('LI_ENGAGEMENT_API_OVERRIDE')) {
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake_fail') {
            throw new RuntimeException('Comment failed 429: rate limited (simulated)');
        }
        if (LI_ENGAGEMENT_API_OVERRIDE === 'fake') {
            return 'urn:li:comment:(fake,' . bin2hex(random_bytes(4)) . ')';
        }
    }

    $ch = curl_init(LI_API_BASE . '/rest/socialActions/' . rawurlencode($targetUrn) . '/comments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => li_json_headers($accessToken),
        CURLOPT_POSTFIELDS     => json_encode([
            'actor'   => $actorUrn,
            'message' => ['text' => $commentText],
        ]),
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Comment failed {$status}: " . substr($response, $headerSize));
    }

    $headerText = substr($response, 0, $headerSize);
    if (preg_match('/^x-restli-id:\s*(.+)$/mi', $headerText, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

// Orchestrates the image/document/text branching used by both the
// "Post Now" endpoint and the scheduled cron sweep, so the two never
// drift out of sync on posting behavior.
//
// $title is what LinkedIn actually displays as the media title — most
// visibly, the caption shown directly under a carousel's swipeable PDF
// in the feed — so it should be the post's human-readable topic/title,
// not the internal campaign ID. Falls back to $campaignId when blank.
function li_publish_post(string $accessToken, string $actingUrn, string $format, string $caption, string $campaignId, array $slidePaths, string $title = '', array $mentionCandidates = []): string
{
    $mediaTitle = $title !== '' ? $title : $campaignId;

    if (in_array($format, ['Text Post', 'Poll'], true) || empty($slidePaths)) {
        return li_create_post($accessToken, $actingUrn, $caption, null, $mentionCandidates);
    }

    if ($format === 'Single Image' || count($slidePaths) === 1) {
        $imageUrn = li_upload_image($accessToken, $actingUrn, $slidePaths[0]);
        return li_create_post($accessToken, $actingUrn, $caption, [
            'media' => ['title' => $mediaTitle, 'id' => $imageUrn],
        ], $mentionCandidates);
    }

    // Carousel: combine slides into a single PDF, upload as a document.
    // The temp filename itself is never shown anywhere (LinkedIn only
    // displays the "title" field set on the post below) but it's still
    // named from the title for clarity in server-side debugging/logs.
    $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '', $mediaTitle);
    $safeName = trim($safeName) !== '' ? trim($safeName) : $campaignId;
    $pdfPath = sys_get_temp_dir() . '/' . $safeName . '_' . bin2hex(random_bytes(4)) . '.pdf';
    build_carousel_pdf($slidePaths, $pdfPath);
    try {
        $docUrn = li_upload_document($accessToken, $actingUrn, $pdfPath);
        sleep(3); // LinkedIn needs a moment to finish processing the uploaded document.
        return li_create_post($accessToken, $actingUrn, $caption, [
            'media' => ['title' => $mediaTitle, 'id' => $docUrn],
        ], $mentionCandidates);
    } finally {
        @unlink($pdfPath);
    }
}

// Shared by api/post_now.php and pages/new_post.php's own "Post Now"
// action — both need identical validation and the same publish/record
// logic, so it lives in one place. Callers must have already loaded
// db.php, helpers.php (get_enabled_formats) and post_helpers.php
// (fetch_post(), get_mention_candidates), same as everywhere else in
// this codebase.
function publish_post_now(int $postId, int $userId): array
{
    // fetch_post() applies the same owns-OR-granted access check as
    // everywhere else a shared workspace's posts are read — a teammate
    // granted this page can Post Now the same as its owner.
    $post = fetch_post($postId, $userId);

    if (!$post) {
        return ['success' => false, 'error' => 'Post not found', 'status_code' => 404];
    }
    if (!$post['linkedin_account_id']) {
        return ['success' => false, 'error' => 'Assign a LinkedIn account to this post before posting.', 'status_code' => 422];
    }
    // No ownership re-check here: linkedin_account_id is only ever set
    // through account_usable_in_workspace()-validated writes (New Post,
    // Post edit, Bulk Schedule's account assignment), so by the time a
    // post reaches here the assignment is already legitimate — same
    // trust-at-use-time pattern cron/auto_post.php relies on.
    $acctStmt = db()->prepare('SELECT access_token, target_urn, status AS account_status FROM linkedin_accounts WHERE id = ?');
    $acctStmt->execute([$post['linkedin_account_id']]);
    $account = $acctStmt->fetch();
    $post = array_merge($post, $account ?: ['access_token' => null, 'target_urn' => null, 'account_status' => null]);
    if (!$account || $post['account_status'] !== 'active') {
        return ['success' => false, 'error' => 'The connected LinkedIn account needs to be reconnected.', 'status_code' => 422];
    }
    if (!in_array($post['format'], get_enabled_formats($userId), true)) {
        return ['success' => false, 'error' => "\"{$post['format']}\" posting is disabled in Settings.", 'status_code' => 422];
    }
    $orgId = user_organization_id($userId);
    if ($orgId && !org_within_limit($orgId, 'posts')) {
        return ['success' => false, 'error' => "Your organization's plan has reached its monthly post limit.", 'status_code' => 422];
    }

    $slideStmt = db()->prepare('SELECT filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
    $slideStmt->execute([$postId]);
    $slidePaths = array_column($slideStmt->fetchAll(), 'filepath');

    try {
        $postUrn = li_publish_post(
            $post['access_token'],
            $post['target_urn'],
            $post['format'],
            $post['caption'] ?? '',
            $post['campaign_id'] ?? '',
            $slidePaths,
            $post['title'] ?? '',
            get_mention_candidates($userId)
        );

        // Calendar only shows posts with scheduled_at set (pages/calendar.php).
        // A draft posted straight via "Post Now" without ever being
        // scheduled would otherwise never appear there at all — backfill
        // it to the actual post time so it shows up under today. Leaves
        // an existing scheduled_at (the date it was originally planned
        // for) untouched.
        $upd = db()->prepare('UPDATE posts SET status = "posted", posted_at = NOW(), scheduled_at = COALESCE(scheduled_at, NOW()), li_post_urn = ?, error_message = NULL WHERE id = ?');
        $upd->execute([$postUrn, $postId]);

        return ['success' => true, 'post_urn' => $postUrn];
    } catch (Throwable $e) {
        $upd = db()->prepare('UPDATE posts SET status = "failed", error_message = ? WHERE id = ?');
        $upd->execute([$e->getMessage(), $postId]);

        return ['success' => false, 'error' => $e->getMessage(), 'status_code' => 500];
    }
}
