<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('signup_goals.php');
    }
    $goals = trim($_POST['goals'] ?? '');
    if ($goals !== '') {
        set_workspace_goals(personal_workspace_id($userId), $goals);
    }
    redirect('dashboard.php');
}

$token = csrf_token();
$pageTitle = 'What will you use PostPilot for? — Easi7 PostPilot';
require __DIR__ . '/includes/auth_shell_top.php';
?>
  <h1>What will you use PostPilot for?</h1>
  <p class="subtitle">A quick note on your goals helps AI-generated content sound more like you — you can update this anytime.</p>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Goals <span class="optional">(optional)</span>
      <textarea name="goals" rows="4" placeholder="e.g. Grow our company page, share product updates, build my personal brand..." autofocus></textarea>
    </label>
    <button type="submit" class="btn-primary">Finish</button>
  </form>

  <a href="<?= h(app_path('dashboard.php')) ?>" class="link-muted" style="display:block;text-align:center;margin-top:20px;">Skip for now</a>
<?php require __DIR__ . '/includes/auth_shell_bottom.php'; ?>
