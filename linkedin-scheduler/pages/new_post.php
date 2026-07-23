<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/linkedin_api.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/embeddings.php';
require_once __DIR__ . '/../includes/content_memory.php';

require_login();
$userId = current_user_id();
$workspaceId = current_workspace_id();
$workspace = current_workspace();

$availableFormats = array_values(array_intersect(['Text Post', 'Single Image', 'Carousel'], get_enabled_formats($userId)));
$accounts = fetch_user_accounts($userId);
$aiConfig = resolve_ai_config($userId);
$personas = fetch_personas($userId, $workspaceId);
$contentPillars = fetch_content_pillars($userId, $workspaceId);
$brandPalettes = fetch_brand_palettes($userId);

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

    // AI-generated slides replace the manual file-upload requirement —
    // the image gets rendered server-side after the post row exists
    // (see below), same as Content Studio's confirm step.
    $aiCreative = null;
    $aiCreativeRaw = trim($_POST['ai_creative_json'] ?? '');
    if ($aiCreativeRaw !== '') {
        $decoded = json_decode($aiCreativeRaw, true);
        if (is_array($decoded) && !empty($decoded['slides'])) {
            if (count($decoded['slides']) > MAX_SLIDES_PER_CAMPAIGN) {
                flash('error', 'A Carousel can have at most ' . MAX_SLIDES_PER_CAMPAIGN . ' slides.');
                redirect('pages/new_post.php');
            }
            $aiCreative = $decoded;
        }
    }

    if ($aiCreative === null) {
        if ($format === 'Single Image' && empty($_FILES['image']['tmp_name'])) {
            flash('error', 'Upload an image for a Single Image post, or use "Generate with AI" / "Write content directly".');
            redirect('pages/new_post.php');
        }
        if ($format === 'Carousel') {
            $uploadedCount = empty($_FILES['images']['tmp_name']) ? 0 : count(array_filter($_FILES['images']['tmp_name']));
            if ($uploadedCount === 0) {
                flash('error', 'Upload at least one image for a Carousel post, or use "Generate with AI" / "Write content directly".');
                redirect('pages/new_post.php');
            }
            if ($uploadedCount > MAX_SLIDES_PER_CAMPAIGN) {
                flash('error', 'A Carousel can have at most ' . MAX_SLIDES_PER_CAMPAIGN . ' slides.');
                redirect('pages/new_post.php');
            }
        }
    }

    $caption    = $_POST['caption'] ?? '';
    $title      = trim($_POST['title'] ?? '');
    $accountId  = $_POST['linkedin_account_id'] !== '' ? (int) $_POST['linkedin_account_id'] : null;
    $campaignId = trim($_POST['campaign_id'] ?? '');
    // A duplicate campaign_id used to fail the INSERT below and redirect
    // back to a blank form, losing everything the user typed/uploaded —
    // check up front instead and fall back to an auto-suffixed id, same
    // as the blank-id case, so the save always goes through. The user is
    // told about the swap afterward rather than losing their work over it.
    $campaignIdRenamed = false;
    $originalCampaignId = $campaignId;
    if ($campaignId === '') {
        $campaignId = 'MANUAL-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    } else {
        $dupStmt = db()->prepare('SELECT 1 FROM posts WHERE user_id = ? AND campaign_id = ?');
        $dupStmt->execute([$userId, $campaignId]);
        if ($dupStmt->fetchColumn()) {
            $campaignId .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $campaignIdRenamed = true;
        }
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
        // creative_json is stored (when the image is generated rather than
        // uploaded) so it can be re-edited later from the post page.
        $storedCreative = ($aiCreative !== null && in_array($format, ['Single Image', 'Carousel'], true))
            ? json_encode($aiCreative) : null;
        $stmt = db()->prepare(
            'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, campaign_id, title, format, caption, status, scheduled_at, creative_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $workspaceId, $accountId, $campaignId, $title, $format, $caption, $status, $scheduledAt, $storedCreative]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            // The pre-check above already handles the common case — this
            // only catches a genuine race (two saves with the same typed
            // id landing at once). Retry once with a fresh suffix rather
            // than redirecting away and losing everything the user typed.
            $campaignId .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $campaignIdRenamed = true;
            $stmt->execute([$userId, $workspaceId, $accountId, $campaignId, $title, $format, $caption, $status, $scheduledAt, $storedCreative]);
        } else {
            throw $e;
        }
    }
    $postId = (int) db()->lastInsertId();

    if ($aiCreative !== null && in_array($format, ['Single Image', 'Carousel'], true)) {
        $user = current_user();
        $footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
        $photoPath = resolve_footer_image($userId, 'personal', $workspaceId);
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        try {
            $slides = render_creative_to_slides($aiCreative, $destDir, $footerName, $photoPath, $userId, $workspaceId);
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            flash('error', 'Image rendering failed: ' . $e->getMessage());
            redirect('pages/new_post.php');
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        foreach ($slides as $order => $slide) {
            $insertSlide->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
        }
    }

    if ($aiCreative === null && $format === 'Single Image' && !empty($_FILES['image']['tmp_name'])) {
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

    if ($aiCreative === null && $format === 'Carousel' && !empty($_FILES['images']['tmp_name'])) {
        $destDir = UPLOAD_DIR . '/' . $userId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $insertSlide = db()->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');
        $order = 0;
        foreach ($_FILES['images']['tmp_name'] as $i => $tmpPath) {
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                continue;
            }
            $contents = file_get_contents($tmpPath);
            $mime = zip_sniff_image_mime($contents);
            if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
                continue; // skip a bad file rather than aborting the whole carousel
            }
            $order++;
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
            $filename = sprintf('slide_%02d.%s', $order, $ext);
            $destPath = $destDir . '/' . $filename;
            file_put_contents($destPath, $contents);
            $insertSlide->execute([$postId, $order, $filename, $destPath]);
        }
        if ($order === 0) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            flash('error', 'None of the uploaded files were valid PNG/JPEG images.');
            redirect('pages/new_post.php');
        }
    }

    // Memory & Context: remember this post (any real caption, AI or
    // hand-written) so future generations in this workspace can avoid
    // repeating it — silently a no-op if embeddings aren't available
    // (Claude-only accounts) or the caption is empty.
    if (trim($caption) !== '') {
        save_content_memory($workspaceId, $postId, trim($title . ' ' . $caption), $title ?: mb_substr($caption, 0, 200), resolve_ai_config($userId));
    }

    // Prepended to whichever flash message actually ends up showing below
    // (success or error, depending on how the save/post/schedule went),
    // rather than its own flash() call — the 'error'/'success' keys can
    // only hold one message each, and this can co-occur with either.
    $renameNotice = $campaignIdRenamed
        ? "Campaign ID \"{$originalCampaignId}\" was already in use — saved as \"{$campaignId}\" instead. "
        : '';

    if ($action === 'post_now') {
        $result = publish_post_now($postId, $userId);
        flash($result['success'] ? 'success' : 'error', $renameNotice . ($result['success'] ? 'Posted to LinkedIn.' : $result['error']));
    } else {
        flash('success', $renameNotice . ($action === 'schedule' ? 'Post scheduled.' : 'Draft saved.'));
    }

    redirect('pages/post.php?id=' . $postId);
}

