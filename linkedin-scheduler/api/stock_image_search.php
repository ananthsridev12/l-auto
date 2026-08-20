<?php
// New Post's "Stock/AI Photo" panel — stock-search tab. Same skeleton
// as api/ai_generate_preview.php: require_login, method/csrf checks,
// call the include function, json_response.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stock_images.php';

require_login();
$userId = current_user_id();

if (!module_enabled('post_scheduling')) {
    json_response(['success' => false, 'error' => 'Post scheduling is not enabled for your organization.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$accessKey = get_unsplash_access_key($userId);
if (!unsplash_configured($accessKey)) {
    json_response(['success' => false, 'error' => 'Add an Unsplash Access Key in Settings first.'], 422);
}

$query = trim($_POST['query'] ?? '');
if ($query === '') {
    json_response(['success' => false, 'error' => 'Enter something to search for.'], 422);
}
$page = max(1, (int) ($_POST['page'] ?? 1));

try {
    $results = unsplash_search($query, $accessKey, $page);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
}

json_response(['success' => true, 'results' => $results]);
