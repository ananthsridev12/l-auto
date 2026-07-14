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
// No pillar/category context at preview time (same as the actual save
// path for New Post) — defaults to the personal footer image slot.
$photoPath = resolve_footer_image($userId, 'personal', $workspaceId);

// Fixed, per-user scratch path — cleared on every preview rather than
// accumulating files (e.g. switching Carousel -> Single Image would
// otherwise leave the carousel's extra slide files behind), since the
// post doesn't exist yet here.
$outDir = UPLOAD_DIR . '/' . $userId . '/_preview';
foreach (glob($outDir . '/*.png') ?: [] as $stale) {
    unlink($stale);
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
