<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'revoke') {
        $stmt = db()->prepare('UPDATE linkedin_accounts SET status = "revoked" WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        flash('success', 'Account removed.');
    } elseif ($action === 'rename') {
        $name = trim($_POST['display_name'] ?? '');
        if ($name !== '') {
            $stmt = db()->prepare('UPDATE linkedin_accounts SET display_name = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$name, $id, $userId]);
            flash('success', 'Nickname updated.');
        }
    }
    redirect('pages/accounts.php');
}

$stmt = db()->prepare('SELECT * FROM linkedin_accounts WHERE user_id = ? AND status != "revoked" ORDER BY account_type, display_name');
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();
$personal = array_values(array_filter($accounts, fn ($a) => $a['account_type'] === 'personal'));
$company  = array_values(array_filter($accounts, fn ($a) => $a['account_type'] === 'company'));

$pageTitle  = 'Connected Accounts';
$activePage = 'accounts';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Connected Accounts</h1>
  <p class="subtitle">Your CSV import's "LinkedIn Page" column is matched against the nickname below — keep them in sync.</p>
</div>

<section class="card">
  <div class="card-header">
    <h2>Personal Profile</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/linkedin_start.php?type=personal')) ?>">
      <?= empty($personal) ? 'Connect Personal Profile' : 'Reconnect' ?>
    </a>
  </div>
  <?php if (empty($personal)): ?>
    <p class="muted">No personal profile connected yet.</p>
  <?php else: foreach ($personal as $acct): ?>
    <?php include __DIR__ . '/_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Company Pages</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/linkedin_start.php?type=company')) ?>">Add Company Page(s)</a>
  </div>
  <?php if (empty($company)): ?>
    <p class="muted">No Company Pages connected yet.</p>
  <?php else: foreach ($company as $acct): ?>
    <?php include __DIR__ . '/_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
