<?php
// Shared by New Post's "Generate with AI" panel — a single-row version
// of what api/content_studio_preview.php does per CSV row, for composing
// one ad-hoc post instead of a batch import.

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

$aiConfig = resolve_ai_config($userId);
if (!ai_configured($aiConfig)) {
    $label = AI_PROVIDER_LABELS[$aiConfig['provider']] ?? ucfirst($aiConfig['provider']);
    json_response(['success' => false, 'error' => "Add a {$label} API key in Settings first."], 422);
}

$format = trim($_POST['format'] ?? '');
if (!in_array($format, ['Text Post', 'Single Image', 'Carousel'], true)) {
    json_response(['success' => false, 'error' => 'Choose a post format first.'], 422);
}

// Knowledge Base picks (dropdowns in New Post's AI panel) take priority
// over the free-text fallback fields when present.
$personaId = (int) ($_POST['persona_id'] ?? 0);
$pillarId  = (int) ($_POST['pillar_id'] ?? 0);
$ctaId     = (int) ($_POST['cta_id'] ?? 0);

$persona = $personaId ? fetch_persona($userId, $personaId) : null;
$pillar  = $pillarId ? fetch_content_pillar($userId, $pillarId) : null;
$cta     = $ctaId ? fetch_cta($userId, $ctaId) : null;

$row = [
    'Topic / Title'  => trim($_POST['topic'] ?? ''),
    'Target Persona' => $persona['name'] ?? trim($_POST['persona'] ?? ''),
    'Type'           => $pillar['name'] ?? trim($_POST['type'] ?? ''),
    'CTA'            => $cta['text'] ?? trim($_POST['cta'] ?? ''),
    'Tag Page'       => trim($_POST['tag_page'] ?? ''),
    'Post Caption'   => trim($_POST['caption'] ?? ''),
    'Final_Format'   => $format,
];

if ($row['Topic / Title'] === '' && $row['Post Caption'] === '') {
    json_response(['success' => false, 'error' => 'Enter at least a topic/title (or a caption) to generate from.'], 422);
}

$brandBrief = get_brand_brief($userId);

try {
    $creative = generate_creative_via_ai($row, $aiConfig, $brandBrief, $persona, $pillar);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

json_response(['success' => true, 'creative' => $creative]);
