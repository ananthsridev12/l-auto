<?php
// Persists the sidebar theme toggle's choice (includes/layout_top.php).
// Called via fetch() in the background — the toggle already flips
// data-theme on <html> client-side before this resolves, so the
// response body doesn't drive any UI; it's just for next page load.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

$theme = $_POST['theme'] ?? '';
$theme = in_array($theme, ['light', 'dark'], true) ? $theme : null;
set_user_theme(current_user_id(), $theme);

json_response(['success' => true]);
