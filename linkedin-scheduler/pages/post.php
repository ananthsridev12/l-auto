<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/linkedin_api.php';

require_login();
$userId = current_user_id();
$postId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/post.php?id=' . $postId);
    }

    $existing = fetch_post_with_slides($postId, $userId);
    if (!$existing) {
        flash('error', 'Post not found.');
        redirect('pages/calendar.php');
    }

    $action = $_POST['action'] ?? 'save';

    // "unpublish" is the one action allowed on an already-posted post —
    // it's an explicit manual override for when the user has deleted the
    // LinkedIn post themselves and wants to reuse/reschedule the content.
    // The app has no way to detect that on its own (would require calling
    // LinkedIn's API to check the post still exists), so this is opt-in.
    if ($action === 'unpublish') {
        if ($existing['status'] !== 'posted') {
            flash('error', 'Only a published post can be marked as unpublished.');
            redirect('pages/post.php?id=' . $postId);
        }
        $stmt = db()->prepare(
            'UPDATE posts SET status = "draft", scheduled_at = NULL, posted_at = NULL, li_post_urn = NULL, error_message = NULL
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$postId, $userId]);
        flash('success', 'Marked as not posted — this is now a draft you can edit and reschedule.');
        redirect('pages/post.php?id=' . $postId);
    }

    if ($existing['status'] === 'posted') {
        flash('error', 'This post has already been published and can no longer be edited.');
        redirect('pages/post.php?id=' . $postId);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM posts WHERE id = ? AND user_id = ?');
        $stmt->execute([$postId, $userId]);
        flash('success', 'Post deleted.');
        redirect('pages/calendar.php');
    }

    $caption    = $_POST['caption'] ?? '';
    $title      = trim($_POST['title'] ?? '');
    $accountId  = $_POST['linkedin_account_id'] !== '' ? (int) $_POST['linkedin_account_id'] : null;
    $schedDate  = trim($_POST['scheduled_date'] ?? '');
    $schedTime  = trim($_POST['scheduled_time'] ?? '09:00');

    $scheduledAt = null;
    $status = 'draft';
    if ($action === 'schedule' && $schedDate !== '') {
        if (!in_array($existing['format'], get_enabled_formats($userId), true)) {
            flash('error', "\"{$existing['format']}\" posting is disabled in Settings, so this can't be scheduled — enable it there first, or change this post's format.");
            redirect('pages/post.php?id=' . $postId);
        }
        $scheduledAt = $schedDate . ' ' . ($schedTime ?: '09:00') . ':00';
        $status = 'scheduled';
    }

    $stmt = db()->prepare(
        'UPDATE posts SET caption = ?, title = ?, linkedin_account_id = ?, scheduled_at = ?, status = ?
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$caption, $title, $accountId, $scheduledAt, $status, $postId, $userId]);

    flash('success', $action === 'schedule' ? 'Post scheduled.' : 'Draft saved.');
    redirect('pages/post.php?id=' . $postId);
}

$post = fetch_post_with_slides($postId, $userId);
if (!$post) {
    flash('error', 'Post not found.');
    redirect('pages/calendar.php');
}
$accounts = fetch_user_accounts($userId);
$formatDisabled = !in_array($post['format'], get_enabled_formats($userId), true);

// Posts whose image was generated from creative JSON (AI or "write
// content directly", any of the three generation flows) can have that
// content re-edited and the image re-rendered — see api/post_rerender.php.
// Uploaded images have no stored content, so they get no editor.
$creative = json_decode((string) ($post['creative_json'] ?? ''), true);
$canReedit = $post['status'] !== 'posted'
    && in_array($post['format'], ['Single Image', 'Carousel'], true)
    && is_array($creative) && !empty($creative['slides']);
$brandPalettes = $canReedit ? fetch_brand_palettes($userId) : [];

