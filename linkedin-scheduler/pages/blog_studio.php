<?php
// Blog Studio — Phase F. List view (no ?id=) shows this workspace's
// blog posts by status plus a "New Blog Post" topic form; the editor
// view (?id=) edits one post's fields and handles Save/Schedule/Publish
// Now/Delete. Generation reuses the same AI-provider dispatch as
// LinkedIn creatives (includes/blog_generate.php) and Memory & Context
// (content_type='blog', so a blog never dedupes against LinkedIn
// captions or vice versa).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/creative_builder.php';
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/embeddings.php';
require_once __DIR__ . '/../includes/content_memory.php';
require_once __DIR__ . '/../includes/blog_posts.php';
require_once __DIR__ . '/../includes/blog_generate.php';
require_once __DIR__ . '/../includes/wordpress_api.php';

require_login();
$userId = current_user_id();
$workspaceId = current_workspace_id();
$workspace = current_workspace();
$aiConfig = resolve_ai_config($userId);
$postId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/blog_studio.php' . ($postId ? '?id=' . $postId : ''));
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $topicTitle = trim($_POST['topic'] ?? '');
        $length = strtolower(trim($_POST['length'] ?? BLOG_LENGTH_DEFAULT));
        if (!isset(BLOG_LENGTH_PRESETS[$length])) {
            $length = BLOG_LENGTH_DEFAULT;
        }
        if ($topicTitle === '') {
            flash('error', 'Enter a topic to write about.');
            redirect('pages/blog_studio.php');
        }
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/blog_studio.php');
        }
        try {
            $relatedMemory = content_memory_related_for_topic($workspaceId, $topicTitle, $aiConfig, 'blog');
            $existingPosts = array_map(
                fn ($p) => ['title' => $p['title'], 'slug' => $p['slug']],
                fetch_blog_posts($userId, $workspaceId, 'published')
            );
            $creative = generate_blog_post_via_ai(['title' => $topicTitle, 'length' => $length], $aiConfig, $workspace, $relatedMemory, null, $existingPosts);
            $newPostId = create_blog_post($userId, $workspaceId, $creative, null);
            save_blog_content_memory($workspaceId, $newPostId, $creative['title'] . ' ' . $creative['meta_description'], $creative['title'], $aiConfig);
            flash('success', 'Blog post drafted — review and edit before publishing.');
            redirect('pages/blog_studio.php?id=' . $newPostId);
        } catch (Throwable $e) {
            flash('error', 'Generation failed: ' . $e->getMessage());
            redirect('pages/blog_studio.php');
        }
    }

    // Every action below operates on one existing post — verify
    // ownership + workspace once up front.
    $existing = $postId ? fetch_blog_post($userId, $postId) : null;
    if (!$existing || (int) $existing['workspace_id'] !== $workspaceId) {
        flash('error', 'Blog post not found.');
        redirect('pages/blog_studio.php');
    }

    if ($action === 'save') {
        update_blog_post($userId, $postId, [
            'title'            => trim($_POST['title'] ?? $existing['title']),
            'slug'             => blog_slugify(trim($_POST['slug'] ?? $existing['slug'])),
            'meta_description' => trim($_POST['meta_description'] ?? '') ?: null,
            'keywords'         => trim($_POST['keywords'] ?? '') ?: null,
            'content_html'     => $_POST['content_html'] ?? $existing['content_html'],
        ]);
        flash('success', 'Saved.');
        redirect('pages/blog_studio.php?id=' . $postId);
    }

    if ($action === 'schedule') {
        $date = trim($_POST['scheduled_date'] ?? '');
        $time = trim($_POST['scheduled_time'] ?? '09:00');
        if ($date === '') {
            flash('error', 'Pick a date to schedule for.');
            redirect('pages/blog_studio.php?id=' . $postId);
        }
        set_blog_post_schedule($userId, $postId, $date . ' ' . $time . ':00');
        flash('success', 'Scheduled for ' . $date . ' ' . $time . '.');
        redirect('pages/blog_studio.php?id=' . $postId);
    }

    if ($action === 'unschedule') {
        db()->prepare('UPDATE blog_posts SET status = "draft", scheduled_at = NULL WHERE id = ? AND user_id = ?')->execute([$postId, $userId]);
        flash('success', 'Back to draft.');
        redirect('pages/blog_studio.php?id=' . $postId);
    }

    if ($action === 'publish_now') {
        if (!wordpress_configured($workspace)) {
            flash('error', 'Connect WordPress for this workspace in Settings first.');
            redirect('pages/blog_studio.php?id=' . $postId);
        }
        $result = wordpress_publish_post($workspace, $existing);
        if ($result['success']) {
            mark_blog_post_published($postId, $result['external_post_id'], $result['external_url'] ?? null);
            flash('success', 'Published to WordPress.');
        } else {
            mark_blog_post_failed($postId, $result['error']);
            flash('error', $result['error']);
        }
        redirect('pages/blog_studio.php?id=' . $postId);
    }

    if ($action === 'delete') {
        delete_blog_post($userId, $postId);
        flash('success', 'Blog post deleted.');
        redirect('pages/blog_studio.php');
    }
}

