<?php
// Renders the in-progress creative JSON (from either "Generate with AI"
// or "Write content directly") to real PNG(s) without creating a post
// row, so New Post can show what the image actually looks like before
// Save Draft/Schedule/Post Now — previously the only way to see the
// rendered image was to already have committed the post.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/stock_images.php';

require_login();
$userId = current_user_id();
$workspaceId = current_workspace_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$creative = json_decode($_POST['creative_json'] ?? '', true);
if (!is_array($creative) || empty($creative['slides'])) {
    json_response(['success' => false, 'error' => 'Nothing to preview yet — fill in the slide content first.'], 422);
}

$user = current_user();
$footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
// No pillar context at preview time (same as the actual save path for
// New Post) — category still follows the active workspace's type, so
// a Company-workspace preview shows the logo, not the personal photo.
$photoPath = resolve_footer_image(workspace_brand_user_id($userId, $workspaceId), resolve_post_category(current_workspace()), $workspaceId);

// Fixed, per-user scratch path — cleared on every preview rather than
// accumulating files (e.g. switching Carousel -> Single Image would
// otherwise leave the carousel's extra slide files behind), since the
// post doesn't exist yet here.
$outDir = UPLOAD_DIR . '/' . $userId . '/_preview';
foreach (glob($outDir . '/*.png') ?: [] as $stale) {
    unlink($stale);
}

// Background: Stock/AI Photo — mirrors pages/new_post.php's actual
// save-path handling exactly, so this preview really is "what will be
// saved" (see new_post_ai.js's previewImageBtn handler, which also
// forwards these same bg_* fields alongside creative_json). The
// Unsplash download-location ping is deliberately skipped here (empty
// string, not the real value) — that's Unsplash's "this photo was
// actually used" signal, and a preview can be clicked many times
// before (or without) ever actually saving; the real save path pings
// it for real exactly once.
try {
    $bgPath = save_stock_or_ai_background($userId, $outDir, trim($_POST['bg_stock_image_url'] ?? ''), trim($_POST['bg_stock_ai_image_b64'] ?? ''), '');
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Could not use the selected background photo: ' . $e->getMessage()], 422);
}
if ($bgPath !== null) {
    // Mirrors pages/new_post.php's save-path handling — don't stomp
    // 'side_image' (fading side-photo) if that's what was picked, since
    // it also consumes background_image_override, just rendered
    // differently.
    if (($creative['background'] ?? '') !== 'side_image') {
        $creative['background'] = 'image';
    }
    $creative['background_image_override'] = $bgPath;
}

try {
    $slides = render_creative_to_slides($creative, $outDir, $footerName, $photoPath, $userId, $workspaceId);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}

json_response([
    'success' => true,
    'slides'  => array_map(fn ($s) => ['url' => slide_public_url($s['filepath'])], $slides),
]);
