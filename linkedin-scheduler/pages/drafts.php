<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

$stmt = db()->prepare(
    'SELECT p.*, la.display_name AS account_name
     FROM posts p
     LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
     WHERE p.user_id = ? AND (p.workspace_id = ? OR p.workspace_id IS NULL) AND p.status = "draft"
     ORDER BY p.updated_at DESC
     LIMIT 200'
);
$stmt->execute([$userId, current_workspace_id()]);
$drafts = $stmt->fetchAll();

$pageTitle  = 'Drafts';
$activePage = 'drafts';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Drafts</h1></div>

<section class="card">
  <?php if (empty($drafts)): ?>
    <p class="muted">No drafts. Drafts show up here when an imported row has no date, no matched LinkedIn account, or when you click "Save Draft" on a post.</p>
  <?php else: ?>
    <table class="preview-table">
      <thead><tr><th>Campaign ID</th><th>Format</th><th>Title</th><th>Account</th><th>Last Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($drafts as $d): ?>
          <tr>
            <td><?= h($d['campaign_id']) ?></td>
            <td><?= h($d['format']) ?></td>
            <td><?= h($d['title']) ?></td>
            <td><?= h($d['account_name'] ?? '— unassigned —') ?></td>
            <td><?= h($d['updated_at']) ?></td>
            <td><a href="<?= h(app_path('pages/post.php?id=' . $d['id'])) ?>" class="btn-tiny">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
