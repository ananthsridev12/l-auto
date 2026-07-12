<?php
// Step 1 of the Content Calendar Generator: pure math, no AI calls, so
// this is always fast regardless of period length. Creates the
// calendar_batches row and one draft `posts` row per planned slot (date/
// pillar/persona/format assigned, but no caption/slides yet). The
// browser then loops calling api/calendar_generate_one.php once per post
// to fill in content — see pages/content_calendar.php /
// assets/js/calendar_batch.js for why this is split into two steps.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/calendar_planner.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$periodDays = (int) ($_POST['period_days'] ?? 0);
if (!in_array($periodDays, [7, 14, 30], true)) {
    json_response(['success' => false, 'error' => 'Choose a valid calendar period.'], 422);
}
$postsPerWeek = (int) ($_POST['posts_per_week'] ?? 0);
if ($postsPerWeek < 1 || $postsPerWeek > 7) {
    json_response(['success' => false, 'error' => 'Posts per week must be between 1 and 7.'], 422);
}

$workspaceId = current_workspace_id();
$workspace = current_workspace();
$pillars = fetch_content_pillars($userId, $workspaceId);
$pillarIds = array_column($pillars, 'id');
$rawPillarWeights = $_POST['pillar_weights'] ?? [];
$pillarWeights = [];
foreach ($rawPillarWeights as $pid => $pct) {
    $pid = (int) $pid;
    $pct = (float) $pct;
    if (in_array($pid, $pillarIds, true) && $pct > 0) {
        $pillarWeights[$pid] = $pct;
    }
}
if (!$pillarWeights) {
    json_response(['success' => false, 'error' => 'Set at least one content pillar percentage above 0.'], 422);
}

$enabledFormats = get_enabled_formats($userId);
$rawFormatWeights = $_POST['format_weights'] ?? [];
$formatWeights = [];
foreach ($rawFormatWeights as $fmt => $pct) {
    $pct = (float) $pct;
    if (in_array($fmt, $enabledFormats, true) && $pct > 0) {
        $formatWeights[$fmt] = $pct;
    }
}
if (!$formatWeights) {
    json_response(['success' => false, 'error' => 'Set at least one format percentage above 0 (check Settings if none of your formats are enabled).'], 422);
}

$personas = fetch_personas($userId, $workspaceId);
$mixConfig = [
    'period_days'     => $periodDays,
    'posts_per_week'  => $postsPerWeek,
    'pillar_weights'  => $pillarWeights,
    'format_weights'  => $formatWeights,
];
$plan = generate_calendar_plan($mixConfig, $pillars, $personas);

$pdo = db();
$pdo->beginTransaction();

$startDate = $plan[0]['date'] ?? date('Y-m-d', strtotime('+1 day'));
$batchStmt = $pdo->prepare(
    'INSERT INTO calendar_batches (user_id, workspace_id, period_days, posts_per_week, start_date, status, mix_config) VALUES (?, ?, ?, ?, ?, "content_review", ?)'
);
$batchStmt->execute([$userId, $workspaceId, $periodDays, $postsPerWeek, $startDate, json_encode($mixConfig)]);
$batchId = (int) $pdo->lastInsertId();

$insertPost = $pdo->prepare(
    'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, campaign_id, title, format, status, scheduled_at, calendar_batch_id, content_pillar_id, persona_id)
     VALUES (?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?)'
);
$postIds = [];
foreach ($plan as $i => $slot) {
    $campaignId = "CAL-{$batchId}-" . ($i + 1);
    $insertPost->execute([
        $userId, $workspaceId, $workspace['linkedin_account_id'] ?? null, $campaignId, null, $slot['format'], $slot['date'] . ' 09:00:00',
        $batchId, $slot['pillar_id'], $slot['persona_id'],
    ]);
    $postIds[] = (int) $pdo->lastInsertId();
}

$pdo->commit();

json_response(['success' => true, 'batch_id' => $batchId, 'post_ids' => $postIds]);
