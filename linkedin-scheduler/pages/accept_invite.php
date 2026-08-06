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
$pageTitle = 'Join ' . ($org['name'] ?? 'an organization') . ' — Easi7 PostPilot';
require __DIR__ . '/../includes/auth_shell_top.php';
?>
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
<?php require __DIR__ . '/../includes/auth_shell_bottom.php'; ?>
