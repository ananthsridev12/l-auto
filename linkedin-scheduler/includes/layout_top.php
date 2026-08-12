<?php
// Include this after setting $pageTitle and $activePage.
// Requires includes/auth.php to already be loaded (for current_user()).
$__user = current_user();
$__flashError   = flash('error');
$__flashSuccess = flash('success');
$__theme = $__user ? get_user_theme((int) $__user['id']) : null;
?>
<!DOCTYPE html>
<html lang="en"<?= $__theme ? ' data-theme="' . h($__theme) . '"' : '' ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'Easi7 PostPilot') ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body>
<div class="app">

  <div class="mobile-topbar">
    <button type="button" class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <span class="mobile-topbar-title">Easi7 PostPilot</span>
  </div>
  <div class="sidebar-backdrop" onclick="closeSidebar()"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <?php require __DIR__ . '/logo.php'; ?>
      <span>Easi7 PostPilot</span>
    </div>

    <?php if ($__user): ?>
      <?php $__workspaces = fetch_workspaces((int) $__user['id']); $__activeWs = current_workspace_id(); ?>
      <form method="post" action="<?= h(app_path('api/switch_workspace.php')) ?>" class="workspace-switcher">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="return_to" value="<?= h(ltrim($_SERVER['REQUEST_URI'] ?? '', '/')) ?>">
        <select name="workspace_id" onchange="this.form.submit()" aria-label="Workspace">
          <?php foreach ($__workspaces as $__ws): ?>
            <?php $__granted = (int) $__ws['user_id'] !== (int) $__user['id']; ?>
            <option value="<?= (int) $__ws['id'] ?>"<?= (int) $__ws['id'] === $__activeWs ? ' selected' : '' ?>>
              <?= h($__ws['name']) ?><?= $__ws['type'] === 'personal' ? ' (Personal)' : ' (Company)' ?><?= $__granted ? ' — shared by ' . h($__ws['owner_name'] ?: $__ws['owner_email']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>

    <nav>
      <?php if (module_enabled('post_scheduling')): ?>
        <a href="<?= h(app_path('pages/new_post.php')) ?>" class="<?= ($activePage ?? '') === 'new_post' ? 'active' : '' ?>">New Post</a>
        <a href="<?= h(app_path('pages/today.php')) ?>" class="<?= ($activePage ?? '') === 'today' ? 'active' : '' ?>">Today</a>
        <a href="<?= h(app_path('pages/calendar.php')) ?>" class="<?= ($activePage ?? '') === 'calendar' ? 'active' : '' ?>">Calendar</a>
        <a href="<?= h(app_path('pages/drafts.php')) ?>" class="<?= ($activePage ?? '') === 'drafts' ? 'active' : '' ?>">Drafts</a>
        <a href="<?= h(app_path('pages/bulk_schedule.php')) ?>" class="<?= ($activePage ?? '') === 'bulk_schedule' ? 'active' : '' ?>">Bulk Schedule</a>
      <?php endif; ?>
      <?php if (module_enabled('content_studio')): ?>
        <a href="<?= h(app_path('pages/content_calendar.php')) ?>" class="<?= ($activePage ?? '') === 'content_calendar' ? 'active' : '' ?>">Content Calendar</a>
        <a href="<?= h(app_path('pages/content_studio.php')) ?>" class="<?= ($activePage ?? '') === 'content_studio' ? 'active' : '' ?>">Content Studio</a>
      <?php endif; ?>
      <?php if (module_enabled('news_studio')): ?>
        <a href="<?= h(app_path('pages/news_studio.php')) ?>" class="<?= ($activePage ?? '') === 'news_studio' ? 'active' : '' ?>">News Studio</a>
      <?php endif; ?>
      <?php if (module_enabled('blog_studio')): ?>
        <a href="<?= h(app_path('pages/blog_studio.php')) ?>" class="<?= ($activePage ?? '') === 'blog_studio' ? 'active' : '' ?>">Blog Studio</a>
      <?php endif; ?>
      <?php if (module_enabled('engagement')): ?>
        <a href="<?= h(app_path('pages/engagement.php')) ?>" class="<?= ($activePage ?? '') === 'engagement' ? 'active' : '' ?>">Engagement</a>
      <?php endif; ?>
      <?php if (module_enabled('post_scheduling')): ?>
        <a href="<?= h(app_path('pages/import.php')) ?>" class="<?= ($activePage ?? '') === 'import' ? 'active' : '' ?>">Import</a>
      <?php endif; ?>
      <a href="<?= h(app_path('pages/accounts.php')) ?>" class="<?= ($activePage ?? '') === 'accounts' ? 'active' : '' ?>">Accounts</a>
      <?php if (module_enabled('post_scheduling')): ?>
        <a href="<?= h(app_path('pages/history.php')) ?>" class="<?= ($activePage ?? '') === 'history' ? 'active' : '' ?>">History</a>
      <?php endif; ?>
      <a href="<?= h(app_path('pages/knowledge.php')) ?>" class="<?= ($activePage ?? '') === 'knowledge' ? 'active' : '' ?>">Knowledge Base</a>
      <a href="<?= h(app_path('pages/settings.php')) ?>" class="<?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">Settings</a>
      <?php if ($__user && !empty($__user['is_superadmin'])): ?>
        <a href="<?= h(app_path('pages/admin.php')) ?>" class="<?= ($activePage ?? '') === 'admin' ? 'active' : '' ?>">Admin</a>
      <?php endif; ?>
    </nav>

    <?php if ($__user): ?>
    <div class="sidebar-user">
      <div class="avatar"><?= h(strtoupper(substr($__user['name'] ?: $__user['email'], 0, 1))) ?></div>
      <div class="user-info">
        <span class="user-name"><?= h($__user['name'] ?: $__user['email']) ?></span>
        <a href="<?= h(app_path('logout.php')) ?>" class="logout-link">Sign out</a>
      </div>
      <button type="button" id="themeToggleBtn" class="theme-toggle-btn" title="Toggle light/dark theme" aria-label="Toggle light/dark theme">
        <span id="themeToggleIcon"><?= $__theme === 'dark' ? '☀️' : '🌙' ?></span>
      </button>
    </div>
    <?php endif; ?>
  </aside>

  <script>
    function toggleSidebar() { document.body.classList.toggle('sidebar-open'); }
    function closeSidebar() { document.body.classList.remove('sidebar-open'); }
    document.querySelectorAll('#sidebar nav a').forEach(function (a) {
      a.addEventListener('click', closeSidebar);
    });

    (function () {
      var btn = document.getElementById('themeToggleBtn');
      if (!btn) return;
      var icon = document.getElementById('themeToggleIcon');
      var html = document.documentElement;
      btn.addEventListener('click', function () {
        var current = html.getAttribute('data-theme')
          || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        var next = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        icon.textContent = next === 'dark' ? '☀️' : '🌙';
        var fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('theme', next);
        fetch(<?= json_encode(app_path('api/set_theme.php')) ?>, { method: 'POST', body: fd });
      });
    })();
  </script>

  <main class="main">
    <?php if ($__flashError): ?><div class="flash flash-error"><?= h($__flashError) ?></div><?php endif; ?>
    <?php if ($__flashSuccess): ?><div class="flash flash-success"><?= h($__flashSuccess) ?></div><?php endif; ?>
