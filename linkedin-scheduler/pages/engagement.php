<?php
// Engagement (Like & Comment) — see includes/engagement.php for the
// full design rationale. Admins/owners curate a short list of external
// LinkedIn posts (target_posts); any member with an active connected
// LinkedIn account can Like or Comment on them with one click, and
// every action is logged to engagement_actions automatically — the
// data source a future points feature will read from.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/linkedin_api.php';
require_once __DIR__ . '/../includes/engagement.php';

require_login();
require_module('engagement');
$userId = current_user_id();
$workspaceId = current_workspace_id();
$workspace = current_workspace();
$canManage = user_can_manage_target_posts($userId, $workspaceId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/engagement.php');
    }

    if (($_POST['form'] ?? '') === 'target_add') {
        if (!$canManage) {
            flash('error', 'Only a workspace owner or organization admin can add posts to this list.');
            redirect('pages/engagement.php');
        }
        [$ok, $error] = add_target_post($workspaceId, $userId, $_POST['post_url'] ?? '', $_POST['label'] ?? '');
        flash($ok ? 'success' : 'error', $ok ? 'Added to the engagement list.' : $error);
        redirect('pages/engagement.php');
    }

    if (($_POST['form'] ?? '') === 'target_archive') {
        if (!$canManage) {
            flash('error', 'Only a workspace owner or organization admin can archive posts.');
            redirect('pages/engagement.php');
        }
        archive_target_post((int) ($_POST['target_id'] ?? 0), $workspaceId);
        flash('success', 'Post archived.');
        redirect('pages/engagement.php');
    }

    if (($_POST['form'] ?? '') === 'target_unarchive') {
        if (!$canManage) {
            flash('error', 'Only a workspace owner or organization admin can restore posts.');
            redirect('pages/engagement.php');
        }
        unarchive_target_post((int) ($_POST['target_id'] ?? 0), $workspaceId);
        flash('success', 'Post restored.');
        redirect('pages/engagement.php');
    }
}

$targetPosts = fetch_target_posts($workspaceId);
$archivedPosts = $canManage ? array_filter(fetch_target_posts($workspaceId, true), fn ($t) => $t['status'] === 'archived') : [];
$accounts = fetch_user_accounts($userId, $workspaceId);
$defaultAccountId = $accounts ? (int) $accounts[0]['id'] : 0;
$remainingToday = $defaultAccountId ? engagement_actions_remaining_today($defaultAccountId) : 0;
$outOfQuota = $defaultAccountId && $remainingToday <= 0;

$pageTitle  = 'Engagement';
$activePage = 'engagement';
$token = csrf_token();
$pageScripts = ['engagement.js'];
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Engagement</h1>
</div>

<?php if (!$accounts): ?>
  <p class="badge badge-warning">No LinkedIn account connected for this workspace — <a href="<?= h(app_path('pages/accounts.php')) ?>">connect one</a> before liking or commenting.</p>
<?php elseif ($outOfQuota): ?>
  <p class="badge badge-warning">You've used today's engagement action limit for this account. Try again tomorrow.</p>
<?php else: ?>
  <p class="muted"><?= (int) $remainingToday ?> of <?= ENGAGEMENT_DAILY_CAP ?> engagement actions left today for this account.</p>
<?php endif; ?>

<section class="card">
  <div class="card-header"><h2>Posts to engage with</h2></div>
  <?php if ($targetPosts): ?>
    <?php foreach ($targetPosts as $t): ?>
      <?php
        $permalink = li_post_url($t['target_urn']) ?? $t['post_url'];
        $alreadyLiked = $accounts && has_liked_target_post((int) $t['id'], $userId);
        $disabled = !$accounts || $outOfQuota;
      ?>
      <div class="account-row" style="align-items:flex-start;">
        <div class="account-info" style="flex-direction:column; align-items:flex-start; gap:4px;">
          <span><?= h($t['label'] ?: 'LinkedIn post') ?></span>
          <a href="<?= h($permalink) ?>" target="_blank" rel="noopener noreferrer" class="link-muted">View on LinkedIn</a>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px; min-width:260px;">
          <div>
            <button id="like-btn-<?= (int) $t['id'] ?>" class="btn-tiny" <?= ($disabled || $alreadyLiked) ? 'disabled' : '' ?> onclick="engagementLike(<?= (int) $t['id'] ?>, <?= $defaultAccountId ?>)">
              <?= $alreadyLiked ? 'Liked ✓' : 'Like' ?>
            </button>
          </div>
          <div id="like-status-<?= (int) $t['id'] ?>" class="post-status" style="display:none;"></div>

          <textarea id="comment-text-<?= (int) $t['id'] ?>" rows="2" placeholder="Write a comment…" <?= $disabled ? 'disabled' : '' ?> style="width:100%;"></textarea>
          <div>
            <button id="comment-btn-<?= (int) $t['id'] ?>" class="btn-tiny" <?= $disabled ? 'disabled' : '' ?> onclick="engagementComment(<?= (int) $t['id'] ?>, <?= $defaultAccountId ?>)">Comment</button>
          </div>
          <div id="comment-status-<?= (int) $t['id'] ?>" class="post-status" style="display:none;"></div>

          <?php if ($canManage): ?>
            <form method="post" onsubmit="return confirm('Archive this post? It will stop showing here, but past engagement stays on record.');">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="target_archive">
              <input type="hidden" name="target_id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn-tiny btn-danger">Archive</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No posts on the list yet<?= $canManage ? ' — add one below.' : '. Ask a workspace owner or admin to add one.' ?></p>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<section class="card">
  <div class="card-header"><h2>Add a post to engage with</h2></div>
  <p class="muted">Paste a LinkedIn post URL (e.g. the Company Page's latest post) — members will see it in the list above and can Like/Comment on it from here.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="target_add">
    <label>Post URL
      <input type="text" name="post_url" placeholder="https://www.linkedin.com/posts/..." required>
    </label>
    <label>Label <span class="muted">(optional)</span>
      <input type="text" name="label" placeholder="e.g. Company Page — August product post">
    </label>
    <button type="submit" class="btn-secondary">Add to list</button>
  </form>
</section>

<?php if ($archivedPosts): ?>
<section class="card">
  <div class="card-header"><h2>Archived</h2></div>
  <?php foreach ($archivedPosts as $t): ?>
    <div class="account-row">
      <div class="account-info">
        <span><?= h($t['label'] ?: 'LinkedIn post') ?></span>
        <a href="<?= h(li_post_url($t['target_urn']) ?? $t['post_url']) ?>" target="_blank" rel="noopener noreferrer" class="link-muted">View on LinkedIn</a>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="form" value="target_unarchive">
        <input type="hidden" name="target_id" value="<?= (int) $t['id'] ?>">
        <button type="submit" class="btn-tiny">Restore</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<script>
  window.ENGAGEMENT_LIKE_URL = <?= json_encode(app_path('api/engagement_like.php')) ?>;
  window.ENGAGEMENT_COMMENT_URL = <?= json_encode(app_path('api/engagement_comment.php')) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
