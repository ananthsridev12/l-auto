<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pinterest_oauth.php';

require_login();

if (empty($_SESSION['pinterest_pending_token'])) {
    flash('error', 'Your Pinterest session expired — please reconnect.');
    redirect('pages/accounts.php');
}

$accessToken  = $_SESSION['pinterest_pending_token'];
$refreshToken = $_SESSION['pinterest_pending_refresh_token'] ?? null;
$expiresAt    = $_SESSION['pinterest_pending_expires_at'] ?? null;
$scope        = $_SESSION['pinterest_pending_scope'] ?? 'boards:read,pins:read,pins:write';
$userId       = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    $selected = $_POST['boards'] ?? [];
    $names    = $_POST['board_name'] ?? [];
    foreach ($selected as $boardId) {
        $name = $names[$boardId] ?? $boardId;
        pinterest_upsert_social_account($userId, $boardId, $name, $accessToken, $refreshToken, $expiresAt, $scope);
    }
    unset($_SESSION['pinterest_pending_token'], $_SESSION['pinterest_pending_refresh_token'], $_SESSION['pinterest_pending_expires_at'], $_SESSION['pinterest_pending_scope']);
    flash('success', count($selected) . ' Pinterest board(s) connected.');
    redirect('pages/accounts.php');
}

try {
    $boards = pinterest_list_boards($accessToken);
} catch (Throwable $e) {
    flash('error', 'Could not list your Pinterest boards: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$pageTitle = 'Choose Pinterest Boards';
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
  <h1>Choose Pinterest Boards</h1>
  <p class="subtitle">Select the boards you'd like to post Pins to.</p>

  <?php if (empty($boards)): ?>
    <p>No boards found on your Pinterest account.</p>
    <a href="<?= h(app_path('pages/accounts.php')) ?>" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">Back to Accounts</a>
  <?php else: ?>
    <form method="post" class="stacked-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php foreach ($boards as $board): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="boards[]" value="<?= h($board['id']) ?>" checked>
          <?= h($board['name']) ?>
          <input type="hidden" name="board_name[<?= h($board['id']) ?>]" value="<?= h($board['name']) ?>">
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary">Connect Selected Boards</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
