<?php
// New Post's "Stock/AI Photo" panel — AI-generate tab. A plain
// generated photo/graphic (not a branded slide — see
// api/ai_generate_preview.php for that), returned as a data: URL so
// the client can preview and "use" it without a second round trip.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_generate.php';

require_login();
$userId = current_user_id();

if (!module_enabled('ai_generation')) {
    json_response(['success' => false, 'error' => 'AI generation is not enabled for your organization.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$prompt = trim($_POST['prompt'] ?? '');
if ($prompt === '') {
    json_response(['success' => false, 'error' => 'Describe the image you want.'], 422);
}

$aiConfig = resolve_ai_config($userId);
if (!ai_configured($aiConfig)) {
    $label = AI_PROVIDER_LABELS[$aiConfig['provider']] ?? ucfirst($aiConfig['provider']);
    json_response(['success' => false, 'error' => "Add a {$label} API key in Settings first."], 422);
}

try {
    $image = ai_generate_image($prompt, $aiConfig);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

json_response(['success' => true, 'data_url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes'])]);
