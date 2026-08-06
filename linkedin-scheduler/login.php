<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (current_user_id()) {
    redirect('dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired, please try again.';
    } else {
        [$ok, $err] = attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($ok) {
            redirect('dashboard.php');
        }
        $error = $err;
    }
}

$token = csrf_token();
$pageTitle = 'Sign in — Easi7 PostPilot';
require __DIR__ . '/includes/auth_shell_top.php';
?>
  <h1>Sign in</h1>
  <p class="subtitle">Schedule and post LinkedIn content across all your profiles and pages.</p>

  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required minlength="8">
    </label>
    <button type="submit" class="btn-primary">Sign in</button>
  </form>

  <a href="<?= h(app_path('signup.php')) ?>" class="link-muted" style="display:block;text-align:center;margin-top:20px;">New here? Create an account</a>
<?php require __DIR__ . '/includes/auth_shell_bottom.php'; ?>