$pageTitle  = 'Blog Studio';
$activePage = 'blog_studio';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';

if ($postId) {
    $post = fetch_blog_post($userId, $postId);
    if (!$post || (int) $post['workspace_id'] !== $workspaceId) {
        echo '<div class="page-header"><h1>Blog Studio</h1></div><section class="card"><p class="muted">Blog post not found.</p></section>';
        require __DIR__ . '/../includes/layout_bottom.php';
        exit;
    }
    ?>
    <div class="page-header">
      <h1><?= h($post['title']) ?></h1>
      <span class="badge <?= $post['status'] === 'published' ? 'badge-active' : ($post['status'] === 'failed' ? 'badge-warning' : ($post['status'] === 'scheduled' ? 'badge-scheduled' : 'badge-format')) ?>"><?= h(ucfirst($post['status'])) ?></span>
    </div>
    <a href="<?= h(app_path('pages/blog_studio.php')) ?>">&larr; Back to Blog Studio</a>

    <?php if ($post['status'] === 'failed' && $post['error_message']): ?>
      <section class="card"><p class="badge badge-warning">Last publish attempt failed: <?= h($post['error_message']) ?></p></section>
    <?php endif; ?>

    <?php if ($post['status'] === 'published'): ?>
      <section class="card">
        <p>Published <?= h(date('j M Y, g:i a', strtotime($post['published_at']))) ?><?php if ($post['external_url']): ?> — <a href="<?= h($post['external_url']) ?>" target="_blank" rel="noopener noreferrer">View on WordPress</a><?php endif; ?></p>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Edit</h2>
      <form method="post" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save">
        <label>Title
          <input type="text" name="title" value="<?= h($post['title']) ?>" <?= $post['status'] === 'published' ? 'disabled' : 'required' ?>>
        </label>
        <label>Slug
          <input type="text" name="slug" value="<?= h($post['slug']) ?>" <?= $post['status'] === 'published' ? 'disabled' : '' ?>>
        </label>
        <label>Meta description <span class="muted">(120-155 characters, for SEO)</span>
          <input type="text" name="meta_description" value="<?= h($post['meta_description'] ?? '') ?>" <?= $post['status'] === 'published' ? 'disabled' : '' ?>>
        </label>
        <label>Keywords
          <input type="text" name="keywords" value="<?= h($post['keywords'] ?? '') ?>" <?= $post['status'] === 'published' ? 'disabled' : '' ?>>
        </label>
        <label>Body (HTML)
          <textarea name="content_html" rows="20" style="font-family:monospace;" <?= $post['status'] === 'published' ? 'disabled' : '' ?>><?= h($post['content_html']) ?></textarea>
        </label>
        <?php if ($post['status'] !== 'published'): ?>
          <button type="submit" class="btn-primary">Save</button>
        <?php endif; ?>
      </form>
    </section>

    <?php if ($post['status'] !== 'published'): ?>
    <section class="card">
      <h2>Publish</h2>
      <?php if (!wordpress_configured($workspace)): ?>
        <p class="muted">Connect WordPress for this workspace in <a href="<?= h(app_path('pages/settings.php')) ?>">Settings</a> to publish or schedule.</p>
      <?php else: ?>
        <form method="post" style="display:inline-block; margin-right:12px;">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="publish_now">
          <button type="submit" class="btn-primary">Publish Now</button>
        </form>
        <?php if ($post['status'] === 'scheduled'): ?>
          <form method="post" style="display:inline-block;">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="unschedule">
            <button type="submit" class="btn-secondary">Cancel schedule (<?= h(date('j M Y, g:i a', strtotime($post['scheduled_at']))) ?>)</button>
          </form>
        <?php else: ?>
          <form method="post" class="stacked-form" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="schedule">
            <label>Date <input type="date" name="scheduled_date" required></label>
            <label>Time <input type="time" name="scheduled_time" value="09:00"></label>
            <button type="submit" class="btn-secondary">Schedule</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <form method="post" onsubmit="return confirm('Delete this blog post permanently?');">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn-tiny btn-danger">Delete</button>
      </form>
    </section>
    <?php
} else {
    $drafts = fetch_blog_posts($userId, $workspaceId, 'draft');
    $scheduled = fetch_blog_posts($userId, $workspaceId, 'scheduled');
    $published = fetch_blog_posts($userId, $workspaceId, 'published');
    $failed = fetch_blog_posts($userId, $workspaceId, 'failed');
    ?>
    <div class="page-header"><h1>Blog Studio</h1><span class="badge badge-campaign"><?= h($workspace['name']) ?></span></div>

    <section class="card">
      <h2>New Blog Post</h2>
      <p class="muted">Writes an original, SEO-friendly post in this workspace's voice — grounded in its Knowledge Hub documents and avoiding repeats of its own past posts (Memory &amp; Context). Review and edit before publishing.</p>
      <form method="post" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="generate">
        <label>Topic
          <input type="text" name="topic" placeholder="e.g. Why predictive maintenance is going mainstream in 2026" required>
        </label>
        <label>Length
          <select name="length">
            <?php foreach (BLOG_LENGTH_PRESETS as $key => $preset): ?>
              <option value="<?= h($key) ?>"<?= $key === BLOG_LENGTH_DEFAULT ? ' selected' : '' ?>><?= h($preset['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn-primary" <?= ai_configured($aiConfig) ? '' : 'disabled title="Add an AI provider key in Settings first"' ?>>Generate</button>
      </form>
    </section>

    <?php
    $sections = [
        'Scheduled' => $scheduled,
        'Drafts' => $drafts,
        'Failed' => $failed,
        'Published' => $published,
    ];
    foreach ($sections as $label => $rows):
        if (!$rows) continue;
    ?>
    <section class="card">
      <h2><?= h($label) ?> (<?= count($rows) ?>)</h2>
      <?php foreach ($rows as $p): ?>
        <div class="account-row">
          <div class="account-info">
            <div>
              <div><strong><?= h($p['title']) ?></strong></div>
              <div class="muted">
                <?= h(date('j M Y', strtotime($p['updated_at']))) ?>
                <?php if ($p['status'] === 'scheduled' && $p['scheduled_at']): ?> · scheduled for <?= h(date('j M Y, g:i a', strtotime($p['scheduled_at']))) ?><?php endif; ?>
                <?php if ($p['status'] === 'failed' && $p['error_message']): ?> · <?= h(mb_strimwidth($p['error_message'], 0, 100, '…')) ?><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="inline-form">
            <a href="<?= h(app_path('pages/blog_studio.php?id=' . $p['id'])) ?>" class="btn-tiny">Open</a>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <?php if (!$drafts && !$scheduled && !$published && !$failed): ?>
      <section class="card"><p class="muted">No blog posts yet — write one above.</p></section>
    <?php endif; ?>
    <?php
}
require __DIR__ . '/../includes/layout_bottom.php';
