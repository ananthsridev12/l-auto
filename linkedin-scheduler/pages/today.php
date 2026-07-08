<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';

require_login();
$userId = current_user_id();

$stmt = db()->prepare('SELECT id FROM posts WHERE user_id = ? AND DATE(scheduled_at) = CURDATE() ORDER BY id ASC');
$stmt->execute([$userId]);
$todayIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

// The common case (one post today) gets the full editor below. With more
// than one scheduled for the same day, show a picklist instead of
// silently editing/posting just one of them.
$post = count($todayIds) === 1 ? fetch_post_with_slides($todayIds[0], $userId) : null;
$todayList = count($todayIds) > 1
    ? array_map(fn ($id) => fetch_post_with_slides($id, $userId), $todayIds)
    : [];

$pageTitle   = "Today's Post";
$activePage  = 'today';
$pageScripts = ['formatter.js', 'app.js'];
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Today's Post</h1>
  <span class="date-label"><?= h(date('F j, Y')) ?></span>
</div>

<?php if ($post): ?>
<div class="post-card">
  <div class="post-meta-bar">
    <span class="badge badge-campaign"><?= h($post['campaign_id']) ?></span>
    <span class="badge badge-format"><?= h($post['format']) ?></span>
    <span class="post-title-text"><?= h($post['title']) ?></span>
  </div>

  <div class="post-layout">
    <div class="slides-panel">
      <?php if ($post['slides']): ?>
        <div class="slide-frame">
          <img id="slideImg" src="<?= h($post['slides'][0]['url']) ?>" alt="Slide preview">
        </div>
        <?php if (count($post['slides']) > 1): ?>
        <div class="slide-nav">
          <button class="slide-btn" onclick="prevSlide()">&#8592;</button>
          <span id="slideCounter">1 / <?= count($post['slides']) ?></span>
          <button class="slide-btn" onclick="nextSlide()">&#8594;</button>
        </div>
        <?php endif; ?>
        <p class="slide-hint"><?= count($post['slides']) ?> slide<?= count($post['slides']) > 1 ? 's' : '' ?> — will post as <?= count($post['slides']) > 1 ? 'PDF carousel' : 'image' ?></p>
      <?php else: ?>
        <div class="no-slides"><p>No slides attached to this post.</p></div>
      <?php endif; ?>
    </div>

    <div class="editor-panel">
      <div class="editor-label">Caption</div>
      <div class="toolbar">
        <button class="tool-btn" onclick="applyBold()" title="Bold selected text">B</button>
        <button class="tool-btn" onclick="applyItalic()" title="Italic selected text">I</button>
        <div class="toolbar-divider"></div>
        <button class="tool-btn" onclick="clearFormatting()">Clear Format</button>
        <div class="toolbar-spacer"></div>
        <span class="char-count"><span id="charCount">0</span> / 3,000</span>
      </div>
      <textarea id="caption" class="caption-editor" spellcheck="true"><?= h($post['caption']) ?></textarea>

      <?php if (!$post['linkedin_account_id']): ?>
        <p class="badge badge-warning">No LinkedIn account assigned — <a href="<?= h(app_path('pages/post.php?id=' . $post['id'])) ?>">assign one</a> before posting.</p>
      <?php else: ?>
        <p class="muted">Posting as <?= h($post['account_name']) ?></p>
      <?php endif; ?>

      <button id="postBtn" class="post-btn" onclick="postNow(<?= (int) $post['id'] ?>)" <?= !$post['linkedin_account_id'] ? 'disabled' : '' ?>>
        Post to LinkedIn
      </button>
      <div id="postStatus" class="post-status" style="display:none"></div>
    </div>
  </div>
</div>
<?php elseif ($todayList): ?>
<p class="muted"><?= count($todayList) ?> posts are scheduled for today — open one to review and post it.</p>
<section class="card">
  <table class="preview-table">
    <thead><tr><th>Campaign ID</th><th>Format</th><th>Title</th><th>Account</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($todayList as $p): ?>
        <tr>
          <td><?= h($p['campaign_id']) ?></td>
          <td><?= h($p['format']) ?></td>
          <td><?= h($p['title']) ?></td>
          <td><?= h($p['account_name'] ?? '— unassigned —') ?></td>
          <td><span class="badge badge-<?= h(strtolower($p['status'])) ?>"><?= h(ucfirst($p['status'])) ?></span></td>
          <td><a href="<?= h(app_path('pages/post.php?id=' . $p['id'])) ?>" class="btn-tiny">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php else: ?>
<div class="empty-state">
  <h2>No post scheduled for today</h2>
  <p>Check your <a href="<?= h(app_path('pages/calendar.php')) ?>">calendar</a>, or <a href="<?= h(app_path('pages/import.php')) ?>">import content</a>.</p>
</div>
<?php endif; ?>

<script>
  window.SLIDES = <?= json_encode(array_column($post['slides'] ?? [], 'url')) ?>;
  window.POST_NOW_URL = <?= json_encode(app_path('api/post_now.php')) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
