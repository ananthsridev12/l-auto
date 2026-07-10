<?php
// Shared by New Post's "Generate with AI" panel — a single-row version
// of what api/content_studio_preview.php does per CSV row, for composing
// one ad-hoc post instead of a batch import.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
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

$geminiKey = get_gemini_api_key($userId);
if (!gemini_configured($geminiKey)) {
    json_response(['success' => false, 'error' => 'Add a Gemini API key in Settings first.'], 422);
}

$format = trim($_POST['format'] ?? '');
if (!in_array($format, ['Text Post', 'Single Image', 'Carousel'], true)) {
    json_response(['success' => false, 'error' => 'Choose a post format first.'], 422);
}

$row = [
    'Topic / Title'  => trim($_POST['topic'] ?? ''),
    'Target Persona' => trim($_POST['persona'] ?? ''),
    'Type'           => trim($_POST['type'] ?? ''),
    'CTA'            => trim($_POST['cta'] ?? ''),
    'Tag Page'       => trim($_POST['tag_page'] ?? ''),
    'Post Caption'   => trim($_POST['caption'] ?? ''),
    'Final_Format'   => $format,
];

if ($row['Topic / Title'] === '' && $row['Post Caption'] === '') {
    json_response(['success' => false, 'error' => 'Enter at least a topic/title (or a caption) to generate from.'], 422);
}

try {
    $creative = generate_creative_via_gemini($row, $geminiKey);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

json_response(['success' => true, 'creative' => $creative]);
