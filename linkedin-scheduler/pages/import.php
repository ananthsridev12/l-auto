<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$pageTitle   = 'Import Content';
$activePage  = 'import';
$pageScripts = ['import_wizard.js'];
$csrf = csrf_token();
$previewUrl = app_path('api/import_csv_preview.php');
$confirmUrl = app_path('api/import_confirm.php');
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Import Content</h1>
  <p class="subtitle">Upload your content calendar CSV, map each "LinkedIn Page" label to a connected account, then attach the bulk media ZIP (one folder per Campaign ID, e.g. <code>AS-LO-48/slide_01.png</code>).</p>
</div>

<section class="card" id="step1">
  <h2>Step 1 — Upload CSV</h2>
  <p class="muted">New to this? <a href="<?= h(app_path('assets/templates/import_template.csv')) ?>" download>Download a CSV template</a> with the expected columns.</p>
  <input type="file" id="csvFile" accept=".csv">
  <button type="button" id="csvUploadBtn" class="btn-primary">Upload &amp; Preview</button>
  <p id="csvStatus" class="muted"></p>
</section>

<section class="card" id="step2" style="display:none;">
  <h2>Step 2 — Map LinkedIn Pages to Accounts</h2>
  <div id="mappingRows"></div>
</section>

<section class="card" id="step3" style="display:none;">
  <h2>Step 3 — Preview &amp; Attach Media</h2>
  <div id="previewSummary"></div>
  <table class="preview-table">
    <thead><tr><th>Campaign ID</th><th>Date</th><th>Format</th><th>Title</th><th>Page</th><th>Status</th></tr></thead>
    <tbody id="previewRows"></tbody>
  </table>

  <form id="confirmForm" method="post" action="<?= h($confirmUrl) ?>" enctype="multipart/form-data" style="margin-top:20px;">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="rows_json" id="rowsJsonField">
    <input type="hidden" name="mapping_json" id="mappingJsonField">
    <input type="hidden" name="csv_filename" id="csvFilenameField">
    <label>Bulk media ZIP (optional)
      <input type="file" name="zip" accept=".zip">
    </label>
    <button type="submit" class="btn-primary">Confirm Import</button>
  </form>
</section>

<script>
  window.IMPORT_PREVIEW_URL = <?= json_encode($previewUrl) ?>;
  window.IMPORT_CSRF = <?= json_encode($csrf) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
