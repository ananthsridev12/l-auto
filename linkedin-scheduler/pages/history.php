<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

$stmt = db()->prepare(
    'SELECT p.*, la.display_name AS account_name
     FROM posts p
     LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
     WHERE p.user_id = ? AND p.status IN ("posted", "failed")
     ORDER BY COALESCE(p.posted_at, p.updated_at) DESC
     LIMIT 200'
);
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();

$pageTitle  = 'History';
$activePage = 'history';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Post History</h1></div>

<section class="card">
  <?php if (empty($posts)): ?>
    <p class="muted">No posted or failed posts yet.</p>
  <?php else: ?>
    <table class="preview-table">
      <thead><tr><th>Campaign ID</th><th>Format</th><th>Account</th><th>Status</th><th>Posted At</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($posts as $p): ?>
          <tr>
            <td><a href="<?= h(app_path('pages/post.php?id=' . $p['id'])) ?>"><?= h($p['campaign_id']) ?></a></td>
            <td><?= h($p['format']) ?></td>
            <td><?= h($p['account_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= h(strtolower($p['status'])) ?>"><?= h(ucfirst($p['status'])) ?></span></td>
            <td><?= h($p['posted_at'] ?? '—') ?></td>
            <td class="muted"><?= h($p['status'] === 'posted' ? $p['li_post_urn'] : $p['error_message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
