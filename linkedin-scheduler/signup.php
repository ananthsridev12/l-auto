<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (current_user_id()) {
    redirect('dashboard.php');
}

$error = null;
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired, please try again.';
    } else {
        [$ok, $err] = register_user($email, $_POST['password'] ?? '', $name);
        if ($ok) {
            // register_user() doesn't log in by itself — same
            // create-then-login sequence the old combined index.php used.
            attempt_login($email, $_POST['password'] ?? '');
            redirect('signup_company.php');
        }
        $error = $err;
    }
}

$token = csrf_token();
$pageTitle = 'Create your account — Easi7 PostPilot';
require __DIR__ . '/includes/auth_shell_top.php';
?>
  <h1>Create your account</h1>
  <p class="subtitle">Schedule and post LinkedIn content across all your profiles and pages.</p>

  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Name
      <input type="text" name="name" value="<?= h($name) ?>" required autofocus>
    </label>
    <label>Email
      <input type="email" name="email" value="<?= h($email) ?>" required>
    </label>
    <label>Password
      <input type="password" name="password" required minlength="8">
    </label>
    <button type="submit" class="btn-primary">Create account</button>
  </form>

  <a href="<?= h(app_path('login.php')) ?>" class="link-muted" style="display:block;text-align:center;margin-top:20px;">Already have an account? Sign in</a>
<?php require __DIR__ . '/includes/auth_shell_bottom.php'; ?>
