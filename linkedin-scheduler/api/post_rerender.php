<?php
// Re-renders a post's generated image from its (edited) creative JSON —
// the "Edit Image Content" card on pages/post.php. Only posts whose
// slides were rendered from a stored creative_json qualify (uploaded
// images have no content to re-edit); published posts are locked, same
// rule as the rest of that page.
//
// The client sends the edited creative, but only the fields the editor
// actually exposes are taken from it (slides, template, layout,
// background, size, text_position) — everything else (format,
// series_label, hashtags, caption) is kept from the stored creative, so
// a tampered request can't switch a post's format or smuggle in
// unrelated keys.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php'; // MAX_SLIDES_PER_CAMPAIGN
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
$stmt = db()->prepare('SELECT * FROM posts WHERE id = ? AND user_id = ?');
$stmt->execute([$postId, $userId]);
$post = $stmt->fetch();
if (!$post) {
    json_response(['success' => false, 'error' => 'Post not found.'], 404);
}
if ($post['status'] === 'posted') {
    json_response(['success' => false, 'error' => 'This post has already been published and can no longer be edited.'], 422);
}
if (!in_array($post['format'], ['Single Image', 'Carousel'], true)) {
    json_response(['success' => false, 'error' => 'This post format has no image to re-render.'], 422);
}

$creative = json_decode((string) $post['creative_json'], true);
if (!$creative || empty($creative['slides'])) {
    json_response(['success' => false, 'error' => 'This post\'s image wasn\'t generated from editable content.'], 422);
}

$edited = json_decode($_POST['creative_json'] ?? '', true);
if (!is_array($edited) || empty($edited['slides']) || !is_array($edited['slides'])) {
    json_response(['success' => false, 'error' => 'No slide content submitted.'], 422);
}
if (count($edited['slides']) > MAX_SLIDES_PER_CAMPAIGN) {
    json_response(['success' => false, 'error' => 'A Carousel can have at most ' . MAX_SLIDES_PER_CAMPAIGN . ' slides.'], 422);
}

$slides = [];
foreach (array_values($edited['slides']) as $i => $slide) {
    if (!is_array($slide)) {
        continue;
    }
    $points = array_values(array_filter(array_map('trim', (array) ($slide['points'] ?? [])), fn ($p) => $p !== ''));
    $slides[] = [
        'slide_number' => $i + 1,
        'headline'     => trim((string) ($slide['headline'] ?? '')),
        'body'         => trim((string) ($slide['body'] ?? '')),
        'points'       => $points,
    ];
}
if (!$slides) {
    json_response(['success' => false, 'error' => 'No slide content submitted.'], 422);
}
$creative['slides'] = $slides;

foreach (['template', 'layout', 'background', 'size', 'text_position', 'font_scale'] as $key) {
    if (isset($edited[$key]) && $edited[$key] !== '' && $edited[$key] !== null) {
        $creative[$key] = $edited[$key];
    } else {
        unset($creative[$key]);
    }
}
if (isset($creative['layout']) && !array_key_exists($creative['layout'], render_design_templates())) {
    unset($creative['layout']);
}

$creative['format'] = $post['format'] === 'Single Image' ? 'single' : 'carousel';

$user = current_user();
$footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
$pillar = $post['content_pillar_id'] ? fetch_content_pillar($userId, (int) $post['content_pillar_id']) : null;
$category = ($pillar['category'] ?? 'personal') === 'company' ? 'company' : 'personal';
$wsId = $post['workspace_id'] ? (int) $post['workspace_id'] : null;
$photoPath = resolve_footer_image($userId, $category, $wsId);

$destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $post['campaign_id']);

// The old slide files are removed before rendering — the new render may
// produce fewer slides (or the same names), and stale extras would
// otherwise linger on disk with no post_slides row pointing at them.
$oldStmt = db()->prepare('SELECT filepath FROM post_slides WHERE post_id = ?');
$oldStmt->execute([$postId]);
$oldPaths = array_column($oldStmt->fetchAll(), 'filepath');

try {
    $newSlides = render_creative_to_slides($creative, $destDir, $footerName, $photoPath, $userId, $wsId);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Image rendering failed: ' . $e->getMessage()], 500);
}

$newPaths = array_column($newSlides, 'filepath');
foreach ($oldPaths as $path) {
    if (!in_array($path, $newPaths, true)) {
        @unlink($path);
    }
}

db()->prepare('DELETE FROM post_slides WHERE post_id = ?')->execute([$postId]);
$insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
foreach ($newSlides as $order => $slide) {
    $insertSlide->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
}

db()->prepare('UPDATE posts SET creative_json = ? WHERE id = ?')->execute([json_encode($creative), $postId]);

// slide_public_url() itself appends a filemtime-based cache-buster now,
// so the freshly-rendered file (just imagepng()'d above) always gets a
// URL distinct from whatever the browser had cached pre-rerender.
json_response([
    'success' => true,
    'post_id' => $postId,
    'slides'  => array_map(fn ($s) => ['url' => slide_public_url($s['filepath'])], $newSlides),
]);
