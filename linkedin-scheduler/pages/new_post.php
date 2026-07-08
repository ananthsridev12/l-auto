<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/linkedin_api.php';

require_login();
$userId = current_user_id();

$availableFormats = array_values(array_intersect(['Text Post', 'Single Image'], get_enabled_formats($userId)));
$accounts = fetch_user_accounts($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/new_post.php');
    }

    $format = $_POST['format'] ?? '';
    if (!in_array($format, $availableFormats, true)) {
        flash('error', 'Choose a valid, enabled post format.');
        redirect('pages/new_post.php');
    }

    if ($format === 'Single Image' && empty($_FILES['image']['tmp_name'])) {
        flash('error', 'Upload an image for a Single Image post.');
        redirect('pages/new_post.php');
    }

    $caption    = $_POST['caption'] ?? '';
    $title      = trim($_POST['title'] ?? '');
    $accountId  = $_POST['linkedin_account_id'] !== '' ? (int) $_POST['linkedin_account_id'] : null;
    $campaignId = trim($_POST['campaign_id'] ?? '');
    if ($campaignId === '') {
        $campaignId = 'MANUAL-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    $action    = $_POST['action'] ?? 'save';
    $schedDate = trim($_POST['scheduled_date'] ?? '');
    $schedTime = trim($_POST['scheduled_time'] ?? '09:00');

    $scheduledAt = null;
    $status = 'draft';
    if ($action === 'schedule' && $schedDate !== '') {
        $scheduledAt = $schedDate . ' ' . ($schedTime ?: '09:00') . ':00';
        $status = 'scheduled';
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO posts (user_id, linkedin_account_id, campaign_id, title, format, caption, status, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $accountId, $campaignId, $title, $format, $caption, $status, $scheduledAt]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            flash('error', "Campaign ID \"{$campaignId}\" is already in use — choose another.");
            redirect('pages/new_post.php');
        }
        throw $e;
    }
    $postId = (int) db()->lastInsertId();

    if ($format === 'Single Image' && !empty($_FILES['image']['tmp_name'])) {
        $contents = file_get_contents($_FILES['image']['tmp_name']);
        $mime = zip_sniff_image_mime($contents);
        if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            flash('error', 'Image must be a PNG or JPEG file.');
            redirect('pages/new_post.php');
        }
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $filename = 'image_01.' . $ext;
        $destPath = $destDir . '/' . $filename;
        file_put_contents($destPath, $contents);
        db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, 1, ?, ?)')
            ->execute([$postId, $filename, $destPath]);
    }

    if ($action === 'post_now') {
        $result = publish_post_now($postId, $userId);
        flash($result['success'] ? 'success' : 'error', $result['success'] ? 'Posted to LinkedIn.' : $result['error']);
    } else {
        flash('success', $action === 'schedule' ? 'Post scheduled.' : 'Draft saved.');
    }

    redirect('pages/post.php?id=' . $postId);
}

$pageTitle  = 'New Post';
$activePage = 'new_post';
$pageScripts = ['formatter.js', 'app.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>New Post</h1></div>

<?php if (empty($availableFormats)): ?>
  <section class="card">
    <p class="muted">Text Post and Single Image are both disabled in <a href="<?= h(app_path('pages/settings.php')) ?>">Settings</a> — enable at least one to compose a new post here.</p>
  </section>
<?php else: ?>
<div class="post-card">
  <div class="post-layout">
    <div class="slides-panel">
      <label style="width:100%;">Format
        <select name="format" id="formatSelect" form="newPostForm">
          <?php foreach ($availableFormats as $fmt): ?>
            <option value="<?= h($fmt) ?>"><?= h($fmt) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div id="imageUploadField" style="width:100%; margin-top:12px; display:none;">
        <label>Image (PNG or JPEG)
          <input type="file" name="image" accept="image/png,image/jpeg" form="newPostForm">
        </label>
      </div>
    </div>

    <div class="editor-panel">
      <form method="post" id="newPostForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">

        <div class="editor-label">Caption</div>
        <?php include __DIR__ . '/_formatter_toolbar.php'; ?>
        <textarea id="caption" name="caption" class="caption-editor"></textarea>

        <label>Title <span class="muted">(optional)</span>
          <input type="text" name="title">
        </label>

        <label>Campaign ID <span class="muted">(optional — auto-generated if left blank)</span>
          <input type="text" name="campaign_id" placeholder="e.g. LAUNCH-01">
        </label>

        <label>LinkedIn Account
          <select name="linkedin_account_id">
            <option value="">— Unassigned —</option>
            <?php foreach ($accounts as $acct): ?>
              <option value="<?= (int) $acct['id'] ?>"><?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="schedule-row">
          <label>Date <input type="date" name="scheduled_date"></label>
          <label>Time <input type="time" name="scheduled_time" value="09:00"></label>
        </div>

        <div class="button-row">
          <button type="submit" name="action" value="save" class="btn-secondary">Save Draft</button>
          <button type="submit" name="action" value="schedule" class="btn-primary">Schedule</button>
        </div>
        <button type="submit" name="action" value="post_now" class="post-btn" style="margin-top:10px;">Post Now</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  window.MENTION_ACCOUNTS = <?= json_encode(fetch_mention_picker_list($userId)) ?>;
  (function () {
    var select = document.getElementById('formatSelect');
    var imageField = document.getElementById('imageUploadField');
    if (!select || !imageField) return;
    var toggle = function () {
      imageField.style.display = select.value === 'Single Image' ? 'block' : 'none';
    };
    select.addEventListener('change', toggle);
    toggle();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
