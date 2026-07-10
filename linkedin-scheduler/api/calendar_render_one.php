<?php
// Step 2's per-post render, called once per approved post by the
// browser's progress loop — same reasoning as calendar_generate_one.php
// for keeping this fast and failure-isolated per row.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$postId = (int) ($_POST['post_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM posts WHERE id = ? AND user_id = ? AND calendar_batch_id IS NOT NULL');
$stmt->execute([$postId, $userId]);
$post = $stmt->fetch();
if (!$post) {
    json_response(['success' => false, 'error' => 'Post not found.'], 404);
}
if (!$post['content_approved_at']) {
    json_response(['success' => false, 'error' => 'Approve this post\'s content before generating its image.'], 422);
}
if ($post['format'] === 'Text Post') {
    json_response(['success' => false, 'error' => 'Text Post has no image to generate.'], 422);
}

$creative = json_decode((string) $post['creative_json'], true);
if (!$creative || empty($creative['slides'])) {
    json_response(['success' => false, 'error' => 'No generated content to render for this post.'], 422);
}
$creative['format'] = $post['format'] === 'Single Image' ? 'single' : 'carousel';

$user = current_user();
$footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
$pillar = $post['content_pillar_id'] ? fetch_content_pillar($userId, (int) $post['content_pillar_id']) : null;
$category = ($pillar['category'] ?? 'company') === 'personal' ? 'personal' : 'company';
$photoPath = resolve_footer_image($userId, $category);

$campaignId = $post['campaign_id'];
$destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);

try {
    $slides = render_creative_to_slides($creative, $destDir, $footerName, $photoPath, $userId);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage(), 'post_id' => $postId], 500);
}

db()->prepare('DELETE FROM post_slides WHERE post_id = ?')->execute([$postId]);
$insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
foreach ($slides as $order => $slide) {
    $insertSlide->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
}

json_response([
    'success' => true,
    'post_id' => $postId,
    'slides'  => array_map(fn ($s) => ['url' => slide_public_url($s['filepath'])], $slides),
]);
