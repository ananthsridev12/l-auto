<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gbp_oauth.php';

require_login();

if (empty($_SESSION['gbp_pending_token'])) {
    flash('error', 'Your Google session expired — please reconnect.');
    redirect('pages/accounts.php');
}

$accessToken  = $_SESSION['gbp_pending_token'];
$refreshToken = $_SESSION['gbp_pending_refresh_token'] ?? null;
$expiresAt    = $_SESSION['gbp_pending_expires_at'] ?? null;
$scope        = $_SESSION['gbp_pending_scope'] ?? 'https://www.googleapis.com/auth/business.manage';
$userId       = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    $selected = $_POST['locations'] ?? [];
    $titles   = $_POST['location_title'] ?? [];
    foreach ($selected as $locationId) {
        $title = $titles[$locationId] ?? $locationId;
        gbp_upsert_social_account($userId, $locationId, $title, $accessToken, $refreshToken, $expiresAt, $scope);
    }
    unset($_SESSION['gbp_pending_token'], $_SESSION['gbp_pending_refresh_token'], $_SESSION['gbp_pending_expires_at'], $_SESSION['gbp_pending_scope']);
    flash('success', count($selected) . ' Google Business Profile location(s) connected.');
    redirect('pages/accounts.php');
}

try {
    $locations = gbp_list_locations($accessToken);
} catch (Throwable $e) {
    flash('error', 'Could not list your Google Business Profile locations: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$pageTitle = 'Choose Business Profile Locations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <h1>Choose Business Profile Locations</h1>
  <p class="subtitle">Select the locations you'd like to post updates to.</p>

  <?php if (empty($locations)): ?>
    <p>No locations found on your Google Business Profile account.</p>
    <a href="<?= h(app_path('pages/accounts.php')) ?>" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">Back to Accounts</a>
  <?php else: ?>
    <form method="post" class="stacked-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php foreach ($locations as $loc): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="locations[]" value="<?= h($loc['id']) ?>" checked>
          <?= h($loc['title']) ?>
          <input type="hidden" name="location_title[<?= h($loc['id']) ?>]" value="<?= h($loc['title']) ?>">
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary">Connect Selected Locations</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
