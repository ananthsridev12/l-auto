<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/meta_oauth.php';

require_login();

if (empty($_SESSION['meta_pending_token'])) {
    flash('error', 'Your Facebook session expired — please reconnect.');
    redirect('pages/accounts.php');
}

$userToken = $_SESSION['meta_pending_token'];
$userId    = current_user_id();
$scopes    = 'pages_show_list,pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish,business_management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    if (empty($_SESSION['meta_pending_pages'])) {
        flash('error', 'Your Facebook session expired — please reconnect.');
        redirect('pages/accounts.php');
    }
    $selectedPages = $_POST['pages'] ?? [];
    // Page access tokens come from the session (populated by our own
    // meta_list_pages() call below), never from client-submitted form
    // data — a hidden field round-tripping real access tokens through
    // the browser would both leak them into page source and let a
    // tampered POST write an arbitrary token into social_accounts.
    $pageData      = $_SESSION['meta_pending_pages'];
    $connectIg     = $_POST['connect_ig'] ?? [];
    $connected     = 0;

    foreach ($selectedPages as $pageId) {
        $page = $pageData[$pageId] ?? null;
        if (!$page) {
            continue;
        }
        meta_upsert_social_account($userId, 'facebook', $pageId, $page['name'], $page['access_token'], null, $scopes);
        $connected++;

        $ig = $page['instagram_business_account'] ?? null;
        if ($ig && !empty($connectIg[$pageId])) {
            $igName = '@' . ($ig['username'] ?? $page['name']);
            meta_upsert_social_account($userId, 'instagram', $ig['id'], $igName, $page['access_token'], null, $scopes);
            $connected++;
        }
    }

    unset($_SESSION['meta_pending_token'], $_SESSION['meta_pending_pages']);
    flash('success', $connected . ' account(s) connected.');
    redirect('pages/accounts.php');
}

try {
    $pages = meta_list_pages($userToken);
} catch (Throwable $e) {
    flash('error', 'Could not list your Facebook Pages: ' . $e->getMessage());
    redirect('pages/accounts.php');
}

$pageDataById = [];
foreach ($pages as $page) {
    $pageDataById[$page['id']] = $page;
}
$_SESSION['meta_pending_pages'] = $pageDataById;

$pageTitle = 'Choose Facebook Pages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <h1>Choose Facebook Pages</h1>
  <p class="subtitle">Select the Facebook Pages you'd like to connect. Pages with a linked Instagram Business account can be connected for both at once.</p>

  <?php if (empty($pages)): ?>
    <p>No Facebook Pages found where you're an admin.</p>
    <a href="<?= h(app_path('pages/accounts.php')) ?>" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">Back to Accounts</a>
  <?php else: ?>
    <form method="post" class="stacked-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="page_data" value="<?= h(json_encode($pageDataById)) ?>">
      <?php foreach ($pages as $page): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="pages[]" value="<?= h($page['id']) ?>" checked>
          <?= h($page['name']) ?>
        </label>
        <?php if (!empty($page['instagram_business_account']['id'])): ?>
          <label class="checkbox-row" style="margin-left:24px;">
            <input type="checkbox" name="connect_ig[<?= h($page['id']) ?>]" value="1" checked>
            Also connect Instagram — @<?= h($page['instagram_business_account']['username'] ?? $page['name']) ?>
          </label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary">Connect Selected</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
