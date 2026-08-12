<?php
// Engagement (Like, Comment & Repost) — see includes/engagement.php for
// the full design rationale. Admins/owners curate a short list of
// external LinkedIn posts (target_posts), embedded here via LinkedIn's
// public "Embed this post" iframe (no API/auth needed). Any member with
// an active connected LinkedIn account can:
// - Like/Comment: opens the real post on LinkedIn in a new tab AND
//   immediately logs the action as done — self-reported, not verified
//   (LinkedIn's socialActions API needs partner approval this app
//   doesn't have; see includes/engagement.php).
// - Repost: publishes for real, in-app, via the same Posts API used for
//   scheduled posting — no redirect, and actually verified.
// Every action is logged to engagement_actions the moment it happens —
// the data source a future points feature will read from.
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
  <p class="badge badge-warning">No LinkedIn account connected for this workspace — <a href="<?= h(app_path('pages/accounts.php')) ?>">connect one</a> before engaging.</p>
<?php elseif ($outOfQuota): ?>
  <p class="badge badge-warning">You've used today's engagement action limit for this account. Try again tomorrow.</p>
<?php else: ?>
  <p class="muted"><?= (int) $remainingToday ?> of <?= ENGAGEMENT_DAILY_CAP ?> engagement actions left today for this account.</p>
<?php endif; ?>

<section class="card">
  <div class="card-header"><h2>Posts to engage with</h2></div>
  <p class="muted">Like/Comment open the real post on LinkedIn in a new tab — please actually like/comment there, since that's what makes the engagement real. Repost publishes immediately from your connected account, right here, no need to leave the page.</p>
  <?php if ($targetPosts): ?>
    <?php foreach ($targetPosts as $t): ?>
      <?php
        $permalink = li_post_url($t['target_urn']) ?? $t['post_url'];
        $embedUrl = li_embed_url($t['target_urn']);
        $alreadyLiked = $accounts && has_action_on_target('like', (int) $t['id'], $userId);
        $alreadyReposted = $accounts && has_action_on_target('repost', (int) $t['id'], $userId);
        $disabled = !$accounts || $outOfQuota;
      ?>
      <div class="account-row" style="align-items:flex-start; flex-wrap:wrap;">
        <div style="flex:1 1 320px; min-width:280px;">
          <div style="margin-bottom:8px;"><span><?= h($t['label'] ?: 'LinkedIn post') ?></span></div>
          <?php if ($embedUrl): ?>
            <iframe src="<?= h($embedUrl) ?>" height="480" width="100%" style="max-width:504px; border:none;" loading="lazy" allowfullscreen title="<?= h($t['label'] ?: 'LinkedIn post') ?>"></iframe>
          <?php endif; ?>
          <div><a href="<?= h($permalink) ?>" target="_blank" rel="noopener noreferrer" class="link-muted">Open on LinkedIn</a></div>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px; min-width:260px; flex:1 1 260px;">
          <div>
            <button id="like-btn-<?= (int) $t['id'] ?>" class="btn-tiny" data-permalink="<?= h($permalink) ?>" <?= ($disabled || $alreadyLiked) ? 'disabled' : '' ?> onclick="engagementLike(<?= (int) $t['id'] ?>, <?= $defaultAccountId ?>, this.dataset.permalink)">
              <?= $alreadyLiked ? 'Liked ✓' : 'Like' ?>
            </button>
          </div>
          <div id="like-status-<?= (int) $t['id'] ?>" class="post-status" style="display:none;"></div>

          <textarea id="comment-text-<?= (int) $t['id'] ?>" rows="2" placeholder="Write a comment… (posted by you on LinkedIn, in the tab that opens)" <?= $disabled ? 'disabled' : '' ?> style="width:100%;"></textarea>
          <div>
            <button id="comment-btn-<?= (int) $t['id'] ?>" class="btn-tiny" data-permalink="<?= h($permalink) ?>" <?= $disabled ? 'disabled' : '' ?> onclick="engagementComment(<?= (int) $t['id'] ?>, <?= $defaultAccountId ?>, this.dataset.permalink)">Comment</button>
          </div>
          <div id="comment-status-<?= (int) $t['id'] ?>" class="post-status" style="display:none;"></div>

          <textarea id="repost-text-<?= (int) $t['id'] ?>" rows="2" placeholder="Add your thoughts (optional) — leave blank for a plain repost" <?= ($disabled || $alreadyReposted) ? 'disabled' : '' ?> style="width:100%;"></textarea>
          <div>
            <button id="repost-btn-<?= (int) $t['id'] ?>" class="btn-tiny" <?= ($disabled || $alreadyReposted) ? 'disabled' : '' ?> onclick="engagementRepost(<?= (int) $t['id'] ?>, <?= $defaultAccountId ?>)">
              <?= $alreadyReposted ? 'Reposted ✓' : 'Repost' ?>
            </button>
          </div>
          <div id="repost-status-<?= (int) $t['id'] ?>" class="post-status" style="display:none;"></div>

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
  <p class="muted">Paste a LinkedIn post URL (e.g. the Company Page's latest post) — members will see it embedded in the list above and can Like/Comment/Repost it from here.</p>
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
  window.ENGAGEMENT_REPOST_URL = <?= json_encode(app_path('api/engagement_repost.php')) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
