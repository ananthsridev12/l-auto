<?php
// News Studio — the review surface for news-driven auto content (see
// includes/news_fetch.php). Top: drafts the daily cron (or "Create
// Draft" here) generated from headlines, ready to open/schedule.
// Bottom: fresh fetched headlines to hand-pick from or dismiss.
// "Fetch news now" runs the same refresh the cron does, synchronously.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';
require_once __DIR__ . '/../includes/image_renderer.php';
require_once __DIR__ . '/../includes/creative_builder.php'; // creative_series_label() etc., used by generate_creative_via_ai()
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/embeddings.php';
require_once __DIR__ . '/../includes/content_memory.php';
require_once __DIR__ . '/../includes/news_fetch.php';
require_once __DIR__ . '/../includes/blog_posts.php';
require_once __DIR__ . '/../includes/blog_generate.php';
require_once __DIR__ . '/../includes/sitemap.php';

require_login();
require_module('news_studio');
$userId = current_user_id();
$workspaceId = current_workspace_id();
$workspace = current_workspace();
$aiConfig = resolve_ai_config($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/news_studio.php');
    }

    if (($_POST['form'] ?? '') === 'fetch_now') {
        $result = news_refresh($userId, $workspaceId);
        if ($result['fetched'] === 0) {
            flash('error', 'Nothing to search — add news keywords in Settings first.');
        } else {
            $msg = "Searched {$result['fetched']} topic(s), {$result['stored']} new headline(s).";
            if ($result['errors']) {
                $msg .= ' Some feeds failed: ' . implode(' · ', array_slice($result['errors'], 0, 3));
                flash('error', $msg);
            } else {
                flash('success', $msg);
            }
        }
        redirect('pages/news_studio.php');
    }

    if (($_POST['form'] ?? '') === 'create_draft') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM news_items WHERE id = ? AND user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND status = "new"');
        $stmt->execute([$itemId, $userId, $workspaceId]);
        $item = $stmt->fetch();
        if (!$item) {
            flash('error', 'Headline not found (already used or dismissed?).');
            redirect('pages/news_studio.php');
        }
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/news_studio.php');
        }
        $length = strtolower(trim($_POST['length'] ?? CAPTION_LENGTH_DEFAULT));
        if (!isset(CAPTION_LENGTH_PRESETS[$length])) {
            $length = CAPTION_LENGTH_DEFAULT;
        }
        // 'auto' (the default) keeps the original random-pick-from-enabled
        // behavior — news_generate_draft() treats a null $format that way.
        $format = trim($_POST['format'] ?? 'auto');
        if (!in_array($format, ['Text Post', 'Single Image', 'Carousel'], true)) {
            $format = null;
        }
        $slideCount = (int) ($_POST['slide_count'] ?? 0) ?: null;
        if ($slideCount !== null) {
            $slideCount = max(2, min(10, $slideCount));
        }
        try {
            $postId = news_generate_draft($userId, $item, $aiConfig, $format, $length, $slideCount);
            flash('success', 'Draft created — review it below or open it to edit/schedule.');
            redirect('pages/post.php?id=' . $postId);
        } catch (Throwable $e) {
            flash('error', 'Generation failed: ' . $e->getMessage());
            redirect('pages/news_studio.php');
        }
    }

    if (($_POST['form'] ?? '') === 'dismiss_item') {
        db()->prepare('UPDATE news_items SET status = "dismissed" WHERE id = ? AND user_id = ? AND status = "new"')
            ->execute([(int) ($_POST['item_id'] ?? 0), $userId]);
        redirect('pages/news_studio.php');
    }

    // Same scope as the "Fresh headlines" query below (this workspace's
    // own items + legacy workspace-less ones) so "Dismiss All" clears
    // everything the list is currently showing, not just the visible
    // page of up to 60.
    if (($_POST['form'] ?? '') === 'dismiss_all') {
        $stmt = db()->prepare(
            "UPDATE news_items SET status = 'dismissed'
             WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND status = 'new'"
        );
        $stmt->execute([$userId, $workspaceId]);
        flash('success', $stmt->rowCount() . ' headline(s) dismissed.');
        redirect('pages/news_studio.php');
    }

    if (($_POST['form'] ?? '') === 'dismiss_selected') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['item_ids'] ?? [])))));
        if (!$ids) {
            flash('error', 'Select at least one headline to dismiss.');
            redirect('pages/news_studio.php');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE news_items SET status = 'dismissed'
             WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND status = 'new' AND id IN ({$placeholders})"
        );
        $stmt->execute([$userId, $workspaceId, ...$ids]);
        flash('success', $stmt->rowCount() . ' headline(s) dismissed.');
        redirect('pages/news_studio.php');
    }

    if (($_POST['form'] ?? '') === 'write_blog_post') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM news_items WHERE id = ? AND user_id = ? AND (workspace_id = ? OR workspace_id IS NULL)');
        $stmt->execute([$itemId, $userId, $workspaceId]);
        $item = $stmt->fetch();
        if (!$item) {
            flash('error', 'Headline not found.');
            redirect('pages/news_studio.php');
        }
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/news_studio.php');
        }
        try {
            // Sibling headlines/snippets from the same trend — grounds the
            // post without pretending to have crawled the live web.
            $sibStmt = db()->prepare(
                "SELECT title, source, description, url FROM news_items
                 WHERE user_id = ? AND topic_query = ? AND id != ?
                 ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT 5"
            );
            $sibStmt->execute([$userId, $item['topic_query'], $itemId]);
            $siblings = $sibStmt->fetchAll();
            $researchLines = array_map(
                fn ($s) => '- ' . $s['title'] . ($s['source'] ? ' (' . $s['source'] . ')' : ''),
                $siblings
            );
            $researchContext = $researchLines ? implode("\n", $researchLines) : null;

            // Grounded Rewrite mode only — this headline's own snippet
            // first, then any siblings that also had one. Falls back to
            // Original Take behavior on its own if nothing came through
            // (news_clean_description() returns null for a feed with no
            // <description>, or for every Reddit item).
            $blogMode = ($_POST['blog_mode'] ?? '') === BLOG_MODE_GROUNDED ? BLOG_MODE_GROUNDED : BLOG_MODE_ORIGINAL;
            $includeReference = !empty($_POST['include_reference']);
            $sourceSnippets = [];
            if ($blogMode === BLOG_MODE_GROUNDED) {
                if (!empty($item['description'])) {
                    $sourceSnippets[] = ['text' => $item['description'], 'source' => $item['source'], 'url' => $item['url']];
                }
                foreach ($siblings as $s) {
                    if (!empty($s['description'])) {
                        $sourceSnippets[] = ['text' => $s['description'], 'source' => $s['source'], 'url' => $s['url']];
                    }
                }
                if (!$sourceSnippets) {
                    $blogMode = BLOG_MODE_ORIGINAL; // nothing to ground on — same as picking Original Take
                }
            }

            $contentType = trim($_POST['content_type'] ?? BLOG_CONTENT_TYPE_DEFAULT);
            if (!array_key_exists($contentType, BLOG_CONTENT_TYPES)) {
                $contentType = BLOG_CONTENT_TYPE_DEFAULT;
            }
            $freshContext = !empty($_POST['fresh_context']);
            $genWorkspace = $freshContext ? null : $workspace;

            $meta = array_filter([$item['source'] ? 'reported by ' . $item['source'] : null, $item['published_at'] ? date('j M Y', strtotime($item['published_at'])) : null]);
            $blogLength = strtolower(trim($_POST['length'] ?? BLOG_LENGTH_DEFAULT));
            if (!isset(BLOG_LENGTH_PRESETS[$blogLength])) {
                $blogLength = BLOG_LENGTH_DEFAULT;
            }
            $topic = ['title' => $item['title'], 'news_line' => $meta ? '(' . implode(', ', $meta) . ')' : null, 'length' => $blogLength];

            $relatedMemory = $freshContext ? [] : content_memory_related_for_topic($workspaceId, $item['title'], $aiConfig, 'blog');
            $existingPosts = blog_internal_link_candidates($userId, $workspaceId);
            $creative = generate_blog_post_via_ai($topic, $aiConfig, $genWorkspace, $relatedMemory, $researchContext, $existingPosts, $blogMode, $includeReference, $sourceSnippets, $contentType);
            $newBlogPostId = create_blog_post($userId, $workspaceId, $creative, $itemId, null, $contentType);
            if (!$freshContext) {
                save_blog_content_memory($workspaceId, $newBlogPostId, $creative['title'] . ' ' . $creative['meta_description'], $creative['title'], $aiConfig);
            }
            db()->prepare('UPDATE news_items SET status = "used" WHERE id = ? AND user_id = ?')->execute([$itemId, $userId]);
            flash('success', 'Blog post drafted — review and edit before publishing.');
            redirect('pages/blog_studio.php?id=' . $newBlogPostId);
        } catch (Throwable $e) {
            flash('error', 'Blog generation failed: ' . $e->getMessage());
            redirect('pages/news_studio.php');
        }
    }
}

