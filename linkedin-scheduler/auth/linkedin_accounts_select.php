<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/linkedin_oauth.php';

require_login();

if (empty($_SESSION['li_pending_token'])) {
    flash('error', 'Your LinkedIn session expired — please reconnect.');
    redirect('pages/accounts.php');
}

$accessToken = $_SESSION['li_pending_token'];
$expiresAt   = $_SESSION['li_pending_expires_at'] ?? null;
$userId      = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    $selected = $_POST['orgs'] ?? [];
    $names    = $_POST['org_name'] ?? [];
    foreach ($selected as $orgUrn) {
        $name = $names[$orgUrn] ?? $orgUrn;
        li_upsert_account($userId, 'company', $orgUrn, $name, $name, $accessToken, $expiresAt, LI_SCOPES_COMPANY);
    }
    unset($_SESSION['li_pending_token'], $_SESSION['li_pending_expires_at']);
    flash('success', count($selected) . ' LinkedIn Company Page(s) connected.');
    redirect('pages/accounts.php');
}

try {
    $orgUrns = li_list_admin_organizations($accessToken);
} catch (Throwable $e) {
    flash('error', 'Could not list your LinkedIn Company Pages: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$orgs = [];
foreach ($orgUrns as $urn) {
    $orgs[] = ['urn' => $urn, 'name' => li_get_organization_name($accessToken, $urn)];
}

$pageTitle = 'Choose Company Pages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <h1>Choose Company Pages</h1>
  <p class="subtitle">Select the LinkedIn Company Pages you'd like to connect for scheduling.</p>

  <?php if (empty($orgs)): ?>
    <p>No Company Pages found where you're an administrator.</p>
    <a href="<?= h(app_path('pages/accounts.php')) ?>" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">Back to Accounts</a>
  <?php else: ?>
    <form method="post" class="stacked-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php foreach ($orgs as $org): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="orgs[]" value="<?= h($org['urn']) ?>" checked>
          <?= h($org['name']) ?>
          <input type="hidden" name="org_name[<?= h($org['urn']) ?>]" value="<?= h($org['name']) ?>">
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary">Connect Selected Pages</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
