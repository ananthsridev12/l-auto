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
require_once __DIR__ . '/../includes/jekyll_api.php';
require_once __DIR__ . '/../includes/grav_api.php';
require_once __DIR__ . '/../includes/sitemap.php';
require_once __DIR__ . '/../includes/collections.php';

require_login();
require_module('blog_studio');
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
            $freshContext = !empty($_POST['fresh_context']);
            $genWorkspace = $freshContext ? null : $workspace;
            $relatedMemory = $freshContext ? [] : content_memory_related_for_topic($workspaceId, $topicTitle, $aiConfig, 'blog');
            $existingPosts = blog_internal_link_candidates($userId, $workspaceId);
            $contentType = trim($_POST['content_type'] ?? BLOG_CONTENT_TYPE_DEFAULT);
            if (!array_key_exists($contentType, BLOG_CONTENT_TYPES)) {
                $contentType = BLOG_CONTENT_TYPE_DEFAULT;
            }
            $pillarId = (int) ($_POST['content_pillar_id'] ?? 0) ?: null;
            if ($pillarId !== null && !fetch_content_pillar($userId, $pillarId)) {
                $pillarId = null;
            }
            $collectionId = (int) ($_POST['collection_id'] ?? 0) ?: null;
            if ($collectionId !== null && !fetch_content_collection($userId, $collectionId)) {
                $collectionId = null;
            }
            $creative = generate_blog_post_via_ai(['title' => $topicTitle, 'length' => $length], $aiConfig, $genWorkspace, $relatedMemory, null, $existingPosts, BLOG_MODE_ORIGINAL, false, [], $contentType);
            $newPostId = create_blog_post($userId, $workspaceId, $creative, null, $pillarId, $contentType, $collectionId);
            if (!$freshContext) {
                save_blog_content_memory($workspaceId, $newPostId, $creative['title'] . ' ' . $creative['meta_description'], $creative['title'], $aiConfig);
            }
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
        $fields = [
            'title'            => trim($_POST['title'] ?? $existing['title']),
            'slug'             => blog_slugify(trim($_POST['slug'] ?? $existing['slug'])),
            'meta_description' => trim($_POST['meta_description'] ?? '') ?: null,
            'keywords'         => trim($_POST['keywords'] ?? '') ?: null,
            'content_html'     => $_POST['content_html'] ?? $existing['content_html'],
            // Grav-only taxonomy — see the site's own taxonomy reference
            // doc: category is News-fixed-enum/Blog-free-label,
            // service is the exact URL slug of a /services/ page,
            // industry is a plain (non-taxonomy) header field. All
            // optional; grav_publish_post() only sends what's set.
            'grav_category'    => trim($_POST['grav_category'] ?? '') ?: null,
            'grav_service'     => trim($_POST['grav_service'] ?? '') ?: null,
            'grav_industry'    => trim($_POST['grav_industry'] ?? '') ?: null,
        ];
        if (in_array($_POST['publish_target'] ?? null, ['wordpress', 'jekyll', 'grav'], true)) {
            $fields['publish_target'] = $_POST['publish_target'];
        }
        if (array_key_exists('content_pillar_id', $_POST)) {
            $pillarId = (int) $_POST['content_pillar_id'] ?: null;
            $fields['content_pillar_id'] = ($pillarId !== null && fetch_content_pillar($userId, $pillarId)) ? $pillarId : null;
        }
        if (array_key_exists('collection_id', $_POST)) {
            $collectionId = (int) $_POST['collection_id'] ?: null;
            $fields['collection_id'] = ($collectionId !== null && fetch_content_collection($userId, $collectionId)) ? $collectionId : null;
        }
        update_blog_post($userId, $postId, $fields);
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
        $target = blog_resolve_publish_target($workspace, $existing);
        if ($target === null) {
            flash('error', 'Connect WordPress, Jekyll, or Grav for this workspace in Settings first.');
            redirect('pages/blog_studio.php?id=' . $postId);
        }
        // Only Grav consults the pillar (its route/template override) —
        // wordpress_publish_post()/jekyll_publish_post() have a
        // 2-argument signature and don't take one.
        if ($target === 'grav') {
            $pillar = $existing['content_pillar_id'] ? fetch_content_pillar($userId, (int) $existing['content_pillar_id']) : null;
            $result = grav_publish_post($workspace, $existing, $pillar);
        } else {
            $publishers = [
                'wordpress' => 'wordpress_publish_post',
                'jekyll'    => 'jekyll_publish_post',
            ];
            $result = $publishers[$target]($workspace, $existing);
        }
        if ($result['success']) {
            mark_blog_post_published($postId, $result['external_post_id'], $result['external_url'] ?? null, $target);
            $successMsg = [
                'wordpress' => 'Published to WordPress.',
                'jekyll'    => 'Published to Jekyll (GitHub commit pushed — deploy it from cPanel to go live).',
                'grav'      => 'Published to Grav — live now.',
            ];
            flash('success', $successMsg[$target]);
        } else {
            mark_blog_post_failed($postId, $result['error']);
            flash('error', $result['error']);
        }
        redirect('pages/blog_studio.php?id=' . $postId);
    }

    // Grav-only: soft "mark as deleted" (header.published = false — the
    // page stays on the site, just hidden) and its reverse, plus a real
    // permanent delete. All three require the post to actually be a
    // published-to-Grav page (external_post_id set on a grav target).
    if (in_array($action, ['grav_unpublish', 'grav_republish', 'grav_delete'], true)) {
        if ($existing['publish_target'] !== 'grav' || empty($existing['external_post_id'])) {
            flash('error', 'This post has no Grav page to act on.');
            redirect('pages/blog_studio.php?id=' . $postId);
        }
        if ($action === 'grav_delete') {
            $result = grav_delete_post($workspace, $existing);
            if ($result['success']) {
                mark_blog_post_deleted_from_platform($postId);
                flash('success', 'Deleted from Grav permanently.');
            } else {
                flash('error', $result['error']);
            }
        } else {
            $publish = $action === 'grav_republish';
            $result = grav_set_published($workspace, $existing, $publish);
            if ($result['success']) {
                $publish ? mark_blog_post_published($postId, $existing['external_post_id'], $existing['external_url'], 'grav') : mark_blog_post_unpublished($postId);
                flash('success', $publish ? 'Republished on Grav.' : 'Unpublished from Grav — the page is hidden but not deleted.');
            } else {
                flash('error', $result['error']);
            }
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
$contentPillars = fetch_content_pillars($userId, $workspaceId);
$contentCollections = fetch_content_collections($userId, $workspaceId);
require __DIR__ . '/../includes/layout_top.php';

if ($postId) {
    $post = fetch_blog_post($userId, $postId);
    if (!$post || (int) $post['workspace_id'] !== $workspaceId) {
        echo '<div class="page-header"><h1>Blog Studio</h1></div><section class="card"><p class="muted">Blog post not found.</p></section>';
        require __DIR__ . '/../includes/layout_bottom.php';
        exit;
    }
    $postPillar = $post['content_pillar_id'] ? fetch_content_pillar($userId, (int) $post['content_pillar_id']) : null;
    $platformLabels = ['wordpress' => 'WordPress', 'jekyll' => 'Jekyll (GitHub)', 'grav' => 'Grav'];
    $configuredTargets = array_filter([
        'wordpress' => wordpress_configured($workspace),
        'jekyll'    => jekyll_configured($workspace),
        'grav'      => grav_configured($workspace),
    ]);
    $resolvedTarget = blog_resolve_publish_target($workspace, $post);
    $publishPlatformLabel = $platformLabels[$resolvedTarget] ?? 'WordPress';
    // Only meaningful for Grav (see includes/grav_api.php's taxonomy
    // block) — the resolved template decides whether Category is a
    // fixed News enum or a free Blog label, per the site's own
    // taxonomy reference doc.
    $resolvedGravTemplate = $resolvedTarget === 'grav' ? grav_template($workspace, $postPillar) : null;
    // A post stays field-locked while it has a live (or hidden-but-still-
    // there) remote copy — 'unpublished' means the Grav page still
    // exists, just hidden, same reasoning as 'published'.
    $locked = in_array($post['status'], ['published', 'unpublished'], true);
    $isGravManaged = $post['publish_target'] === 'grav' && !empty($post['external_post_id']);
    $statusBadgeClass = match ($post['status']) {
        'published'   => 'badge-active',
        'unpublished' => 'badge-warning',
        'failed'      => 'badge-warning',
        'scheduled'   => 'badge-scheduled',
        default       => 'badge-format',
    };
    ?>
    <div class="page-header">
      <h1><?= h($post['title']) ?></h1>
      <span class="badge <?= $statusBadgeClass ?>"><?= h(ucfirst($post['status'])) ?></span>
      <?php if ($post['content_type'] && isset(BLOG_CONTENT_TYPES[$post['content_type']])): ?>
        <span class="badge badge-format"><?= h(BLOG_CONTENT_TYPES[$post['content_type']]['label']) ?></span>
      <?php endif; ?>
    </div>
    <a href="<?= h(app_path('pages/blog_studio.php')) ?>">&larr; Back to Blog Studio</a>

    <?php $seo = blog_post_seo_score($post); ?>
    <section class="card">
      <details class="kb-details">
        <summary>
          SEO Score: <strong><?= (int) $seo['score'] ?>/100</strong>
          <span class="badge <?= $seo['score'] >= 80 ? 'badge-active' : ($seo['score'] >= 50 ? 'badge-scheduled' : 'badge-warning') ?>"><?= $seo['score'] >= 80 ? 'Good' : ($seo['score'] >= 50 ? 'Needs work' : 'Weak') ?></span>
        </summary>
        <p class="muted" style="margin-top:var(--space-2);">A plain rules checklist (not an AI judgment) — every item here is something you could verify by eye, this just saves counting characters by hand.</p>
        <ul style="margin:var(--space-2) 0 0; padding-left:20px; list-style:none;">
          <?php foreach ($seo['checks'] as $check): ?>
            <li style="margin-bottom:4px;">
              <span class="badge <?= $check['pass'] ? 'badge-active' : 'badge-warning' ?>"><?= $check['pass'] ? 'Pass' : 'Fix' ?></span>
              <?= h($check['label']) ?> — <span class="muted"><?= h($check['detail']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    </section>

    <?php if ($post['status'] === 'failed' && $post['error_message']): ?>
      <section class="card"><p class="badge badge-warning">Last publish attempt failed: <?= h($post['error_message']) ?></p></section>
    <?php endif; ?>

    <?php if ($post['status'] === 'published' || $post['status'] === 'unpublished'): ?>
      <section class="card">
        <p>
          <?= $post['status'] === 'published' ? 'Published' : 'Unpublished (hidden, not deleted)' ?> <?= h(date('j M Y, g:i a', strtotime($post['published_at']))) ?><?php if ($post['external_url']): ?> — <a href="<?= h($post['external_url']) ?>" target="_blank" rel="noopener noreferrer">View on <?= h($platformLabels[$post['publish_target']] ?? 'WordPress') ?></a><?php endif; ?><?php if ($post['publish_target'] === 'jekyll'): ?> <span class="muted">(remember to deploy from cPanel if the site hasn't picked this up yet)</span><?php endif; ?>
        </p>
      </section>
    <?php endif; ?>

    <?php if ($isGravManaged && $post['status'] !== 'draft'): ?>
    <section class="card">
      <h2>Grav Page Management</h2>
      <p class="muted">The page still exists on Grav even when unpublished — only "Delete Permanently" actually removes it. If the page was already deleted directly on the Grav site itself (outside this app), use "Delete Permanently" here too — it clears this post back to a Draft you can edit and Publish Now again, which creates a fresh page.</p>
      <?php if ($post['status'] === 'unpublished'): ?>
        <form method="post" style="display:inline-block; margin-right:12px;">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="grav_republish">
          <button type="submit" class="btn-secondary">Republish</button>
        </form>
      <?php else: ?>
        <form method="post" style="display:inline-block; margin-right:12px;">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="grav_unpublish">
          <button type="submit" class="btn-secondary">Unpublish (mark as deleted)</button>
        </form>
      <?php endif; ?>
      <form method="post" style="display:inline-block;" onsubmit="return confirm('Permanently delete this page from Grav? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="grav_delete">
        <button type="submit" class="btn-tiny btn-danger">Delete Permanently from Grav</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2>Edit</h2>
      <form method="post" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="save">
        <label>Title
          <input type="text" name="title" value="<?= h($post['title']) ?>" <?= $locked ? 'disabled' : 'required' ?>>
        </label>
        <label>Slug
          <input type="text" name="slug" value="<?= h($post['slug']) ?>" <?= $locked ? 'disabled' : '' ?>>
        </label>
        <label>Meta description <span class="muted">(120-155 characters, for SEO)</span>
          <input type="text" name="meta_description" value="<?= h($post['meta_description'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
        </label>
        <label>Keywords
          <input type="text" name="keywords" value="<?= h($post['keywords'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
        </label>
        <?php
          // Maps 1:1 to the site's taxonomy reference doc's "Quick
          // reference by content type" table for the 5 templates it
          // names. An unrecognized/unset template (e.g. the workspace
          // is still on Grav's generic "item" default, never
          // customized) permissively shows all three rather than
          // hiding them — an unused field here is simply never sent
          // (grav_publish_post() only includes what's set), while
          // hiding a genuinely needed one would be a real gap.
          $knownGravTemplates = ['news-item', 'blog-item', 'portfolio-detail', 'case-study-detail', 'glossary-term'];
          $isKnownTemplate = in_array($resolvedGravTemplate, $knownGravTemplates, true);
          $showGravCategory = !$isKnownTemplate || in_array($resolvedGravTemplate, ['news-item', 'blog-item'], true);
          $showGravService = !$isKnownTemplate || in_array($resolvedGravTemplate, ['blog-item', 'portfolio-detail', 'case-study-detail'], true);
          $showGravIndustry = !$isKnownTemplate || in_array($resolvedGravTemplate, ['portfolio-detail', 'case-study-detail'], true);
        ?>
        <?php if ($resolvedTarget === 'grav'): ?>
        <?php if ($showGravCategory): ?>
          <?php if ($resolvedGravTemplate === 'news-item'): ?>
            <label>Grav Category <span class="muted">(required by the site's taxonomy — News uses a fixed list)</span>
              <select name="grav_category" <?= $locked ? 'disabled' : '' ?>>
                <option value="">— None —</option>
                <?php foreach (['Company', 'Product', 'Industry'] as $cat): ?>
                  <option value="<?= h($cat) ?>"<?= $post['grav_category'] === $cat ? ' selected' : '' ?>><?= h($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php else: ?>
            <label>Grav Category <span class="muted">(optional — free label, e.g. "Strategy"; check existing Blog posts before introducing a new one)</span>
              <input type="text" name="grav_category" value="<?= h($post['grav_category'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
            </label>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($showGravService): ?>
          <label>Grav Service <span class="muted">(optional — the exact URL slug of the related /services/ page, e.g. "analytics-tracking"; adds a "See this service" link and pulls this post into that service's Related Work)</span>
            <input type="text" name="grav_service" value="<?= h($post['grav_service'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
          </label>
        <?php endif; ?>
        <?php if ($showGravIndustry): ?>
          <label>Grav Industry <span class="muted">(optional — Portfolio/Case Study only, e.g. "Retail / Ecommerce")</span>
            <input type="text" name="grav_industry" value="<?= h($post['grav_industry'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
          </label>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($contentPillars): ?>
        <label>Content Pillar <span class="muted">(optional — a pillar with its own Grav route prefix/template routes this post there instead of the workspace default)</span>
          <select name="content_pillar_id" <?= $locked ? 'disabled' : '' ?>>
            <option value="">— None —</option>
            <?php foreach ($contentPillars as $cp): ?>
              <option value="<?= (int) $cp['id'] ?>"<?= $post['content_pillar_id'] == $cp['id'] ? ' selected' : '' ?>><?= h($cp['name']) ?><?= $cp['grav_route_prefix'] ? ' (' . h('/' . trim($cp['grav_route_prefix'], '/')) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <?php if ($contentCollections): ?>
        <label>Collection <span class="muted">(optional — groups this with related LinkedIn posts/blog posts, see Knowledge Hub)</span>
          <select name="collection_id" <?= $locked ? 'disabled' : '' ?>>
            <option value="">— None —</option>
            <?php foreach ($contentCollections as $cc): ?>
              <option value="<?= (int) $cc['id'] ?>"<?= $post['collection_id'] == $cc['id'] ? ' selected' : '' ?>><?= h($cc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <label>Body (HTML)
          <textarea name="content_html" rows="20" style="font-family:monospace;" <?= $locked ? 'disabled' : '' ?>><?= h($post['content_html']) ?></textarea>
        </label>
        <?php if (!$locked && count($configuredTargets) > 1): ?>
          <label>Publish to
            <select name="publish_target">
              <?php foreach ($configuredTargets as $key => $isConfigured): ?>
                <option value="<?= h($key) ?>"<?= $post['publish_target'] === $key ? ' selected' : '' ?>><?= h($platformLabels[$key]) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <?php if (!$locked): ?>
          <button type="submit" class="btn-primary">Save</button>
        <?php endif; ?>
      </form>
    </section>

    <?php if (!$locked): ?>
    <section class="card">
      <h2>Publish</h2>
      <?php if (!$configuredTargets): ?>
        <p class="muted">Connect WordPress, Jekyll, or Grav for this workspace in <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a> to publish or schedule.</p>
      <?php else: ?>
        <p class="muted">Will publish to <strong><?= h($publishPlatformLabel) ?></strong><?= count($configuredTargets) > 1 ? ' — change this above and Save first.' : '.' ?></p>
        <?php if ($resolvedTarget === 'grav' && empty($post['external_post_id'])): ?>
          <p class="muted">Route: <code><?= h(grav_post_route($workspace, $post, $postPillar)) ?></code><?= $postPillar && $postPillar['grav_route_prefix'] ? ' (from the "' . h($postPillar['name']) . '" pillar)' : '' ?></p>
        <?php endif; ?>
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
    $unpublished = fetch_blog_posts($userId, $workspaceId, 'unpublished');
    $failed = fetch_blog_posts($userId, $workspaceId, 'failed');
    $pillarNameById = array_column($contentPillars, 'name', 'id');
    $collectionNameById = array_column($contentCollections, 'name', 'id');
    $platformLabelsList = ['wordpress' => 'WordPress', 'jekyll' => 'Jekyll', 'grav' => 'Grav'];
    ?>
    <div class="page-header"><h1>Blog Studio</h1><span class="badge badge-campaign"><?= h($workspace['name']) ?></span></div>

    <?php $contentGaps = sitemap_content_gaps($workspaceId, $contentPillars); ?>
    <?php if ($contentGaps): ?>
    <section class="card">
      <h2>Content Gaps <span class="muted">(from your sitemap)</span></h2>
      <p class="muted">Site sections from your <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">sitemap</a> that don't obviously match any of your <a href="<?= h(app_path('pages/knowledge.php')) ?>#pillars">Content Pillars</a> — this app's content engine isn't tuned to write for them yet. A rough word-match heuristic, not a verdict — a pillar worded differently could already cover one of these.</p>
      <div class="item-grid">
        <?php foreach ($contentGaps as $gap): ?>
          <div class="item-card account-row">
            <div class="account-info">
              <span>/<?= h($gap['category']) ?></span>
              <span class="badge badge-warning"><?= (int) $gap['page_count'] ?> pages</span>
            </div>
            <a href="<?= h(app_path('pages/knowledge.php?pillar_name=' . rawurlencode(ucwords(str_replace(['-', '_'], ' ', $gap['category']))) . '#pillars')) ?>" class="btn-tiny">+ Add Pillar</a>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2>New Blog Post</h2>
      <p class="muted">Writes an original, SEO-friendly post in this workspace's voice — grounded in its Knowledge Hub documents and avoiding repeats of its own past posts (Memory &amp; Context). Review and edit before publishing.</p>
      <form method="post" class="stacked-form">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="generate">
        <label>Topic
          <input type="text" name="topic" placeholder="e.g. Why predictive maintenance is going mainstream in 2026" required>
        </label>
        <div class="control-strip">
          <div class="control-field">
            <label for="blogGenLength">Length</label>
            <select name="length" id="blogGenLength">
              <?php foreach (BLOG_LENGTH_PRESETS as $key => $preset): ?>
                <option value="<?= h($key) ?>"<?= $key === BLOG_LENGTH_DEFAULT ? ' selected' : '' ?>><?= h($preset['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($contentPillars): ?>
          <div class="control-field">
            <label for="blogGenPillar">Content Pillar</label>
            <select name="content_pillar_id" id="blogGenPillar" title="Optional — routes to that pillar's own Grav route prefix/template if it has one">
              <option value="">— None —</option>
              <?php foreach ($contentPillars as $cp): ?>
                <option value="<?= (int) $cp['id'] ?>"><?= h($cp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if ($contentCollections): ?>
          <div class="control-field">
            <label for="blogGenCollection">Collection</label>
            <select name="collection_id" id="blogGenCollection" title="Optional — groups this with related LinkedIn posts/blog posts, see Knowledge Hub">
              <option value="">— None —</option>
              <?php foreach ($contentCollections as $cc): ?>
                <option value="<?= (int) $cc['id'] ?>"><?= h($cc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="control-field">
            <label for="blogGenType">Content Type</label>
            <select name="content_type" id="blogGenType">
              <?php foreach (BLOG_CONTENT_TYPES as $tkey => $type): ?>
                <?php if ($type['requires_grounded']) continue; // News Roundup needs a news item's source facts — only offered from News Studio ?>
                <option value="<?= h($tkey) ?>"<?= $tkey === BLOG_CONTENT_TYPE_DEFAULT ? ' selected' : '' ?>><?= h($type['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="checkbox-row" title="Skips this workspace's Knowledge Hub voice/tone and Memory &amp; Context (past-post history) for this one generation">
          <input type="checkbox" name="fresh_context" value="1">
          Fresh Context <span class="muted">(no Knowledge Hub voice, no past-post memory — a clean blank slate)</span>
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
        'Unpublished' => $unpublished,
    ];
    foreach ($sections as $label => $rows):
        if (!$rows) continue;
    ?>
    <section class="card">
      <h2><?= h($label) ?> (<?= count($rows) ?>)</h2>
      <div class="item-grid">
        <?php foreach ($rows as $p): ?>
          <div class="account-row item-card">
            <div class="account-info">
              <div>
                <div>
                  <strong><?= h($p['title']) ?></strong>
                  <?php if ($p['content_type'] && isset(BLOG_CONTENT_TYPES[$p['content_type']])): ?>
                    <span class="badge badge-format"><?= h(BLOG_CONTENT_TYPES[$p['content_type']]['label']) ?></span>
                  <?php endif; ?>
                  <?php if ($p['content_pillar_id'] && isset($pillarNameById[$p['content_pillar_id']])): ?>
                    <span class="badge badge-format"><?= h($pillarNameById[$p['content_pillar_id']]) ?></span>
                  <?php endif; ?>
                  <?php if ($p['collection_id'] && isset($collectionNameById[$p['collection_id']])): ?>
                    <span class="badge badge-active"><?= h($collectionNameById[$p['collection_id']]) ?></span>
                  <?php endif; ?>
                  <?php if ($p['publish_target'] && isset($platformLabelsList[$p['publish_target']])): ?>
                    <span class="badge badge-campaign"><?= h($platformLabelsList[$p['publish_target']]) ?></span>
                  <?php endif; ?>
                </div>
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
      </div>
    </section>
    <?php endforeach; ?>

    <?php if (!$drafts && !$scheduled && !$published && !$unpublished && !$failed): ?>
      <section class="card"><p class="muted">No blog posts yet — write one above.</p></section>
    <?php endif; ?>
    <?php
}
require __DIR__ . '/../includes/layout_bottom.php';