// News drafts pending review: created by this pipeline (NEWS- campaign
// prefix), still drafts. Slide thumb comes from the first slide if any.
$draftStmt = db()->prepare(
    "SELECT p.*, (SELECT ps.filepath FROM post_slides ps WHERE ps.post_id = p.id ORDER BY ps.slide_order LIMIT 1) AS first_slide
     FROM posts p
     WHERE (p.workspace_id = ? OR (p.user_id = ? AND p.workspace_id IS NULL)) AND p.status = 'draft' AND p.campaign_id LIKE 'NEWS-%'
     ORDER BY p.created_at DESC
     LIMIT 50"
);
$draftStmt->execute([$workspaceId, $userId]);
$newsDrafts = $draftStmt->fetchAll();

$itemStmt = db()->prepare(
    "SELECT * FROM news_items
     WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND status = 'new'
     ORDER BY COALESCE(published_at, fetched_at) DESC
     LIMIT 60"
);
$itemStmt->execute([$userId, $workspaceId]);
$headlines = $itemStmt->fetchAll();

$queries = news_build_queries($userId, $workspaceId);
$autoEnabled = (bool) ($workspace['news_auto_enabled'] ?? false);
$draftsPerDay = (int) ($workspace['news_drafts_per_day'] ?? 2);
$enabledFormats = array_values(array_intersect(['Text Post', 'Single Image', 'Carousel'], get_enabled_formats($userId)));
$slideCountOptions = [3, 4, 5, 6, 7, 8, 10];

