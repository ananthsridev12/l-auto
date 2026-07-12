<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csv_parser.php';
require_once __DIR__ . '/../includes/zip_import.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/import.php');
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    flash('error', 'Your session expired, please start the import again.');
    redirect('pages/import.php');
}

$rows    = json_decode($_POST['rows_json'] ?? '[]', true) ?: [];
$mapping = json_decode($_POST['mapping_json'] ?? '{}', true) ?: [];
$csvFilename = $_POST['csv_filename'] ?? null;

if (!$rows) {
    flash('error', 'No rows to import — please upload a CSV first.');
    redirect('pages/import.php');
}

$enabledFormats = get_enabled_formats($userId);

// Verify every mapped account actually belongs to this user (defense
// against a tampered mapping_json field naming another user's account).
$stmt = db()->prepare('SELECT id FROM linkedin_accounts WHERE user_id = ? AND status = "active"');
$stmt->execute([$userId]);
$ownedAccountIds = array_column($stmt->fetchAll(), 'id');
$ownedAccountIds = array_map('intval', $ownedAccountIds);

$batchDir = null;
$slidesByCampaign = [];
$zipFilename = null;
if (!empty($_FILES['zip']['tmp_name']) && $_FILES['zip']['error'] === UPLOAD_ERR_OK) {
    $zipFilename = $_FILES['zip']['name'];
    $batchDir = UPLOAD_DIR . '/' . $userId . '/_imports/' . bin2hex(random_bytes(6));
    try {
        $slidesByCampaign = zip_extract_campaign_slides($_FILES['zip']['tmp_name'], $batchDir);
    } catch (Throwable $e) {
        flash('error', 'Media ZIP could not be processed: ' . $e->getMessage());
        redirect('pages/import.php');
    }
}

$pdo = db();
$pdo->beginTransaction();

$batchStmt = $pdo->prepare('INSERT INTO import_batches (user_id, csv_filename, zip_filename, row_count) VALUES (?, ?, ?, ?)');
$batchStmt->execute([$userId, $csvFilename, $zipFilename, count($rows)]);
$batchId = (int) $pdo->lastInsertId();

$imported = 0;
$skipped = 0;
$unmatchedAccount = 0;

// Re-importing the same CSV must never clobber a post that's already
// gone out — IF(status='posted', <old>, VALUES(<new>)) leaves posted
// rows completely untouched while still updating drafts/scheduled ones.
$upsertStmt = $pdo->prepare(
    'INSERT INTO posts (user_id, workspace_id, linkedin_account_id, import_batch_id, campaign_id, title, format, caption, source_page_label, status, scheduled_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       linkedin_account_id = IF(status = "posted", linkedin_account_id, VALUES(linkedin_account_id)),
       import_batch_id     = IF(status = "posted", import_batch_id, VALUES(import_batch_id)),
       title               = IF(status = "posted", title, VALUES(title)),
       format              = IF(status = "posted", format, VALUES(format)),
       caption             = IF(status = "posted", caption, VALUES(caption)),
       source_page_label   = IF(status = "posted", source_page_label, VALUES(source_page_label)),
       status              = IF(status = "posted", status, VALUES(status)),
       scheduled_at        = IF(status = "posted", scheduled_at, VALUES(scheduled_at)),
       workspace_id        = IF(status = "posted", workspace_id, VALUES(workspace_id))'
);
$findPostStmt = $pdo->prepare('SELECT id, status FROM posts WHERE user_id = ? AND campaign_id = ?');
$deleteSlidesStmt = $pdo->prepare('DELETE FROM post_slides WHERE post_id = ?');
$insertSlideStmt = $pdo->prepare('INSERT INTO post_slides (post_id, slide_order, filename, filepath) VALUES (?, ?, ?, ?)');

$finalDestRoot = UPLOAD_DIR . '/' . $userId;
$alreadyPosted = 0;

foreach ($rows as $row) {
    if (!empty($row['skip'])) {
        $skipped++;
        continue;
    }

    $campaignId = $row['campaign_id'];
    $pageLabel  = $row['page_label'] ?? '';
    $accountId  = $mapping[$pageLabel] ?? null;
    if ($accountId !== null && !in_array((int) $accountId, $ownedAccountIds, true)) {
        $accountId = null;
    }
    if ($accountId === null) {
        $unmatchedAccount++;
    }

    // A row auto-schedules only if it has a matched account, a *future*
    // calendar date, AND its format is one the user has enabled (Poll
    // is off by default — see includes/helpers.php). A past date is
    // never auto-scheduled — the cron sweep treats any "scheduled" row
    // with scheduled_at <= NOW() as due immediately, so auto-scheduling
    // a past date would post it the moment cron next runs, with no
    // chance to review it first. Anything that doesn't qualify lands as
    // a draft instead.
    if ($accountId !== null && !empty($row['date']) && $row['date'] >= date('Y-m-d') && in_array($row['format'], $enabledFormats, true)) {
        $status = 'scheduled';
        $scheduledAt = $row['date'] . ' 09:00:00';
    } else {
        $status = 'draft';
        $scheduledAt = null;
    }

    $upsertStmt->execute([
        $userId, current_workspace_id(), $accountId, $batchId, $campaignId,
        $row['title'] ?? '', $row['format'], $row['caption'] ?? '', $pageLabel,
        $status, $scheduledAt,
    ]);

    $findPostStmt->execute([$userId, $campaignId]);
    $existing = $findPostStmt->fetch();
    $postId = (int) $existing['id'];

    if ($existing['status'] === 'posted') {
        $alreadyPosted++;
        continue; // slides are left untouched too — this post already went out.
    }

    if (isset($slidesByCampaign[$campaignId])) {
        $deleteSlidesStmt->execute([$postId]);
        $destDir = $finalDestRoot . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        foreach (array_values($slidesByCampaign[$campaignId]) as $order => $slide) {
            $finalPath = $destDir . '/' . $slide['filename'];
            rename($slide['filepath'], $finalPath);
            $insertSlideStmt->execute([$postId, $order + 1, $slide['filename'], $finalPath]);
        }
    }

    $imported++;
}

$updateBatchStmt = $pdo->prepare('UPDATE import_batches SET imported_count = ?, skipped_count = ?, unmatched_account_count = ?, already_posted_count = ? WHERE id = ?');
$updateBatchStmt->execute([$imported, $skipped, $unmatchedAccount, $alreadyPosted, $batchId]);

$pdo->commit();

if ($batchDir && is_dir($batchDir)) {
    @rmdir_recursive($batchDir);
}

$pageTitle  = 'Import Complete';
$activePage = 'import';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Import Complete</h1>
</div>
<section class="card">
  <p><strong><?= (int) $imported ?></strong> post(s) imported/updated.</p>
  <p><strong><?= (int) $skipped ?></strong> row(s) skipped (missing caption, campaign ID, or invalid format).</p>
  <?php if ($unmatchedAccount > 0): ?>
    <p class="badge badge-warning"><?= (int) $unmatchedAccount ?> post(s) need a LinkedIn account assigned before they can be scheduled or posted.</p>
  <?php endif; ?>
  <?php if ($alreadyPosted > 0): ?>
    <p class="muted"><?= (int) $alreadyPosted ?> row(s) matched a campaign that was already posted — left untouched.</p>
  <?php endif; ?>
  <a class="btn-primary" href="<?= h(app_path('pages/calendar.php')) ?>" style="text-decoration:none;display:inline-block;margin-top:16px;">View Calendar</a>
</section>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
<?php
function rmdir_recursive(string $dir): void
{
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rmdir_recursive($path) : @unlink($path);
    }
    @rmdir($dir);
}
