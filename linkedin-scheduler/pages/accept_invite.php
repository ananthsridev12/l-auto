<?php
// Public, pre-auth page (like index.php) — reached via the shareable
// link an org owner/admin generates from Settings > Organization.
// Deliberately does NOT call require_login(): the whole point is
// letting a brand-new person create an account. Only supports brand-new
// emails — see includes/organizations.php accept_invite_new_user()'s
// doc comment for why an existing account can't switch orgs this way.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (current_user_id()) {
    redirect('dashboard.php');
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invite = $token !== '' ? fetch_invite_by_token($token) : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired, please try again.';
    } elseif (!$invite) {
        $error = 'This invite link is invalid or has expired.';
    } else {
        [$ok, $err] = accept_invite_new_user($token, $_POST['password'] ?? '', $_POST['name'] ?? '');
        if ($ok) {
            [$loginOk] = attempt_login($invite['email'], $_POST['password']);
            redirect('dashboard.php');
        }
        $error = $err;
    }
}

$org = $invite ? fetch_organization((int) $invite['organization_id']) : null;
$token2 = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Join <?= h($org['name'] ?? 'an organization') ?> — LinkedIn Scheduler</title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <div class="auth-logo">
    <svg viewBox="0 0 24 24" fill="#0A66C2" width="40" height="40"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
  </div>

  <?php if (!$invite): ?>
    <h1>Invite not found</h1>
    <p class="subtitle">This invite link is invalid, expired, or has already been used. Ask whoever invited you to send a new one.</p>
  <?php else: ?>
    <h1>Join <?= h($org['name']) ?></h1>
    <p class="subtitle">You've been invited as <?= h(ucfirst($invite['role'])) ?>. Create your account to accept.</p>

    <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="stacked-form">
      <input type="hidden" name="csrf" value="<?= h($token2) ?>">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label>Email
        <input type="email" value="<?= h($invite['email']) ?>" disabled>
      </label>
      <label>Your Name
        <input type="text" name="name" required>
      </label>
      <label>Password
        <input type="password" name="password" required minlength="8">
      </label>
      <button type="submit" class="btn-primary">Create account &amp; join</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
