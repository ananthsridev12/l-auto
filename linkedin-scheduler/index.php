<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (current_user_id()) {
    redirect('dashboard.php');
}

$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired, please try again.';
    } elseif (($_POST['mode'] ?? '') === 'register') {
        [$ok, $err] = register_user($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['name'] ?? '');
        if ($ok) {
            [$ok2, $err2] = attempt_login($_POST['email'], $_POST['password']);
            redirect('dashboard.php');
        }
        $mode = 'register';
        $error = $err;
    } else {
        [$ok, $err] = attempt_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($ok) {
            redirect('dashboard.php');
        }
        $mode = 'login';
        $error = $err;
    }
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LinkedIn Scheduler</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="centered-page">
<div class="auth-card">
  <div class="auth-logo">
    <svg viewBox="0 0 24 24" fill="#0A66C2" width="40" height="40"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
  </div>

  <h1><?= $mode === 'register' ? 'Create your account' : 'Sign in' ?></h1>
  <p class="subtitle">Schedule and post LinkedIn content across all your profiles and pages.</p>

  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="mode" value="<?= h($mode) ?>">
    <?php if ($mode === 'register'): ?>
      <label>Name
        <input type="text" name="name" required>
      </label>
    <?php endif; ?>
    <label>Email
      <input type="email" name="email" required>
    </label>
    <label>Password
      <input type="password" name="password" required minlength="8">
    </label>
    <button type="submit" class="btn-primary"><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></button>
  </form>

  <?php if ($mode === 'register'): ?>
    <a href="?mode=login" class="link-muted" style="display:block;text-align:center;margin-top:20px;">Already have an account? Sign in</a>
  <?php else: ?>
    <a href="?mode=register" class="link-muted" style="display:block;text-align:center;margin-top:20px;">New here? Create an account</a>
  <?php endif; ?>
</div>
</body>
</html>
