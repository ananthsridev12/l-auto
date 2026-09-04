<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/zip_import.php';
require_once __DIR__ . '/../includes/linkedin_api.php';
require_once __DIR__ . '/../includes/social_publish.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/embeddings.php';
require_once __DIR__ . '/../includes/content_memory.php';
require_once __DIR__ . '/../includes/collections.php';
require_once __DIR__ . '/../includes/stock_images.php';

require_login();
require_module('post_scheduling');
$userId = current_user_id();
$workspaceId = current_workspace_id();
$workspace = current_workspace();

$availableFormats = array_values(array_intersect(['Text Post', 'Single Image', 'Carousel'], get_enabled_formats($userId)));
$accounts = fetch_user_accounts($userId, $workspaceId);
$facebookAccounts = fetch_user_social_accounts($userId, 'facebook');
$instagramAccounts = fetch_user_social_accounts($userId, 'instagram');
// Which of $availableFormats each platform actually supports — used to
// filter the Format picker client-side (assets/js/new_post_platform.js)
// once a non-LinkedIn platform is chosen. Instagram has no true
// text-only post; the others match LinkedIn's own set for now.
$platformFormats = [
    'linkedin'  => ['Text Post', 'Single Image', 'Carousel'],
    'facebook'  => ['Text Post', 'Single Image', 'Carousel'],
    'instagram' => ['Single Image', 'Carousel'],
];
$aiConfig = resolve_ai_config($userId);
$personas = fetch_personas($userId, $workspaceId);
$contentPillars = fetch_content_pillars($userId, $workspaceId);
$contentCollections = fetch_content_collections($userId, $workspaceId);
$brandPalettes = fetch_brand_palettes(workspace_brand_user_id($userId, $workspaceId));
$services = fetch_services($workspaceId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/new_post.php');
    }

    $platform = in_array($_POST['platform'] ?? '', ['linkedin', 'facebook', 'instagram'], true) ? $_POST['platform'] : 'linkedin';

    $format = $_POST['format'] ?? '';
    if (!in_array($format, $availableFormats, true) || !in_array($format, $platformFormats[$platform], true)) {
        flash('error', 'Choose a valid, enabled post format for the selected platform.');
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

    // A stock photo (Unsplash) or a plain AI-generated photo picked in
    // the "Stock/AI Photo" panel — a third, mutually-exclusive
    // alternative to uploading a file or generating a branded slide.
    // Only offered for Single Image (a real photo doesn't make sense as
    // one slide of a branded carousel).
    $stockImageUrl = $format === 'Single Image' ? trim($_POST['stock_image_url'] ?? '') : '';
    $stockDownloadLocation = trim($_POST['stock_download_location'] ?? '');
    $stockAiDataUrl = $format === 'Single Image' ? trim($_POST['stock_ai_image_b64'] ?? '') : '';
    $usingStockOrAiPhoto = $stockImageUrl !== '' || $stockAiDataUrl !== '';

    if ($aiCreative === null && !$usingStockOrAiPhoto) {
        if ($format === 'Single Image' && empty($_FILES['image']['tmp_name'])) {
            flash('error', 'Upload an image for a Single Image post, or use "Generate with AI" / "Write content directly" / "Stock/AI Photo".');
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
    $accountId  = null;
    $socialAccountId = null;
    if ($platform === 'linkedin') {
        $accountId = ($_POST['linkedin_account_id'] ?? '') !== '' ? (int) $_POST['linkedin_account_id'] : null;
        if ($accountId !== null && !account_usable_in_workspace($accountId, $userId, $workspaceId)) {
            $accountId = null;
        }
    } else {
        $socialAccountField = $platform === 'facebook' ? 'facebook_account_id' : 'instagram_account_id';
        $socialAccountId = ($_POST[$socialAccountField] ?? '') !== '' ? (int) $_POST[$socialAccountField] : null;
        if ($socialAccountId !== null && !social_account_usable($socialAccountId, $userId)) {
            $socialAccountId = null;
        }
    }
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

    $collectionId = (int) ($_POST['collection_id'] ?? 0) ?: null;
    if ($collectionId !== null && !fetch_content_collection($userId, $collectionId)) {
        $collectionId = null;
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
            'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, platform, social_account_id, campaign_id, collection_id, title, format, caption, status, scheduled_at, creative_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $workspaceId, $accountId, $platform, $socialAccountId, $campaignId, $collectionId, $title, $format, $caption, $status, $scheduledAt, $storedCreative]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            // The pre-check above already handles the common case — this
            // only catches a genuine race (two saves with the same typed
            // id landing at once). Retry once with a fresh suffix rather
            // than redirecting away and losing everything the user typed.
            $campaignId .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $campaignIdRenamed = true;
            $stmt->execute([$userId, $workspaceId, $accountId, $platform, $socialAccountId, $campaignId, $collectionId, $title, $format, $caption, $status, $scheduledAt, $storedCreative]);
        } else {
            throw $e;
        }
    }
    $postId = (int) db()->lastInsertId();

    if ($aiCreative !== null && in_array($format, ['Single Image', 'Carousel'], true)) {
        $user = current_user();
        $footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
        // New post's files land under the workspace owner's directory
        // (not whoever's composing it), so a later re-render by anyone
        // else granted this page resolves the same location — see
        // api/post_rerender.php.
        $brandUserId = workspace_brand_user_id($userId, $workspaceId);
        $photoPath = resolve_footer_image($brandUserId, resolve_post_category($workspace), $workspaceId);
        $destDir = UPLOAD_DIR . '/' . $brandUserId . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);

        // Background: Stock/AI Photo — an ad-hoc photo (picked from the
        // same search/generate panel used for the raw-photo path below,
        // just submitted under its own bg_* field names) used as the
        // branded slide's background instead of a saved Brand Palette
        // photo. Applies to every slide of a Carousel too, since
        // render_creative_to_slides() already shares one background
        // image across all slides. No-op unless the panel was actually
        // used for this generation.
        try {
            $bgPath = save_stock_or_ai_background($userId, $destDir, trim($_POST['bg_stock_image_url'] ?? ''), trim($_POST['bg_stock_ai_image_b64'] ?? ''), trim($_POST['bg_stock_download_location'] ?? ''));
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            flash('error', 'Could not use the selected background photo: ' . $e->getMessage());
            redirect('pages/new_post.php');
        }
        if ($bgPath !== null) {
            // Only force 'image' when the client didn't already pick the
            // 'side_image' (fading side-photo) style — that style also
            // consumes background_image_override, just interpreted
            // differently by the renderer, so it must survive here.
            if (($aiCreative['background'] ?? '') !== 'side_image') {
                $aiCreative['background'] = 'image';
            }
            $aiCreative['background_image_override'] = $bgPath;
            // Persisted back into the row's stored creative_json (not
            // just used for this render) so a later "Re-render Image"
            // on the edit page (api/post_rerender.php) keeps using this
            // same background file instead of silently falling back to
            // the palette's own saved photo.
            db()->prepare('UPDATE posts SET creative_json = ? WHERE id = ?')->execute([json_encode($aiCreative), $postId]);
        }

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

    if ($aiCreative === null && $usingStockOrAiPhoto) {
        try {
            if ($stockAiDataUrl !== '') {
                if (!preg_match('#^data:image/(?:png|jpeg);base64,(.+)$#', $stockAiDataUrl, $m)) {
                    throw new RuntimeException('Invalid generated image data.');
                }
                $contents = base64_decode($m[1]);
            } else {
                $fetched = unsplash_fetch_image($stockImageUrl);
                $contents = $fetched['bytes'];
                if ($stockDownloadLocation !== '') {
                    $unsplashKey = get_unsplash_access_key($userId);
                    if ($unsplashKey) {
                        unsplash_track_download($stockDownloadLocation, $unsplashKey);
                    }
                }
            }
            // Never trust the client's claimed source/mime — re-sniff the
            // actual decoded/downloaded bytes, same as every other upload
            // path in this file.
            $mime = zip_sniff_image_mime((string) $contents);
            if (!in_array($mime, ALLOWED_SLIDE_MIME, true)) {
                throw new RuntimeException('That image could not be used — not a valid PNG/JPEG.');
            }
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
            flash('error', 'Could not attach the selected photo: ' . $e->getMessage());
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
        $result = publish_social_post_now($postId, $userId);
        flash($result['success'] ? 'success' : 'error', $renameNotice . ($result['success'] ? 'Posted.' : $result['error']));
    } else {
        flash('success', $renameNotice . ($action === 'schedule' ? 'Post scheduled.' : 'Draft saved.'));
    }

    redirect('pages/post.php?id=' . $postId);
}

$pageTitle  = 'New Post';
$activePage = 'new_post';
$pageScripts = ['formatter.js', 'app.js', 'new_post_ai.js', 'stock_photo.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>New Post</h1></div>

<?php if (empty($availableFormats)): ?>
  <section class="card">
    <p class="muted">Text Post, Single Image, and Carousel are all disabled in <a href="<?= h(app_path('pages/settings.php')) ?>#account">Settings</a> — enable at least one to compose a new post here.</p>
  </section>
<?php else: ?>
<div class="post-card post-card--composer">
  <div class="post-composer">
    <div class="post-col-input">
      <div class="post-col-heading">Settings</div>
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

      <?php $aiModuleOff = !module_enabled('ai_generation'); ?>
      <div style="width:100%; margin-top:12px;" id="creativeToggleRow">
        <label class="checkbox-row">
          <input type="checkbox" id="aiGenerateToggle" <?= (ai_configured($aiConfig) && !$aiModuleOff) ? '' : 'disabled' ?>>
          Generate with AI instead
        </label>
        <?php if ($aiModuleOff): ?>
          <p class="muted">AI generation is not enabled for your organization. Contact your organization admin.</p>
        <?php elseif (!ai_configured($aiConfig)): ?>
          <p class="muted">Add an AI provider key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to use this.</p>
        <?php endif; ?>
        <label class="checkbox-row" id="manualToggleLabel">
          <input type="checkbox" id="manualCreativeToggle">
          Write content directly (no AI) — auto-generate the image from text you type in
        </label>
        <?php
          $stockSearchUsable = unsplash_configured(get_unsplash_access_key($userId));
          $stockAiUsable = ai_configured($aiConfig) && !$aiModuleOff;
        ?>
        <label class="checkbox-row" id="stockPhotoToggleLabel">
          <input type="checkbox" id="stockPhotoToggle" <?= ($stockSearchUsable || $stockAiUsable) ? '' : 'disabled' ?>>
          Stock/AI Photo — use a real photo instead of a branded slide
        </label>
        <?php if (!$stockSearchUsable && !$stockAiUsable): ?>
          <p class="muted">Add an Unsplash Access Key or an AI provider key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to use this.</p>
        <?php endif; ?>
      </div>

      <div id="stockPhotoPanel" style="width:100%; margin-top:12px; display:none;">
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-secondary" id="stockSearchTabBtn">Search Stock Photos</button>
          <button type="button" class="btn-tiny" id="stockAiTabBtn">Generate with AI</button>
        </div>
        <div id="stockSearchTab" style="margin-top:8px;">
          <?php if ($stockSearchUsable): ?>
            <div style="display:flex; gap:6px;">
              <input type="text" id="stockSearchQuery" placeholder="e.g. team meeting, factory floor, city skyline" style="flex:1;">
              <button type="button" class="btn-secondary" id="stockSearchBtn">Search</button>
            </div>
            <p id="stockSearchStatus" class="muted"></p>
            <div id="stockSearchResults" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:6px; margin-top:8px;"></div>
          <?php else: ?>
            <p class="muted">Add an Unsplash Access Key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to search stock photos.</p>
          <?php endif; ?>
        </div>
        <div id="stockAiTab" style="margin-top:8px; display:none;">
          <?php if ($stockAiUsable): ?>
            <textarea id="stockAiPrompt" rows="3" style="width:100%;" placeholder="Describe the photo/graphic you want — e.g. &quot;A warm, editorial-style photo of a small team collaborating around a laptop, natural light&quot;"></textarea>
            <button type="button" class="btn-secondary" id="stockAiGenBtn" style="margin-top:6px;">Generate</button>
            <p id="stockAiStatus" class="muted"></p>
            <div id="stockAiResult" style="margin-top:8px;"></div>
          <?php else: ?>
            <p class="muted">Add an AI provider key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to generate a photo.</p>
          <?php endif; ?>
        </div>
        <div id="stockSelectedPreview" style="margin-top:8px; display:none;"></div>
        <input type="hidden" name="stock_image_url" id="stockImageUrlField" form="newPostForm">
        <input type="hidden" name="stock_download_location" id="stockDownloadLocationField" form="newPostForm">
        <input type="hidden" name="stock_ai_image_b64" id="stockAiB64Field" form="newPostForm">
      </div>

      <div id="ctaFieldsPanel" style="width:100%; margin-top:12px; display:none;">
        <label class="checkbox-row">
          <input type="checkbox" id="ctaEnabled">
          Include a CTA
        </label>
        <input type="text" id="ctaText" placeholder="e.g. Book a call with our team" style="width:100%; margin-top:6px; display:none;">
        <label id="ctaStyleLabel" style="width:100%; margin-top:6px; display:none;">CTA Style
          <select id="ctaStyleSelect">
            <option value="text">Text (default)</option>
            <option value="button">Button</option>
            <option value="outline">Outline Button</option>
          </select>
        </label>
      </div>

      <div id="aiGenerateFields" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <div class="prompt-mode-toggle">
          <input type="radio" name="aiPromptMode" id="aiPromptModeKb" value="kb" checked>
          <label for="aiPromptModeKb">Regular</label>
          <input type="radio" name="aiPromptMode" id="aiPromptModeCustom" value="custom">
          <label for="aiPromptModeCustom">Custom Prompt</label>
        </div>
        <p class="prompt-mode-hint" id="aiKbHint">Uses your Knowledge Base (persona, pillar, brand voice, documents) to write the post.</p>
        <p class="prompt-mode-hint" id="aiCustomHint" style="display:none;">No Knowledge Base is referenced — the AI follows only what you type below. There's no Length picker in this mode; word count and structure are entirely up to your prompt.</p>

        <div id="aiKbFields">
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

          <?php if ($services): ?>
          <label>Service being pitched <span class="muted">(optional)</span>
            <select id="aiServiceSelect">
              <option value="">— None —</option>
              <?php foreach ($services as $svc): ?>
                <option value="<?= (int) $svc['id'] ?>"><?= h($svc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>
        </div>

        <div id="aiCustomFields" style="display:none;">
          <label>Your Prompt
            <textarea id="aiCustomPromptInput" rows="7" placeholder="Write the full prompt yourself — e.g. &quot;Write a LinkedIn post announcing our new pricing tiers, aimed at startup founders, upbeat tone, ending with a question.&quot;"></textarea>
          </label>
        </div>

        <button type="button" id="aiGenerateBtn" class="btn-secondary" style="margin-top:8px;">Generate</button>
        <p id="aiGenerateStatus" class="muted"></p>
      </div>

      <div id="aiSettingsPanel" class="stacked-form" style="width:100%; margin-top:12px; display:none;">
        <label>Eyebrow / Series Label <span class="muted">(optional — small label above the logo on slide 1)</span>
          <input type="text" id="aiSeriesLabelInput" placeholder="e.g. Product Updates · Educational">
        </label>
        <label class="checkbox-row"><input type="checkbox" id="aiAccentLiteralToggle"> Use accent color literally for <code>**bold**</code> text <span class="muted">(skips the safe headline/body swap — only enable if your accent color has good contrast against the background)</span></label>
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
            <option value="image">Image</option>
            <option value="side_image">Side Photo (fading)</option>
          </select>
        </label>
        <div id="aiBgSideRow" style="width:100%; margin-top:6px; display:none;">
          <label>Photo Side
            <select id="aiImageSideSelect">
              <option value="right">Right (text on left)</option>
              <option value="left">Left (text on right)</option>
            </select>
          </label>
        </div>
        <div id="aiBgImageSourceRow" style="width:100%; margin-top:6px; display:none;">
          <label class="checkbox-row"><input type="radio" name="ai_bg_image_source" value="palette" checked> Use the palette's saved background photo</label>
          <label class="checkbox-row"><input type="radio" name="ai_bg_image_source" value="stock_ai"> Pick a Stock/AI Photo now</label>
        </div>
        <div id="aiBgStockPhotoPicker" style="width:100%; margin-top:8px; display:none;">
          <div style="display:flex; gap:6px;">
            <button type="button" class="btn-secondary" id="bgStockSearchTabBtn">Search Stock Photos</button>
            <button type="button" class="btn-tiny" id="bgStockAiTabBtn">Generate with AI</button>
          </div>
          <div id="bgStockSearchTab" style="margin-top:8px;">
            <?php if ($stockSearchUsable): ?>
              <div style="display:flex; gap:6px;">
                <input type="text" id="bgStockSearchQuery" placeholder="e.g. team meeting, factory floor, city skyline" style="flex:1;">
                <button type="button" class="btn-secondary" id="bgStockSearchBtn">Search</button>
              </div>
              <p id="bgStockSearchStatus" class="muted"></p>
              <div id="bgStockSearchResults" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:6px; margin-top:8px;"></div>
            <?php else: ?>
              <p class="muted">Add an Unsplash Access Key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to search stock photos.</p>
            <?php endif; ?>
          </div>
          <div id="bgStockAiTab" style="margin-top:8px; display:none;">
            <?php if ($stockAiUsable): ?>
              <textarea id="bgStockAiPrompt" rows="3" style="width:100%;" placeholder="Describe the photo/graphic you want to use as the background"></textarea>
              <button type="button" class="btn-secondary" id="bgStockAiGenBtn" style="margin-top:6px;">Generate</button>
              <p id="bgStockAiStatus" class="muted"></p>
              <div id="bgStockAiResult" style="margin-top:8px;"></div>
            <?php else: ?>
              <p class="muted">Add an AI provider key in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to generate a photo.</p>
            <?php endif; ?>
          </div>
          <div id="bgStockSelectedPreview" style="margin-top:8px; display:none;"></div>
          <input type="hidden" name="bg_stock_image_url" id="bgStockImageUrlField" form="newPostForm">
          <input type="hidden" name="bg_stock_download_location" id="bgStockDownloadLocationField" form="newPostForm">
          <input type="hidden" name="bg_stock_ai_image_b64" id="bgStockAiB64Field" form="newPostForm">
        </div>
        <label class="field-row" id="aiBgOpacityRow">Background Image Tint <span class="muted">(only applies when Background is "Image" — 0% shows the full photo, 100% fully hides it)</span> <input type="range" id="aiBgOpacitySlider" min="0" max="100" value="50" oninput="this.nextElementSibling.textContent = this.value + '%'"><span>50%</span></label>
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
        <p class="muted" style="margin:4px 0;">Style words in Headline/Subheading/Body/Points: <code>**accent**</code> <code>++highlight++</code> <code>*italic*</code> <code>__bold__</code> — nest markers to combine, e.g. <code>**__word__**</code> for bold + color</p>
      </div>
    </div>

    <div class="post-col-caption">
      <div class="post-col-heading">Post Content</div>
      <div class="editor-label">Caption</div>
      <?php include __DIR__ . '/_formatter_toolbar.php'; ?>
      <textarea id="caption" name="caption" class="caption-editor" form="newPostForm"></textarea>

      <label>Title <span class="muted">(optional)</span>
        <input type="text" name="title" id="titleField" form="newPostForm">
      </label>

      <div id="aiSlidesReviewPanel" style="display:none;">
        <div id="aiSlidesReview"></div>
        <button type="button" id="addSlideBtn" class="btn-tiny" style="display:none; margin-top:8px;">+ Add Slide</button>
      </div>
    </div>

    <div class="post-col-image">
      <div class="post-col-heading">Image &amp; Publish</div>
      <div id="aiPreviewPanel" style="display:none;">
        <button type="button" id="previewImageBtn" class="btn-secondary">Generate Image Preview</button>
        <p id="previewStatus" class="muted"></p>
        <div id="imagePreviewResult" style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;"></div>
      </div>

      <form method="post" id="newPostForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="ai_creative_json" id="aiCreativeJsonField">

        <label>Campaign ID <span class="muted">(optional — auto-generated if left blank)</span>
          <input type="text" name="campaign_id" placeholder="e.g. LAUNCH-01">
        </label>

        <?php if ($contentCollections): ?>
        <label>Collection <span class="muted">(optional — groups this with related posts, see Knowledge Hub)</span>
          <select name="collection_id">
            <option value="">— None —</option>
            <?php foreach ($contentCollections as $cc): ?>
              <option value="<?= (int) $cc['id'] ?>"><?= h($cc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>

        <label>Platform
          <select name="platform" id="platformSelect">
            <option value="linkedin">LinkedIn</option>
            <option value="facebook">Facebook</option>
            <option value="instagram">Instagram</option>
          </select>
        </label>

        <div id="linkedinAccountField">
          <label>LinkedIn Account
            <select name="linkedin_account_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($accounts as $acct): ?>
                <option value="<?= (int) $acct['id'] ?>"<?= (int) ($workspace['linkedin_account_id'] ?? 0) === (int) $acct['id'] ? ' selected' : '' ?>><?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div id="facebookAccountField" style="display:none;">
          <label>Facebook Page
            <select name="facebook_account_id" class="social-account-select">
              <option value="">— Unassigned —</option>
              <?php foreach ($facebookAccounts as $acct): ?>
                <option value="<?= (int) $acct['id'] ?>"><?= h($acct['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (empty($facebookAccounts)): ?>
            <p class="muted">No Facebook Pages connected — <a href="<?= h(app_path('pages/accounts.php')) ?>">connect one</a>.</p>
          <?php endif; ?>
        </div>

        <div id="instagramAccountField" style="display:none;">
          <label>Instagram Account
            <select name="instagram_account_id" class="social-account-select">
              <option value="">— Unassigned —</option>
              <?php foreach ($instagramAccounts as $acct): ?>
                <option value="<?= (int) $acct['id'] ?>"><?= h($acct['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (empty($instagramAccounts)): ?>
            <p class="muted">No Instagram accounts connected — <a href="<?= h(app_path('pages/accounts.php')) ?>">connect one</a>.</p>
          <?php endif; ?>
        </div>

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
  window.STOCK_IMAGE_SEARCH_URL = <?= json_encode(app_path('api/stock_image_search.php')) ?>;
  window.AI_IMAGE_GENERATE_URL = <?= json_encode(app_path('api/ai_image_generate.php')) ?>;
  (function () {
    var select = document.getElementById('formatSelect');
    var imageField = document.getElementById('imageUploadField');
    var carouselField = document.getElementById('carouselUploadField');
    var aiToggle = document.getElementById('aiGenerateToggle');
    var manualToggle = document.getElementById('manualCreativeToggle');
    var stockPhotoToggle = document.getElementById('stockPhotoToggle');
    if (!select || !imageField || !carouselField) return;
    var toggle = function () {
      var usingCreativeJson = (aiToggle && aiToggle.checked) || (manualToggle && manualToggle.checked) || (stockPhotoToggle && stockPhotoToggle.checked);
      imageField.style.display = (!usingCreativeJson && select.value === 'Single Image') ? 'flex' : 'none';
      carouselField.style.display = (!usingCreativeJson && select.value === 'Carousel') ? 'flex' : 'none';
    };
    window.newPostUpdateUploadFields = toggle;
    select.addEventListener('change', toggle);
    toggle();
  })();

  window.PLATFORM_FORMATS = <?= json_encode($platformFormats) ?>;
  (function () {
    var platformSelect = document.getElementById('platformSelect');
    var formatSelect = document.getElementById('formatSelect');
    var fieldsByPlatform = {
      linkedin: document.getElementById('linkedinAccountField'),
      facebook: document.getElementById('facebookAccountField'),
      instagram: document.getElementById('instagramAccountField'),
    };
    if (!platformSelect || !formatSelect) return;
    var allFormatOptions = Array.prototype.slice.call(formatSelect.options).map(function (opt) {
      return { value: opt.value, label: opt.textContent };
    });
    var applyPlatform = function () {
      var platform = platformSelect.value;
      Object.keys(fieldsByPlatform).forEach(function (key) {
        if (fieldsByPlatform[key]) {
          fieldsByPlatform[key].style.display = key === platform ? '' : 'none';
        }
      });
      var allowed = window.PLATFORM_FORMATS[platform] || [];
      var currentValue = formatSelect.value;
      formatSelect.innerHTML = '';
      allFormatOptions.forEach(function (opt) {
        if (allowed.indexOf(opt.value) === -1) return;
        var el = document.createElement('option');
        el.value = opt.value;
        el.textContent = opt.label;
        formatSelect.appendChild(el);
      });
      formatSelect.value = allowed.indexOf(currentValue) !== -1 ? currentValue : (allowed[0] || '');
      formatSelect.dispatchEvent(new Event('change'));
    };
    platformSelect.addEventListener('change', applyPlatform);
    applyPlatform();
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
