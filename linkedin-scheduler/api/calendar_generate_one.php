<?php
// Step 2 of the Content Calendar Generator, called once per planned post
// by the browser's progress loop (see assets/js/calendar_batch.js) — one
// AI call per request keeps this fast and means a single failed row
// doesn't lose the rest of the batch. Uses the post's assigned content
// pillar as the topic (there's no more specific topic for an auto-
// planned slot) and its assigned persona, same generation path New
// Post's AI panel uses.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/creative_builder.php';
require_once __DIR__ . '/../includes/ai_generate.php';

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

$aiConfig = resolve_ai_config($userId);
if (!ai_configured($aiConfig)) {
    $label = AI_PROVIDER_LABELS[$aiConfig['provider']] ?? ucfirst($aiConfig['provider']);
    json_response(['success' => false, 'error' => "Add a {$label} API key in Settings first."], 422);
}

$pillar = $post['content_pillar_id'] ? fetch_content_pillar($userId, (int) $post['content_pillar_id']) : null;
$persona = $post['persona_id'] ? fetch_persona($userId, (int) $post['persona_id']) : null;

$row = [
    'Topic / Title'  => $pillar['name'] ?? 'General update',
    'Target Persona' => $persona['name'] ?? '',
    'Type'           => $pillar['name'] ?? '',
    'CTA'            => '',
    'Tag Page'       => '',
    'Post Caption'   => '',
    'Final_Format'   => $post['format'],
];

// The post's workspace (set at plan time) carries the knowledge-hub
// context; the legacy brief pair only applies to pre-workspace posts.
$workspace = !empty($post['workspace_id']) ? fetch_workspace($userId, (int) $post['workspace_id']) : null;
$wsId = $workspace ? (int) $workspace['id'] : null;
$brief = $workspace ? null : resolve_brief_for_pillar($userId, $pillar);

try {
    $creative = generate_creative_via_ai($row, $aiConfig, $brief, $persona, $pillar, $workspace);
    // Same auto-assignment Content Studio's CSV upload uses — see
    // includes/post_helpers.php resolve_default_layout().
    $creative['layout'] = resolve_default_layout($userId, $creative['format'], $pillar['name'] ?? null, $wsId);
    $paletteDefault = resolve_default_palette($userId, $creative['format'], $pillar['name'] ?? null, $wsId);
    if ($paletteDefault !== null && empty($creative['template'])) {
        $creative['template'] = str_starts_with($paletteDefault, 'custom:') ? $paletteDefault : (int) $paletteDefault;
    }
} catch (Throwable $e) {
    // Persisted (not just returned in this response) so the failure
    // reason survives to the content-review screen even after the
    // bulk-generation loop that triggered this has already moved on and
    // redirected — otherwise a failed row just silently shows "No
    // content yet." with no indication of why.
    db()->prepare('UPDATE posts SET error_message = ? WHERE id = ?')->execute([$e->getMessage(), $postId]);
    json_response(['success' => false, 'error' => $e->getMessage(), 'post_id' => $postId], 502);
}

$update = db()->prepare('UPDATE posts SET creative_json = ?, title = ?, caption = ?, error_message = NULL WHERE id = ?');
$update->execute([json_encode($creative), $creative['title'] ?? null, $creative['caption'] ?? null, $postId]);

json_response(['success' => true, 'post_id' => $postId, 'creative' => $creative]);
