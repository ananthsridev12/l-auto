<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/settings.php');
    }

    $name = trim($_POST['name'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    $stmt = db()->prepare('UPDATE users SET name = ? WHERE id = ?');
    $stmt->execute([$name, $userId]);

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    flash('success', 'Settings saved.');
    redirect('pages/settings.php');
}

$pageTitle  = 'Settings';
$activePage = 'settings';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Settings</h1></div>

<section class="card">
  <h2>Profile</h2>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Name
      <input type="text" name="name" value="<?= h($user['name']) ?>">
    </label>
    <label>Email
      <input type="email" value="<?= h($user['email']) ?>" disabled>
    </label>
    <label>New Password <span class="muted">(leave blank to keep current)</span>
      <input type="password" name="new_password" minlength="8">
    </label>
    <button type="submit" class="btn-primary">Save</button>
  </form>
</section>

<section class="card">
  <h2>LinkedIn Accounts</h2>
  <p class="muted">Manage which personal profile and Company Pages are connected from the <a href="<?= h(app_path('pages/accounts.php')) ?>">Accounts</a> page.</p>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
