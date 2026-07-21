<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';

require_login();
$userId = current_user_id();

$batchId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM calendar_batches WHERE id = ? AND user_id = ?');
$stmt->execute([$batchId, $userId]);
$batch = $stmt->fetch();
if (!$batch) {
    flash('error', 'Calendar not found.');
    redirect('pages/content_calendar.php');
}

$stmt = db()->prepare(
    'SELECT p.*, cp.name AS pillar_name, cp.category AS pillar_category, per.name AS persona_name
     FROM posts p
     LEFT JOIN content_pillars cp ON cp.id = p.content_pillar_id
     LEFT JOIN personas per ON per.id = p.persona_id
     WHERE p.calendar_batch_id = ?
     ORDER BY p.scheduled_at ASC'
);
$stmt->execute([$batchId]);
$posts = $stmt->fetchAll();

$slideStmt = db()->prepare('SELECT filename, filepath FROM post_slides WHERE post_id = ? ORDER BY slide_order ASC');
foreach ($posts as &$post) {
    $post['creative'] = $post['creative_json'] ? json_decode($post['creative_json'], true) : null;
    $slideStmt->execute([$post['id']]);
    $post['slides'] = array_map(fn ($s) => ['filename' => $s['filename'], 'url' => slide_public_url($s['filepath'])], $slideStmt->fetchAll());
}
unset($post);

$accounts = fetch_user_accounts($userId);
$brandPalettes = fetch_brand_palettes($userId);

