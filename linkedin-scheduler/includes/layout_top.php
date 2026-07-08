<?php
// Include this after setting $pageTitle and $activePage.
// Requires includes/auth.php to already be loaded (for current_user()).
$__user = current_user();
$__flashError   = flash('error');
$__flashSuccess = flash('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'LinkedIn Scheduler') ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <svg viewBox="0 0 24 24" fill="#0A66C2"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
      <span>LI Scheduler</span>
    </div>

    <nav>
      <a href="<?= h(app_path('pages/new_post.php')) ?>" class="<?= ($activePage ?? '') === 'new_post' ? 'active' : '' ?>">New Post</a>
      <a href="<?= h(app_path('pages/today.php')) ?>" class="<?= ($activePage ?? '') === 'today' ? 'active' : '' ?>">Today</a>
      <a href="<?= h(app_path('pages/calendar.php')) ?>" class="<?= ($activePage ?? '') === 'calendar' ? 'active' : '' ?>">Calendar</a>
      <a href="<?= h(app_path('pages/drafts.php')) ?>" class="<?= ($activePage ?? '') === 'drafts' ? 'active' : '' ?>">Drafts</a>
      <a href="<?= h(app_path('pages/import.php')) ?>" class="<?= ($activePage ?? '') === 'import' ? 'active' : '' ?>">Import</a>
      <a href="<?= h(app_path('pages/accounts.php')) ?>" class="<?= ($activePage ?? '') === 'accounts' ? 'active' : '' ?>">Accounts</a>
      <a href="<?= h(app_path('pages/history.php')) ?>" class="<?= ($activePage ?? '') === 'history' ? 'active' : '' ?>">History</a>
      <a href="<?= h(app_path('pages/settings.php')) ?>" class="<?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">Settings</a>
    </nav>

    <?php if ($__user): ?>
    <div class="sidebar-user">
      <div class="avatar"><?= h(strtoupper(substr($__user['name'] ?: $__user['email'], 0, 1))) ?></div>
      <div class="user-info">
        <span class="user-name"><?= h($__user['name'] ?: $__user['email']) ?></span>
        <a href="<?= h(app_path('logout.php')) ?>" class="logout-link">Sign out</a>
      </div>
    </div>
    <?php endif; ?>
  </aside>

  <main class="main">
    <?php if ($__flashError): ?><div class="flash flash-error"><?= h($__flashError) ?></div><?php endif; ?>
    <?php if ($__flashSuccess): ?><div class="flash flash-success"><?= h($__flashSuccess) ?></div><?php endif; ?>
