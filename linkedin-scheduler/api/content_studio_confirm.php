<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/content_studio.php');
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    flash('error', 'Your session expired, please start again.');
    redirect('pages/content_studio.php');
}

$rows    = json_decode($_POST['rows_json'] ?? '[]', true) ?: [];
$mapping = json_decode($_POST['mapping_json'] ?? '{}', true) ?: [];
$csvFilename = $_POST['csv_filename'] ?? null;

if (!$rows) {
    flash('error', 'No rows to render — please upload a CSV first.');
    redirect('pages/content_studio.php');
}

$enabledFormats = get_enabled_formats($userId);

$stmt = db()->prepare('SELECT id FROM linkedin_accounts WHERE user_id = ? AND status = "active"');
$stmt->execute([$userId]);
$ownedAccountIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

$user = current_user();
$footerName = trim($user['name'] ?? '') ?: explode('@', $user['email'] ?? 'Your Name')[0];
$photoPath = resolve_footer_image($userId, 'personal');

$pdo = db();
$pdo->beginTransaction();

$batchStmt = $pdo->prepare('INSERT INTO import_batches (user_id, csv_filename, row_count) VALUES (?, ?, ?)');
$batchStmt->execute([$userId, $csvFilename, count($rows)]);
$batchId = (int) $pdo->lastInsertId();

$upsertStmt = $pdo->prepare(
    'INSERT INTO posts (user_id, linkedin_account_id, import_batch_id, campaign_id, title, format, caption, source_page_label, status, scheduled_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       linkedin_account_id = IF(status = "posted", linkedin_account_id, VALUES(linkedin_account_id)),
       import_batch_id     = IF(status = "posted", import_batch_id, VALUES(import_batch_id)),
       title               = IF(status = "posted", title, VALUES(title)),
       format              = IF(status = "posted", format, VALUES(format)),
       caption             = IF(status = "posted", caption, VALUES(caption)),
       source_page_label   = IF(status = "posted", source_page_label, VALUES(source_page_label)),
       status              = IF(status = "posted", status, VALUES(status)),
       scheduled_at        = IF(status = "posted", scheduled_at, VALUES(scheduled_at))'
);
$findPostStmt = $pdo->prepare('SELECT id, status FROM posts WHERE user_id = ? AND campaign_id = ?');
$deleteSlidesStmt = $pdo->prepare('DELETE FROM post_slides WHERE post_id = ?');
$insertSlideStmt = $pdo->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');

$rendered = 0;
$skipped = 0;
$unmatchedAccount = 0;
$alreadyPosted = 0;
$renderErrors = [];

foreach ($rows as $row) {
    if (!empty($row['skip']) || empty($row['creative']['slides'])) {
        $skipped++;
        continue;
    }

    $campaignId = trim($row['campaign_id'] ?? '');
    if ($campaignId === '') {
        $skipped++;
        continue;
    }

    $pageLabel = $row['page_label'] ?? '';
    $accountId = $mapping[$pageLabel] ?? null;
    if ($accountId !== null && !in_array((int) $accountId, $ownedAccountIds, true)) {
        $accountId = null;
    }
    if ($accountId === null) {
        $unmatchedAccount++;
    }

    $format = ($row['format'] ?? '') === 'Single Image' ? 'Single Image' : 'Carousel';

    if ($accountId !== null && !empty($row['date']) && $row['date'] >= date('Y-m-d') && in_array($format, $enabledFormats, true)) {
        $status = 'scheduled';
        $scheduledAt = $row['date'] . ' 09:00:00';
    } else {
        $status = 'draft';
        $scheduledAt = null;
    }

    $creative = $row['creative'];
    $title    = trim($creative['title'] ?? '');
    $caption  = trim($creative['caption'] ?? '');

    $upsertStmt->execute([
        $userId, $accountId, $batchId, $campaignId,
        $title, $format, $caption, $pageLabel,
        $status, $scheduledAt,
    ]);

    $findPostStmt->execute([$userId, $campaignId]);
    $existing = $findPostStmt->fetch();
    $postId = (int) $existing['id'];

    if ($existing['status'] === 'posted') {
        $alreadyPosted++;
        continue; // rendering skipped too — this post already went out.
    }

    $safeCampaign = preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
    $outDir = UPLOAD_DIR . '/' . $userId . '/' . $safeCampaign;

    try {
        $slides = render_creative_to_slides($creative, $outDir, $footerName, $photoPath, $userId);
    } catch (Throwable $e) {
        $renderErrors[] = "{$campaignId}: " . $e->getMessage();
        continue;
    }

    $deleteSlidesStmt->execute([$postId]);
    foreach ($slides as $order => $slide) {
        $insertSlideStmt->execute([$postId, $order + 1, $slide['filename'], $slide['filepath']]);
    }

    $rendered++;
}

$updateBatchStmt = $pdo->prepare('UPDATE import_batches SET imported_count = ?, skipped_count = ?, unmatched_account_count = ?, already_posted_count = ? WHERE id = ?');
$updateBatchStmt->execute([$rendered, $skipped, $unmatchedAccount, $alreadyPosted, $batchId]);

$pdo->commit();

$pageTitle  = 'Content Studio — Done';
$activePage = 'content_studio';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Content Studio — Rendered</h1></div>
<section class="card">
  <p><strong><?= (int) $rendered ?></strong> post(s) rendered and saved.</p>
  <p><strong><?= (int) $skipped ?></strong> row(s) skipped.</p>
  <?php if ($unmatchedAccount > 0): ?>
    <p class="badge badge-warning"><?= (int) $unmatchedAccount ?> post(s) need a LinkedIn account assigned before they can be scheduled or posted.</p>
  <?php endif; ?>
  <?php if ($alreadyPosted > 0): ?>
    <p class="muted"><?= (int) $alreadyPosted ?> row(s) matched a campaign that was already posted — left untouched.</p>
  <?php endif; ?>
  <?php if (!empty($renderErrors)): ?>
    <p class="badge badge-warning">Some rows failed to render:</p>
    <ul>
      <?php foreach ($renderErrors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <a class="btn-primary" href="<?= h(app_path('pages/calendar.php')) ?>" style="text-decoration:none;display:inline-block;margin-top:16px;">View Calendar</a>
</section>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