$pageTitle  = 'News Studio';
$activePage = 'news_studio';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>News Studio</h1><span class="badge badge-campaign"><?= h($workspace['name']) ?></span></div>

<section class="card">
  <div class="card-header">
    <h2>How this works</h2>
    <form method="post" style="margin:0;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="fetch_now">
      <button type="submit" class="btn-secondary">Fetch news now</button>
    </form>
  </div>
  <p class="muted">
    Searched for your <?= count($queries) ?> topic(s) — the keywords and direct RSS feeds/subreddits you've added in
    <a href="<?= h(app_path('pages/settings.php')) ?>#integrations">Settings</a>. Fresh headlines land below; each one can become a draft post
    written in your voice, or a full blog post. Drafts wait for your review — nothing posts without approval.
    <?php if ($autoEnabled): ?>
      Auto-drafting is <strong>on</strong>: up to <?= $draftsPerDay ?> draft(s) generate automatically each morning.
    <?php else: ?>
      Auto-drafting is <strong>off</strong> — turn it on in Settings to get drafts generated automatically every day.
    <?php endif; ?>
  </p>
</section>

<section class="card">
  <h2>News drafts awaiting review (<?= count($newsDrafts) ?>)</h2>
  <?php if (!$newsDrafts): ?>
    <p class="muted">No news drafts yet. Create one from a headline below<?= $autoEnabled ? ', or wait for tomorrow\'s auto-drafts' : '' ?>.</p>
  <?php else: ?>
    <div class="item-grid">
      <?php foreach ($newsDrafts as $d): ?>
        <div class="account-row item-card">
          <div class="account-info">
            <?php if ($d['first_slide']): ?>
              <img src="<?= h(slide_public_url($d['first_slide'])) ?>" style="width:56px; height:56px; object-fit:contain; border-radius:6px;" alt="">
            <?php endif; ?>
            <div>
              <div><strong><?= h($d['title']) ?></strong> <span class="badge badge-format"><?= h($d['format']) ?></span></div>
              <div class="muted"><?= h(mb_strimwidth($d['caption'] ?? '', 0, 140, '…')) ?></div>
            </div>
          </div>
          <div class="inline-form">
            <a href="<?= h(app_path('pages/post.php?id=' . $d['id'])) ?>" class="btn-tiny">Review &amp; Schedule</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Fresh headlines (<?= count($headlines) ?>)</h2>
    <?php if ($headlines): ?>
      <div style="display:flex; gap:8px;">
        <button type="submit" form="bulkDismissForm" id="dismissSelectedBtn" class="btn-tiny btn-danger" disabled>Dismiss Selected</button>
        <form method="post" onsubmit="return confirm('Dismiss all <?= count($headlines) ?> headline(s) below? This can\'t be undone.');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="dismiss_all">
          <button type="submit" class="btn-tiny btn-danger">Dismiss All</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!$headlines): ?>
    <p class="muted">No unused headlines stored. Click "Fetch news now" above<?= $queries ? '' : ' after adding news keywords in Settings' ?>.</p>
  <?php else: ?>
    <!-- Holds only the bulk-dismiss csrf/form fields — the per-row
         checkboxes below live inside their own item cards (which also
         contain other <form>s) and reference this one via the HTML5
         form="" attribute instead of nesting, since forms can't nest. -->
    <form method="post" id="bulkDismissForm" style="display:none;">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="dismiss_selected">
    </form>
    <div class="item-grid">
      <?php foreach ($headlines as $item): ?>
        <?php $rid = (int) $item['id']; ?>
        <div class="content-row item-card">
          <label class="checkbox-row" style="padding:0; gap:6px;">
            <input type="checkbox" name="item_ids[]" value="<?= $rid ?>" form="bulkDismissForm" class="js-headline-check">
            <a class="content-row-title" href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($item['title']) ?></a>
          </label>
          <div class="content-row-meta">
            <?= h($item['source'] ?: 'Unknown source') ?>
            <?= $item['published_at'] ? ' · ' . h(date('j M Y', strtotime($item['published_at']))) : '' ?>
            · matched "<?= h($item['topic_query']) ?>"
          </div>

          <div class="control-strip">
            <form method="post" class="news-draft-form" data-row="<?= $rid ?>">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="create_draft">
              <input type="hidden" name="item_id" value="<?= $rid ?>">
              <?php if (count($enabledFormats) > 1): ?>
              <div class="control-field">
                <label for="format-<?= $rid ?>">Format</label>
                <select name="format" id="format-<?= $rid ?>" class="js-format-select" title="Post format">
                  <option value="auto">Auto</option>
                  <?php foreach ($enabledFormats as $fmt): ?>
                    <option value="<?= h($fmt) ?>"><?= h($fmt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <?php if (in_array('Carousel', $enabledFormats, true)): ?>
              <div class="control-field js-slide-count-field" id="slideCountField-<?= $rid ?>" style="<?= count($enabledFormats) > 1 ? 'display:none;' : '' ?>">
                <label for="slideCount-<?= $rid ?>">Slides</label>
                <select name="slide_count" id="slideCount-<?= $rid ?>" title="Number of carousel slides">
                  <?php foreach ($slideCountOptions as $sc): ?>
                    <option value="<?= $sc ?>"<?= $sc === 5 ? ' selected' : '' ?>><?= $sc ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <div class="control-field">
                <label for="length-<?= $rid ?>">Length</label>
                <select name="length" id="length-<?= $rid ?>" title="Caption length">
                  <?php foreach (CAPTION_LENGTH_PRESETS as $lkey => $lpreset): ?>
                    <option value="<?= h($lkey) ?>"<?= $lkey === CAPTION_LENGTH_DEFAULT ? ' selected' : '' ?>><?= h($lpreset['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn-secondary" <?= ai_configured($aiConfig) ? '' : 'disabled title="Add an AI provider key in Settings first"' ?>>Create Draft</button>
            </form>
          </div>

          <div class="control-strip control-strip-secondary">
            <form method="post" style="flex:1; min-width:0;">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="write_blog_post">
              <input type="hidden" name="item_id" value="<?= $rid ?>">
              <?php if (empty($item['description'])): ?>
              <input type="hidden" name="blog_mode" value="<?= BLOG_MODE_ORIGINAL ?>">
              <?php endif; ?>
              <details class="kb-details">
                <summary>Blog post options</summary>
                <div class="control-strip" style="margin-top:0;">
                  <div class="control-field">
                    <label for="bloglength-<?= $rid ?>">Blog length</label>
                    <select name="length" id="bloglength-<?= $rid ?>" title="Blog post length">
                      <?php foreach (BLOG_LENGTH_PRESETS as $lkey => $lpreset): ?>
                        <option value="<?= h($lkey) ?>"<?= $lkey === BLOG_LENGTH_DEFAULT ? ' selected' : '' ?>><?= h($lpreset['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php if (!empty($item['description'])): ?>
                  <div class="control-field">
                    <label for="blogmode-<?= $rid ?>">Mode</label>
                    <select name="blog_mode" id="blogmode-<?= $rid ?>" title="How the source is used">
                      <option value="<?= BLOG_MODE_ORIGINAL ?>">Original Take</option>
                      <option value="<?= BLOG_MODE_GROUNDED ?>">Grounded Rewrite</option>
                    </select>
                  </div>
                  <?php endif; ?>
                  <div class="control-field">
                    <label for="blogtype-<?= $rid ?>">Content Type</label>
                    <select name="content_type" id="blogtype-<?= $rid ?>" title="Post structure">
                      <?php foreach (BLOG_CONTENT_TYPES as $tkey => $type): ?>
                        <option value="<?= h($tkey) ?>"<?= $tkey === BLOG_CONTENT_TYPE_DEFAULT ? ' selected' : '' ?>><?= h($type['label']) ?><?= $type['requires_grounded'] ? ' (needs Grounded Rewrite)' : '' ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <label class="checkbox-row" style="padding:0; gap:6px; align-self:flex-end; margin-bottom:9px;" title="Adds a 'Source:' credit line — only relevant with Grounded Rewrite">
                    <input type="checkbox" name="include_reference" value="1">
                    <span style="font-size:12px;">Cite source</span>
                  </label>
                  <label class="checkbox-row" style="padding:0; gap:6px; align-self:flex-end; margin-bottom:9px;" title="Skips this workspace's Knowledge Hub voice/tone and Memory &amp; Context for this one generation">
                    <input type="checkbox" name="fresh_context" value="1">
                    <span style="font-size:12px;">Fresh Context</span>
                  </label>
                </div>
              </details>
              <button type="submit" class="btn-tiny" style="margin-top:8px;" <?= ai_configured($aiConfig) ? '' : 'disabled title="Add an AI provider key in Settings first"' ?>>Write Blog Post</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="dismiss_item">
              <input type="hidden" name="item_id" value="<?= $rid ?>">
              <button type="submit" class="btn-tiny btn-danger" style="margin-top:8px;">Dismiss</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php $pageScripts = ['news_studio.js']; require __DIR__ . '/../includes/layout_bottom.php'; ?>
