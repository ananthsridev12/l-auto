<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csv_parser.php';
require_once __DIR__ . '/../includes/content_studio_parser.php';
require_once __DIR__ . '/../includes/creative_builder.php';
require_once __DIR__ . '/../includes/ai_generate.php';

require_login();
$userId = current_user_id();
$aiConfig = resolve_ai_config($userId);
$brandBrief = get_brand_brief($userId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv']['tmp_name'])) {
    json_response(['success' => false, 'error' => 'No CSV file uploaded'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

try {
    $preview = content_studio_build_preview($_FILES['csv']['tmp_name']);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Could not parse CSV: ' . $e->getMessage()], 422);
}

// For each row that needs it, build the creative JSON now (mechanically
// from Creative Content if present, else via Gemini) so the review screen
// shows real generated copy rather than making the user trigger it later.
foreach ($preview['rows'] as &$entry) {
    if ($entry['skip']) {
        continue;
    }
    $row = $entry['row'];
    $mechanical = build_creative_from_row($row);
    if ($mechanical !== null) {
        $entry['source'] = 'written';
        $entry['creative'] = $mechanical;
        continue;
    }
    if (!ai_configured($aiConfig)) {
        $entry['skip'] = true;
        $entry['skip_reason'] = 'No Creative Content, and no AI provider configured in Settings';
        continue;
    }
    try {
        $entry['source'] = 'ai';
        $entry['creative'] = generate_creative_via_ai($row, $aiConfig, $brandBrief);
    } catch (Throwable $e) {
        $entry['skip'] = true;
        $entry['skip_reason'] = 'AI generation failed: ' . $e->getMessage();
    }
}
unset($entry);

$stmt = db()->prepare('SELECT id, display_name, account_type FROM linkedin_accounts WHERE user_id = ? AND status = "active" ORDER BY display_name');
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();

$suggested = [];
foreach ($preview['page_labels'] as $label) {
    $suggested[$label] = null;
    foreach ($accounts as $acct) {
        if (strcasecmp(trim($acct['display_name']), $label) === 0) {
            $suggested[$label] = (int) $acct['id'];
            break;
        }
    }
}

json_response([
    'success'           => true,
    'rows'              => $preview['rows'],
    'page_labels'       => $preview['page_labels'],
    'suggested_matches' => $suggested,
    'accounts'          => $accounts,
    'csv_filename'      => $_FILES['csv']['name'],
    'ai_configured'     => ai_configured($aiConfig),
    'ai_provider'       => $aiConfig['provider'],
]);
