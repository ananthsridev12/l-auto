<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/pdf_builder.php';
require_once __DIR__ . '/linkedin_text.php';

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
// (get_mention_candidates), same as everywhere else in this codebase.
function publish_post_now(int $postId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, la.access_token, la.target_urn, la.status AS account_status
         FROM posts p
         LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
         WHERE p.id = ? AND p.user_id = ?'
    );
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();

    if (!$post) {
        return ['success' => false, 'error' => 'Post not found', 'status_code' => 404];
    }
    if (!$post['linkedin_account_id']) {
        return ['success' => false, 'error' => 'Assign a LinkedIn account to this post before posting.', 'status_code' => 422];
    }
    if ($post['account_status'] !== 'active') {
        return ['success' => false, 'error' => 'The connected LinkedIn account needs to be reconnected.', 'status_code' => 422];
    }
    if (!in_array($post['format'], get_enabled_formats($userId), true)) {
        return ['success' => false, 'error' => "\"{$post['format']}\" posting is disabled in Settings.", 'status_code' => 422];
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
