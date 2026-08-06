<?php
// Shared single-card shell for the pre-auth account pages: login.php,
// signup.php, and the post-signup onboarding steps (signup_company.php,
// signup_goals.php). Set $pageTitle before requiring. Requires
// includes/auth.php + includes/helpers.php already loaded (app_path(),
// h(), csrf_token()), same convention includes/layout_top.php uses.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle ?? 'Easi7 PostPilot') ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <a href="<?= h(app_path('index.php')) ?>" class="auth-logo" aria-label="Easi7 PostPilot home">
    <?php require __DIR__ . '/logo.php'; ?>
  </a>
