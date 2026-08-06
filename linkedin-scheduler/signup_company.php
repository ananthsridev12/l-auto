<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('signup_company.php');
    }
    $name = trim($_POST['company_name'] ?? '');
    if ($name !== '') {
        set_workspace_name(personal_workspace_id($userId), $name);
    }
    redirect('signup_goals.php');
}

$token = csrf_token();
$pageTitle = 'Tell us about your page — Easi7 PostPilot';
require __DIR__ . '/includes/auth_shell_top.php';
?>
  <h1>What should we call your page?</h1>
  <p class="subtitle">This is just a label so you can tell your workspaces apart — you can change it anytime in Settings.</p>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Company or page name <span class="optional">(optional)</span>
      <input type="text" name="company_name" placeholder="e.g. Acme Inc." autofocus>
    </label>
    <button type="submit" class="btn-primary">Continue</button>
  </form>

  <a href="<?= h(app_path('signup_goals.php')) ?>" class="link-muted" style="display:block;text-align:center;margin-top:20px;">Skip for now</a>
<?php require __DIR__ . '/includes/auth_shell_bottom.php'; ?>
