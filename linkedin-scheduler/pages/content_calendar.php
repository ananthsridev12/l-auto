<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/calendar_planner.php';

require_login();
$userId = current_user_id();

$workspaceId = current_workspace_id();
$pillars = fetch_content_pillars($userId, $workspaceId);
$enabledFormats = get_enabled_formats($userId);
$defaultPillarWeights = default_pillar_weights($pillars);
$defaultFormatWeights = default_format_weights($enabledFormats);

$stmt = db()->prepare('SELECT id, period_days, posts_per_week, status, created_at FROM calendar_batches WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) ORDER BY created_at DESC');
$stmt->execute([$userId, $workspaceId]);
$batches = $stmt->fetchAll();

$pageTitle  = 'Content Calendar';
$activePage = 'content_calendar';
$pageScripts = ['content_calendar.js'];
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Content Calendar</h1>
  <p class="subtitle">Plan 1-4 weeks of posts at once from your Knowledge Base. Set how much of each content pillar and format you want, generate, review the content, then the images, then schedule everything in bulk.</p>
</div>

<?php if (!$pillars): ?>
  <section class="card">
    <p class="muted">You need at least one content pillar first — add one in <a href="<?= h(app_path('pages/settings.php')) ?>">Settings</a>, or load the starter set from there.</p>
  </section>
<?php else: ?>
<section class="card">
  <h2>Generate New Calendar</h2>
  <form id="calendarGenerateForm" class="stacked-form">
    <label>Period
      <select id="periodDays">
        <option value="7">1 week</option>
        <option value="14">2 weeks</option>
        <option value="30">30 days</option>
      </select>
    </label>
    <label>Posts per week
      <input type="number" id="postsPerWeek" value="5" min="1" max="7">
    </label>

    <div class="editor-label" style="margin-top:16px;">Content Pillar Mix (%)</div>
    <p class="muted" id="rollupDisplay">Company: — % / Personal: — %</p>
    <div id="pillarWeightRows">
      <?php foreach ($pillars as $p): ?>
        <div class="mapping-row">
          <span><?= h($p['name']) ?> <span class="badge <?= $p['category'] === 'personal' ? 'badge-format' : 'badge-active' ?>"><?= $p['category'] === 'personal' ? 'Personal' : 'Company' ?></span></span>
          <input type="number" class="pillar-weight-input" data-category="<?= h($p['category']) ?>" data-pillar-id="<?= (int) $p['id'] ?>" value="<?= (int) ($defaultPillarWeights[$p['id']] ?? 0) ?>" min="0" max="100" style="width:80px;">
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted" id="pillarSumDisplay"></p>

    <div class="editor-label" style="margin-top:16px;">Format Mix (%)</div>
    <div id="formatWeightRows">
      <?php foreach ($enabledFormats as $fmt): ?>
        <div class="mapping-row">
          <span><?= h($fmt) ?></span>
          <input type="number" class="format-weight-input" data-format="<?= h($fmt) ?>" value="<?= (int) ($defaultFormatWeights[$fmt] ?? 0) ?>" min="0" max="100" style="width:80px;">
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted" id="formatSumDisplay"></p>

    <button type="submit" class="btn-primary" id="generateBtn" style="margin-top:16px;">Generate Calendar</button>
    <p id="generateStatus" class="muted"></p>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <h2>Your Calendars</h2>
  <?php if ($batches): ?>
    <table class="preview-table">
      <thead><tr><th>Period</th><th>Posts/Week</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($batches as $b): ?>
          <tr>
            <td><?= (int) $b['period_days'] ?> days</td>
            <td><?= (int) $b['posts_per_week'] ?></td>
            <td><span class="badge badge-format"><?= h(ucfirst(str_replace('_', ' ', $b['status']))) ?></span></td>
            <td><?= h($b['created_at']) ?></td>
            <td><a href="<?= h(app_path('pages/calendar_batch.php?id=' . $b['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">No calendars generated yet.</p>
  <?php endif; ?>
</section>

<script>
  window.CALENDAR_PLAN_URL = <?= json_encode(app_path('api/calendar_plan.php')) ?>;
  window.CALENDAR_GENERATE_ONE_URL = <?= json_encode(app_path('api/calendar_generate_one.php')) ?>;
  window.CALENDAR_BATCH_BASE_URL = <?= json_encode(app_path('pages/calendar_batch.php')) ?>;
  window.CALENDAR_CSRF = <?= json_encode($token) ?>;
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
