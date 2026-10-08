<?php
// A plain multipart file upload — not an MCP tool call — for a file too
// large to practically send through an AI chat client as base64 (the
// chat client's own attachment handling can cap out well below this
// app's own 50MB image_base64/video_base64 limit, even for a file under
// that limit, since base64 inflates it ~33% on top of whatever the
// client already had to read into memory). Upload here once, then hand
// the resulting link to any AI assistant to use as image_urls/video_url
// on create_post — see includes/mcp_tools.php mcp_store_uploaded_media().
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mcp_tools.php';

require_login();
require_module('mcp');
$userId = current_user_id();

$uploadedUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired — please try again.');
        redirect('pages/mcp_upload.php');
    }
    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Choose a file to upload.');
        redirect('pages/mcp_upload.php');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['file']['tmp_name']);
    $imageMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    $videoMimes = ['video/mp4' => 'mp4'];

    if (isset($imageMimes[$mime])) {
        $kind = 'image';
        $ext = $imageMimes[$mime];
        $maxBytes = 15 * 1024 * 1024;
    } elseif (isset($videoMimes[$mime])) {
        $kind = 'video';
        $ext = $videoMimes[$mime];
        $maxBytes = 200 * 1024 * 1024;
    } else {
        flash('error', 'Unsupported file type ("' . $mime . '"). PNG, JPEG, and MP4 are supported.');
        redirect('pages/mcp_upload.php');
    }

    if ($_FILES['file']['size'] > $maxBytes) {
        flash('error', 'File is too large (max ' . round($maxBytes / 1024 / 1024) . 'MB for a' . ($kind === 'image' ? 'n' : '') . ' ' . $kind . ').');
        redirect('pages/mcp_upload.php');
    }

    $bytes = file_get_contents($_FILES['file']['tmp_name']);
    $stored = mcp_store_uploaded_media($userId, $kind, $bytes, $ext);
    $uploadedUrl = $stored['media_url'];
}

$pageTitle  = 'Upload for AI';
$activePage = 'mcp_upload';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Upload for AI</h1>
  <p class="subtitle">For a file too large to send directly through an AI chat (Claude, ChatGPT). Upload it here once, then paste the link it gives you back into the chat and tell the assistant to use it as the image/video URL on <code>create_post</code>.</p>
</div>

<section class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Image (PNG/JPEG, max 15MB) or video (MP4, max 200MB)
      <input type="file" name="file" accept="image/png,image/jpeg,video/mp4" required>
    </label>
    <button type="submit" class="btn-primary" style="margin-top:12px;">Upload &amp; Get Link</button>
  </form>

  <?php if ($uploadedUrl): ?>
    <div class="card" style="margin-top:20px; background:var(--bg-subtle,#f6f6f6);">
      <p><strong>Uploaded.</strong> Copy this link and give it to your AI assistant:</p>
      <p><input type="text" readonly value="<?= h($uploadedUrl) ?>" style="width:100%;" onclick="this.select();"></p>
      <p class="muted">Tell it: "Use <?= h($uploadedUrl) ?> as the image_urls/video_url for create_post."</p>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