$pageTitle   = $post['campaign_id'] ?: 'Edit Post';
$activePage  = 'calendar';
$pageScripts = $canReedit ? ['formatter.js', 'app.js', 'post_reedit.js'] : ['formatter.js', 'app.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';

$schedDateVal = $post['scheduled_at'] ? substr($post['scheduled_at'], 0, 10) : '';
$schedTimeVal = $post['scheduled_at'] ? substr($post['scheduled_at'], 11, 5) : '09:00';
?>
<div class="page-header">
  <h1><?= h($post['campaign_id']) ?></h1>
  <span class="badge badge-<?= h(strtolower($post['status'])) ?>"><?= h(ucfirst($post['status'])) ?></span>
</div>

<?php if ($formatDisabled): ?>
  <p class="badge badge-warning">"<?= h($post['format']) ?>" posting is disabled in <a href="<?= h(app_path('pages/settings.php')) ?>#account">Settings</a> — this post can't be scheduled or posted until it's enabled, or you change its format.</p>
<?php endif; ?>

<div class="post-card">
  <div class="post-meta-bar">
    <span class="badge badge-format"><?= h($post['format']) ?></span>
    <span class="post-title-text"><?= h($post['title']) ?></span>
  </div>

  <div class="post-layout">
    <div class="slides-panel">
      <?php if ($post['slides']): ?>
        <div class="slide-frame"><img id="slideImg" src="<?= h($post['slides'][0]['url']) ?>" alt="Slide preview"></div>
        <?php if (count($post['slides']) > 1): ?>
        <div class="slide-nav">
          <button class="slide-btn" onclick="prevSlide()">&#8592;</button>
          <span id="slideCounter">1 / <?= count($post['slides']) ?></span>
          <button class="slide-btn" onclick="nextSlide()">&#8594;</button>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="no-slides"><p>No slides attached.</p></div>
      <?php endif; ?>
    </div>

    <div class="editor-panel">
      <?php if ($post['status'] === 'posted'): ?>
        <div class="editor-label">Caption (published — read only)</div>
        <textarea class="caption-editor" readonly><?= h($post['caption']) ?></textarea>
        <p class="muted">
          Posted <?= h($post['posted_at']) ?><?= $post['account_name'] ? ' as ' . h($post['account_name']) : '' ?>
          <?php $postUrl = li_post_url($post['li_post_urn'] ?? null); ?>
          <?php if ($postUrl): ?>
             — <a href="<?= h($postUrl) ?>" target="_blank" rel="noopener noreferrer">View on LinkedIn</a>
          <?php endif; ?>
        </p>

        <form method="post" onsubmit="return confirm('Only do this if you deleted the post on LinkedIn yourself. This turns it back into an editable draft here — it will NOT delete or repost anything on LinkedIn automatically.');" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="unpublish">
          <button type="submit" class="btn-secondary">I deleted this on LinkedIn — reset to draft</button>
        </form>
      <?php else: ?>
        <form method="post" id="postForm">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">

          <div class="editor-label">Caption</div>
          <?php include __DIR__ . '/_formatter_toolbar.php'; ?>
          <textarea id="caption" name="caption" class="caption-editor"><?= h($post['caption']) ?></textarea>

          <label>Title
            <input type="text" name="title" value="<?= h($post['title']) ?>">
          </label>

          <label>LinkedIn Account
            <select name="linkedin_account_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($accounts as $acct): ?>
                <option value="<?= (int) $acct['id'] ?>" <?= $post['linkedin_account_id'] == $acct['id'] ? 'selected' : '' ?>>
                  <?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="schedule-row">
            <label>Date <input type="date" name="scheduled_date" value="<?= h($schedDateVal) ?>"></label>
            <label>Time <input type="time" name="scheduled_time" value="<?= h($schedTimeVal) ?>"></label>
          </div>

          <div class="button-row">
            <button type="submit" name="action" value="save" class="btn-secondary">Save Draft</button>
            <button type="submit" name="action" value="schedule" class="btn-primary">Schedule</button>
          </div>
        </form>

        <button id="postBtn" class="post-btn" onclick="postNow(<?= (int) $post['id'] ?>)" <?= (!$post['linkedin_account_id'] || $formatDisabled) ? 'disabled' : '' ?>>
          Post Now
        </button>
        <div id="postStatus" class="post-status" style="display:none"></div>

        <?php if ($post['error_message']): ?>
          <p class="badge badge-warning">Last error: <?= h($post['error_message']) ?></p>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Delete this post permanently?');" style="margin-top:20px;">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn-tiny btn-danger">Delete Post</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canReedit): ?>
<section class="card" id="reeditCard">
  <h2>Edit Image Content</h2>
  <p class="muted">This image was generated from the content below — edit it and re-render to replace the image. The caption above is separate and saves with the form as usual.</p>
  <?php foreach ($creative['slides'] as $si => $slide): ?>
    <fieldset class="slide-fieldset">
      <legend>Slide <?= $si + 1 ?></legend>
      <label class="field-row">Headline
        <input type="text" class="reedit-headline" value="<?= h($slide['headline'] ?? '') ?>">
      </label>
      <label class="field-row">Body
        <textarea class="reedit-body" rows="2"><?= h($slide['body'] ?? '') ?></textarea>
      </label>
      <label class="field-row">Points (one per line)
        <textarea class="reedit-points" rows="3"><?= h(implode("\n", $slide['points'] ?? [])) ?></textarea>
      </label>
    </fieldset>
  <?php endforeach; ?>
  <label>Color Palette
    <select id="reeditTemplateSelect">
      <?= render_palette_select_options($creative['template'] ?? null, $brandPalettes, true) ?>
    </select>
  </label>
  <label>Design Template</label>
  <?= render_template_picker_html($creative['layout'] ?? 'classic', '_reedit') ?>
  <label>Background
    <select id="reeditBackgroundSelect">
      <option value="flat"<?= ($creative['background'] ?? 'flat') === 'flat' ? ' selected' : '' ?>>Flat</option>
      <option value="gradient"<?= ($creative['background'] ?? '') === 'gradient' ? ' selected' : '' ?>>Gradient</option>
      <option value="image"<?= ($creative['background'] ?? '') === 'image' ? ' selected' : '' ?>>Image (needs a palette with a background photo uploaded)</option>
    </select>
  </label>
  <label>Size
    <select id="reeditSizeSelect">
      <option value="square"<?= ($creative['size'] ?? 'square') === 'square' ? ' selected' : '' ?>>Square (1:1)</option>
      <option value="portrait"<?= ($creative['size'] ?? '') === 'portrait' ? ' selected' : '' ?>>Portrait (4:5, Document)</option>
    </select>
  </label>
  <label>Text Position
    <select id="reeditTextPositionSelect">
      <option value="top"<?= ($creative['text_position'] ?? 'top') === 'top' ? ' selected' : '' ?>>Top (default)</option>
      <option value="center"<?= ($creative['text_position'] ?? '') === 'center' ? ' selected' : '' ?>>Center</option>
      <option value="bottom"<?= ($creative['text_position'] ?? '') === 'bottom' ? ' selected' : '' ?>>Bottom</option>
    </select>
  </label>
  <label class="field-row">Text Size <span class="muted">(optional — 100% is default)</span></label>
  <div class="font-scale-group">
    <?php foreach (['headline' => 'Headline', 'subheading' => 'Subheading', 'body' => 'Body', 'points' => 'Points'] as $fsRole => $fsLabel): $fsVal = (int) ($creative['font_scale'][$fsRole] ?? 100); ?>
      <label class="field-row"><?= h($fsLabel) ?> <input type="range" class="reedit-font-scale-slider" data-role="<?= h($fsRole) ?>" min="50" max="200" value="<?= $fsVal ?>" oninput="this.nextElementSibling.textContent = this.value + '%'"><span><?= $fsVal ?>%</span></label>
    <?php endforeach; ?>
  </div>
  <button type="button" id="reeditRenderBtn" class="btn-secondary" style="margin-top:12px;">Re-render Image</button>
  <p id="reeditStatus" class="muted"></p>
</section>
<?php endif; ?>

<script>
  window.SLIDES = <?= json_encode(array_column($post['slides'], 'url')) ?>;
  window.POST_NOW_URL = <?= json_encode(app_path('api/post_now.php')) ?>;
  window.MENTION_ACCOUNTS = <?= json_encode(fetch_mention_picker_list($userId)) ?>;
  <?php if ($canReedit): ?>
  window.POST_REEDIT = {
    url: <?= json_encode(app_path('api/post_rerender.php')) ?>,
    csrf: <?= json_encode($token) ?>,
    postId: <?= (int) $post['id'] ?>
  };
  <?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