$pageTitle  = 'Calendar Batch';
$activePage = 'content_calendar';
$pageScripts = ['calendar_batch.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1><?= (int) $batch['period_days'] ?>-Day Calendar <span class="badge badge-format"><?= h(ucfirst(str_replace('_', ' ', $batch['status']))) ?></span></h1>
  <p class="subtitle"><?= count($posts) ?> post(s) &middot; <?= (int) $batch['posts_per_week'] ?> per week &middot; started <?= h($batch['start_date']) ?></p>
</div>

<?php if ($batch['status'] === 'content_review'): ?>
  <section class="card">
    <h2>Step 1 — Review Content</h2>
    <p class="muted">Edit any caption or slide text below, then approve the ones you're happy with. Posts still missing content can be generated (or retried) individually.</p>
    <div class="button-row" style="margin-bottom:16px;">
      <button type="button" id="generateMissingBtn" class="btn-secondary">Generate Missing Content</button>
      <button type="button" id="approveContentBtn" class="btn-primary">Approve Selected &amp; Continue</button>
    </div>
    <p id="contentStatus" class="muted"></p>
    <div id="contentCards">
      <?php foreach ($posts as $p): ?>
        <div class="card review-card" data-post-id="<?= (int) $p['id'] ?>">
          <div class="review-card-header">
            <strong><?= h(date('D, M j', strtotime($p['scheduled_at']))) ?></strong>
            <span class="badge"><?= h($p['format']) ?></span>
            <?php if ($p['pillar_name']): ?><span class="badge <?= $p['pillar_category'] === 'personal' ? 'badge-format' : 'badge-active' ?>"><?= h($p['pillar_name']) ?></span><?php endif; ?>
            <?php if ($p['persona_name']): ?><span class="muted"><?= h($p['persona_name']) ?></span><?php endif; ?>
            <?php if ($p['creative']): ?>
              <label class="checkbox-row skip-toggle"><input type="checkbox" class="approve-checkbox" checked> Include</label>
            <?php endif; ?>
          </div>
          <?php if (!$p['creative']): ?>
            <?php if ($p['error_message']): ?>
              <p class="badge badge-warning" style="display:block; white-space:normal; text-align:left;">Failed: <?= h($p['error_message']) ?></p>
            <?php else: ?>
              <p class="muted">No content yet.</p>
            <?php endif; ?>
            <button type="button" class="btn-tiny generate-one-btn">Generate</button>
          <?php else: ?>
            <label class="field-row">Title <input type="text" class="title-input" value="<?= h($p['title'] ?? '') ?>"></label>
            <label class="field-row">Caption <textarea class="caption-input" rows="4"><?= h($p['caption'] ?? '') ?></textarea></label>
            <?php if (!empty($p['creative']['slides'])): ?>
              <div class="slides-wrap">
                <?php foreach ($p['creative']['slides'] as $si => $slide): ?>
                  <fieldset class="slide-fieldset" data-slide-index="<?= (int) $si ?>">
                    <legend>Slide <?= (int) $si + 1 ?></legend>
                    <label class="field-row">Headline <input type="text" class="headline-input" value="<?= h($slide['headline'] ?? '') ?>"></label>
                    <label class="field-row">Body <textarea class="body-input" rows="2"><?= h($slide['body'] ?? '') ?></textarea></label>
                    <label class="field-row">Points (one per line) <textarea class="points-input" rows="2"><?= h(implode("\n", $slide['points'] ?? [])) ?></textarea></label>
                  </fieldset>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <label class="field-row">Color Palette
              <select class="template-select">
                <option value="">Auto</option>
                <option value="1"<?= ($p['creative']['template'] ?? '') === 1 ? ' selected' : '' ?>>Cream</option>
                <option value="2"<?= ($p['creative']['template'] ?? '') === 2 ? ' selected' : '' ?>>Dark Green</option>
                <option value="3"<?= ($p['creative']['template'] ?? '') === 3 ? ' selected' : '' ?>>Olive</option>
                <option value="4"<?= ($p['creative']['template'] ?? '') === 4 ? ' selected' : '' ?>>Medium Green</option>
                <?php foreach ($brandPalettes as $bp): ?>
                  <option value="custom:<?= (int) $bp['id'] ?>"<?= ($p['creative']['template'] ?? '') === "custom:{$bp['id']}" ? ' selected' : '' ?>><?= h($bp['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field-row">Design Template</label>
            <div class="template-picker-wrap" data-role="template-picker">
              <?= render_template_picker_html($p['creative']['layout'] ?? 'classic', '_' . (int) $p['id']) ?>
            </div>
            <label class="field-row">Background
              <select class="background-select">
                <option value="flat"<?= ($p['creative']['background'] ?? 'flat') === 'flat' ? ' selected' : '' ?>>Flat</option>
                <option value="gradient"<?= ($p['creative']['background'] ?? '') === 'gradient' ? ' selected' : '' ?>>Gradient</option>
                <option value="image"<?= ($p['creative']['background'] ?? '') === 'image' ? ' selected' : '' ?>>Image</option>
              </select>
            </label>
            <label class="field-row">Size
              <select class="size-select">
                <option value="square"<?= ($p['creative']['size'] ?? 'square') === 'square' ? ' selected' : '' ?>>Square (1:1)</option>
                <option value="portrait"<?= ($p['creative']['size'] ?? '') === 'portrait' ? ' selected' : '' ?>>Portrait (4:5, Document)</option>
              </select>
            </label>
            <button type="button" class="btn-tiny regenerate-btn">Regenerate</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

<?php elseif ($batch['status'] === 'image_review'): ?>
  <section class="card">
    <h2>Step 2 — Review Images</h2>
    <p class="muted">Generate images for approved posts, then approve the ones ready to schedule.</p>
    <div class="button-row" style="margin-bottom:16px;">
      <button type="button" id="generateImagesBtn" class="btn-secondary">Generate Images</button>
      <button type="button" id="approveImagesBtn" class="btn-primary">Approve Selected &amp; Continue</button>
    </div>
    <p id="imageStatus" class="muted"></p>
    <div id="imageCards" style="display:flex; flex-wrap:wrap; gap:16px;">
      <?php foreach ($posts as $p): if (!$p['content_approved_at']) continue; ?>
        <div class="card review-card" data-post-id="<?= (int) $p['id'] ?>" style="width:260px;">
          <div class="review-card-header">
            <strong><?= h(date('D, M j', strtotime($p['scheduled_at']))) ?></strong>
            <span class="badge"><?= h($p['format']) ?></span>
            <?php if ($p['slides']): ?>
              <label class="checkbox-row skip-toggle"><input type="checkbox" class="approve-checkbox" checked> Include</label>
            <?php endif; ?>
          </div>
          <p class="muted"><?= h(mb_strimwidth($p['title'] ?? '', 0, 40, '…')) ?></p>
          <?php if ($p['slides']): ?>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
              <?php foreach ($p['slides'] as $slide): ?>
                <img src="<?= h($slide['url']) ?>" style="width:70px; height:70px; object-fit:cover; border-radius:6px;">
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted">No image yet.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

<?php elseif ($batch['status'] === 'ready'): ?>
  <section class="card">
    <h2>Step 3 — Confirm &amp; Schedule</h2>
    <p class="muted">All content and images are approved. Pick which LinkedIn account these posts go to, then confirm to schedule the whole batch at its planned dates.</p>
    <form id="scheduleForm" class="stacked-form">
      <label>LinkedIn Account
        <select id="scheduleAccountId">
          <option value="">— Choose an account —</option>
          <?php foreach ($accounts as $acct): ?>
            <option value="<?= (int) $acct['id'] ?>"><?= h($acct['display_name']) ?> (<?= h($acct['account_type']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-primary" id="scheduleBtn">Confirm &amp; Schedule</button>
      <p id="scheduleStatus" class="muted"></p>
    </form>
  </section>

<?php else: ?>
  <section class="card">
    <p><strong><?= count($posts) ?></strong> post(s) scheduled. <a href="<?= h(app_path('pages/calendar.php')) ?>">View on Calendar</a>.</p>
  </section>
<?php endif; ?>

<script>
  window.CALENDAR_BATCH_ID = <?= (int) $batchId ?>;
  window.CALENDAR_BATCH_STATUS = <?= json_encode($batch['status']) ?>;
  window.CALENDAR_CSRF = <?= json_encode($token) ?>;
  window.CALENDAR_GENERATE_ONE_URL = <?= json_encode(app_path('api/calendar_generate_one.php')) ?>;
  window.CALENDAR_APPROVE_CONTENT_URL = <?= json_encode(app_path('api/calendar_approve_content.php')) ?>;
  window.CALENDAR_RENDER_ONE_URL = <?= json_encode(app_path('api/calendar_render_one.php')) ?>;
  window.CALENDAR_APPROVE_IMAGES_URL = <?= json_encode(app_path('api/calendar_approve_images.php')) ?>;
  window.CALENDAR_SCHEDULE_URL = <?= json_encode(app_path('api/calendar_schedule_batch.php')) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
