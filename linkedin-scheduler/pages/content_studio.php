<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';

require_login();

$pageTitle   = 'Content Studio';
$activePage  = 'content_studio';
$pageScripts = ['content_studio.js'];
$csrf = csrf_token();
$previewUrl = app_path('api/content_studio_preview.php');
$confirmUrl = app_path('api/content_studio_confirm.php');
$brandPalettes = fetch_brand_palettes(current_user_id());
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Content Studio</h1>
  <p class="subtitle">Upload your content calendar CSV. Rows with a "Creative Content" column already written are parsed as-is; rows left blank (but with a Topic / Title) are written by AI. Review and edit before the images are rendered and saved as drafts.</p>
</div>

<section class="card" id="step1">
  <h2>Step 1 — Upload CSV</h2>
  <input type="file" id="csvFile" accept=".csv">
  <button type="button" id="csvUploadBtn" class="btn-primary">Upload &amp; Generate</button>
  <p id="csvStatus" class="muted"></p>
</section>

<section class="card" id="step2" style="display:none;">
  <h2>Step 2 — Map LinkedIn Pages to Accounts</h2>
  <div id="mappingRows"></div>
</section>

<section class="card" id="step3" style="display:none;">
  <h2>Step 3 — Review &amp; Edit</h2>
  <div id="previewSummary" class="muted"></div>
  <div id="reviewCards"></div>

  <form id="confirmForm" method="post" action="<?= h($confirmUrl) ?>" style="margin-top:20px;">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="rows_json" id="rowsJsonField">
    <input type="hidden" name="mapping_json" id="mappingJsonField">
    <input type="hidden" name="csv_filename" id="csvFilenameField">
    <button type="submit" class="btn-primary">Render Images &amp; Save Drafts</button>
  </form>
</section>

<script>
  window.CONTENT_STUDIO_PREVIEW_URL = <?= json_encode($previewUrl) ?>;
  window.CONTENT_STUDIO_CSRF = <?= json_encode($csrf) ?>;
  window.BRAND_PALETTES = <?= json_encode(array_map(fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $brandPalettes)) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
