<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csv_parser.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv']['tmp_name'])) {
    json_response(['success' => false, 'error' => 'No CSV file uploaded'], 400);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_response(['success' => false, 'error' => 'Session expired, please reload and try again.'], 419);
}

try {
    $preview = csv_build_preview($_FILES['csv']['tmp_name']);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => 'Could not parse CSV: ' . $e->getMessage()], 422);
}

$stmt = db()->prepare('SELECT id, display_name, account_type FROM linkedin_accounts WHERE user_id = ? AND status = "active" ORDER BY display_name');
$stmt->execute([current_user_id()]);
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
]);
