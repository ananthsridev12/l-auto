<?php
// Shared shell for the public marketing pages (Home, Pricing, About,
// Terms, Privacy). Set $pageTitle before requiring. Requires
// includes/auth.php + includes/helpers.php already loaded, same
// convention includes/layout_top.php uses for the logged-in app shell.
$__publicUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'Easi7 PostPilot') ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="public">
<header class="public-header">
  <a href="<?= h(app_path('index.php')) ?>" class="public-brand">
    <?php require __DIR__ . '/logo.php'; ?>
    <span>Easi7 PostPilot</span>
  </a>
  <nav class="public-nav">
    <a href="<?= h(app_path('index.php')) ?>">Home</a>
    <a href="<?= h(app_path('pricing.php')) ?>">Pricing</a>
    <a href="<?= h(app_path('about.php')) ?>">About</a>
  </nav>
  <div class="public-actions">
    <?php if ($__publicUser): ?>
      <a href="<?= h(app_path('dashboard.php')) ?>" class="btn-secondary">Dashboard</a>
      <a href="<?= h(app_path('logout.php')) ?>" class="link-muted">Sign out</a>
    <?php else: ?>
      <a href="<?= h(app_path('login.php')) ?>" class="link-muted">Log in</a>
      <a href="<?= h(app_path('signup.php')) ?>" class="btn-primary">Sign up</a>
    <?php endif; ?>
  </div>
</header>
<main class="public-main">