$pageTitle  = 'New Post';
$activePage = 'new_post';
$pageScripts = ['formatter.js', 'app.js', 'new_post_ai.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>New Post</h1></div>

<?php if (empty($availableFormats)): ?>
  <section class="card">
    <p class="muted">Text Post, Single Image, and Carousel are all disabled in <a href="<?= h(app_path('pages/settings.php')) ?>#account">Settings</a> — enable at least one to compose a new post here.</p>
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
      <div id="imageUploadField" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <label>Image (PNG or JPEG)
          <input type="file" name="image" accept="image/png,image/jpeg" form="newPostForm">
        </label>
      </div>
      <div id="carouselUploadField" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <label>Slides (PNG or JPEG, select multiple — combined into a PDF carousel, in the order selected)
          <input type="file" name="images[]" accept="image/png,image/jpeg" multiple form="newPostForm">
        </label>
      </div>

      <div style="width:100%; margin-top:12px;" id="creativeToggleRow">
        <label class="checkbox-row">
          <input type="checkbox" id="aiGenerateToggle" <?= ai_configured($aiConfig) ? '' : 'disabled' ?>>
          Generate with AI instead
        </label>
        <?php if (!ai_configured($aiConfig)): ?>
          <p class="muted">Add an AI provider key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to use this.</p>
        <?php endif; ?>
        <label class="checkbox-row" id="manualToggleLabel">
          <input type="checkbox" id="manualCreativeToggle">
          Write content directly (no AI) — auto-generate the image from text you type in
        </label>
      </div>

      <div id="ctaFieldsPanel" style="width:100%; margin-top:12px; display:none;">
        <label class="checkbox-row">
          <input type="checkbox" id="ctaEnabled">
          Include a CTA
        </label>
        <input type="text" id="ctaText" placeholder="e.g. Book a call with our team" style="width:100%; margin-top:6px; display:none;">
      </div>

      <div id="aiGenerateFields" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <label>Topic / Title
          <input type="text" id="aiTopic">
        </label>

        <label>Length
          <select id="aiLength">
            <option value="very_short">Very Short (~40-60 words)</option>
            <option value="short">Short (~80-120 words)</option>
            <option value="medium" selected>Medium (~150-250 words)</option>
            <option value="long">Long (~300-400 words)</option>
            <option value="blog_length">Blog Length (~500-700 words)</option>
          </select>
        </label>

        <label>Persona <span class="muted">(optional)</span>
          <select id="aiPersonaSelect">
            <option value="">— None —</option>
            <?php foreach ($personas as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= h($p['name']) ?></option>
            <?php endforeach; ?>
            <option value="custom">Custom / type my own…</option>
          </select>
        </label>
        <input type="text" id="aiPersona" placeholder="Describe the target persona" style="width:100%; margin-top:6px; display:none;">

        <label>Content Pillar / Style <span class="muted">(optional)</span>
          <select id="aiPillarSelect">
            <option value="">— None —</option>
            <?php foreach ($contentPillars as $cp): ?>
              <option value="<?= (int) $cp['id'] ?>"><?= h($cp['name']) ?></option>
            <?php endforeach; ?>
            <option value="custom">Custom / type my own…</option>
          </select>
        </label>
        <input type="text" id="aiType" placeholder="e.g. Case Study, Checklist" style="width:100%; margin-top:6px; display:none;">

        <button type="button" id="aiGenerateBtn" class="btn-secondary" style="margin-top:8px;">Generate</button>
        <p id="aiGenerateStatus" class="muted"></p>
      </div>

      <div id="creativeSlidesPanel" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <label>Color Palette <span class="muted">(optional)</span>
          <select id="aiTemplateSelect">
            <option value="">Auto</option>
            <option value="1">Cream</option>
            <option value="2">Dark Green</option>
            <option value="3">Olive</option>
            <option value="4">Medium Green</option>
            <?php foreach ($brandPalettes as $bp): ?>
              <option value="custom:<?= (int) $bp['id'] ?>"><?= h($bp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Design Template <span class="muted">(optional)</span></label>
        <?= render_template_picker_html('classic', '_ai') ?>
        <label>Background <span class="muted">(optional)</span>
          <select id="aiBackgroundSelect">
            <option value="flat">Flat</option>
            <option value="gradient">Gradient</option>
            <option value="image">Image (needs a palette with a background photo uploaded)</option>
          </select>
        </label>
        <label>Size <span class="muted">(optional)</span>
          <select id="aiSizeSelect">
            <option value="square">Square (1:1)</option>
            <option value="portrait">Portrait (4:5, Document)</option>
          </select>
        </label>
        <label>Text Position <span class="muted">(optional)</span>
          <select id="aiTextPositionSelect">
            <option value="top">Top (default)</option>
            <option value="center">Center</option>
            <option value="bottom">Bottom</option>
          </select>
        </label>
        <label>Text Size <span class="muted">(optional — 100% is default)</span></label>
        <div class="font-scale-group">
          <label class="field-row">Headline <input type="range" class="font-scale-slider" data-role="headline" min="50" max="200" value="100" oninput="this.nextElementSibling.textContent = this.value + '%'"><span>100%</span></label>
          <label class="field-row">Subheading <input type="range" class="font-scale-slider" data-role="subheading" min="50" max="200" value="100" oninput="this.nextElementSibling.textContent = this.value + '%'"><span>100%</span></label>
          <label class="field-row">Body <input type="range" class="font-scale-slider" data-role="body" min="50" max="200" value="100" oninput="this.nextElementSibling.textContent = this.value + '%'"><span>100%</span></label>
          <label class="field-row">Points <input type="range" class="font-scale-slider" data-role="points" min="50" max="200" value="100" oninput="this.nextElementSibling.textContent = this.value + '%'"><span>100%</span></label>
        </div>
        <div id="aiSlidesReview"></div>
        <button type="button" id="addSlideBtn" class="btn-tiny" style="display:none; margin-top:8px;">+ Add Slide</button>

        <button type="button" id="previewImageBtn" class="btn-secondary" style="margin-top:12px;">Generate Image Preview</button>
        <p id="previewStatus" class="muted"></p>
        <div id="imagePreviewResult" style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;"></div>
      </div>
    </div>

    <div class="editor-panel">
      <form method="post" id="newPostForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="ai_creative_json" id="aiCreativeJsonField">

        <div class="editor-label">Caption</div>
        <?php include __DIR__ . '/_formatter_toolbar.php'; ?>
        <textarea id="caption" name="caption" class="caption-editor"></textarea>

        <label>Title <span class="muted">(optional)</span>
          <input type="text" name="title" id="titleField">
        </label>

        <label>Campaign ID <span class="muted">(optional — auto-generated if left blank)</span>
          <input type="text" name="campaign_id" placeholder="e.g. LAUNCH-01">
        </label>

        <label>LinkedIn Account
          <select name="linkedin_account_id">
            <option value="">— Unassigned —</option>
            <?php foreach ($accounts as $acct): ?>
              <option value="<?= (int) $acct['id'] ?>"<?= (int) ($workspace['linkedin_account_id'] ?? 0) === (int) $acct['id'] ? ' selected' : '' ?>><?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)</option>
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
  window.AI_GENERATE_PREVIEW_URL = <?= json_encode(app_path('api/ai_generate_preview.php')) ?>;
  window.IMAGE_PREVIEW_URL = <?= json_encode(app_path('api/new_post_preview_image.php')) ?>;
  window.NEW_POST_CSRF = <?= json_encode($token) ?>;
  window.MAX_SLIDES_PER_CAMPAIGN = <?= (int) MAX_SLIDES_PER_CAMPAIGN ?>;
  (function () {
    var select = document.getElementById('formatSelect');
    var imageField = document.getElementById('imageUploadField');
    var carouselField = document.getElementById('carouselUploadField');
    var aiToggle = document.getElementById('aiGenerateToggle');
    var manualToggle = document.getElementById('manualCreativeToggle');
    if (!select || !imageField || !carouselField) return;
    var toggle = function () {
      var usingCreativeJson = (aiToggle && aiToggle.checked) || (manualToggle && manualToggle.checked);
      imageField.style.display = (!usingCreativeJson && select.value === 'Single Image') ? 'flex' : 'none';
      carouselField.style.display = (!usingCreativeJson && select.value === 'Carousel') ? 'flex' : 'none';
    };
    window.newPostUpdateUploadFields = toggle;
    select.addEventListener('change', toggle);
    toggle();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
