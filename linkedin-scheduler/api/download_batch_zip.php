<?php
// Bundles every rendered slide from one Content Studio (or plain CSV)
// import batch into a single ZIP download — the only alternative
// before this was saving each slide image individually, or pulling
// the files off the server directly (cPanel File Manager / SSH).
// Organizes the ZIP by campaign, so a Carousel's slides stay grouped
// under the same folder as its Single Image siblings.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

$batchId = (int) ($_GET['batch_id'] ?? 0);
if ($batchId <= 0) {
    http_response_code(400);
    exit('Invalid batch.');
}

$batchStmt = db()->prepare('SELECT id, csv_filename FROM import_batches WHERE id = ? AND user_id = ?');
$batchStmt->execute([$batchId, $userId]);
$batch = $batchStmt->fetch();
if (!$batch) {
    http_response_code(404);
    exit('Import batch not found.');
}

$stmt = db()->prepare(
    'SELECT p.campaign_id, ps.filename, ps.filepath
     FROM post_slides ps
     JOIN posts p ON p.id = ps.post_id
     WHERE p.import_batch_id = ? AND p.user_id = ?
     ORDER BY p.campaign_id, ps.slide_order'
);
$stmt->execute([$batchId, $userId]);
$slides = $stmt->fetchAll();

if (!$slides) {
    http_response_code(404);
    exit('No rendered images found for this import batch.');
}

$tmpPath = tempnam(sys_get_temp_dir(), 'batchzip');
$zip = new ZipArchive();
if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not build the ZIP file.');
}
$added = 0;
foreach ($slides as $slide) {
    // is_file() guards against a slide row whose file was since moved/
    // deleted (e.g. a post re-rendered under a new name) — skip rather
    // than fail the whole download over one missing file.
    if (!is_file($slide['filepath'])) {
        continue;
    }
    $safeCampaign = preg_replace('/[^A-Za-z0-9_-]/', '_', $slide['campaign_id']);
    $zip->addFile($slide['filepath'], $safeCampaign . '/' . $slide['filename']);
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($tmpPath);
    http_response_code(404);
    exit('No rendered image files could be found on disk for this batch.');
}

$downloadName = 'content-studio-batch-' . $batchId . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpPath));
readfile($tmpPath);
@unlink($tmpPath);
