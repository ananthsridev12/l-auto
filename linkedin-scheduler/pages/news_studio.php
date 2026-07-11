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
require_once __DIR__ . '/../includes/ai_generate.php';
require_once __DIR__ . '/../includes/news_fetch.php';

require_login();
$userId = current_user_id();
$aiConfig = resolve_ai_config($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/news_studio.php');
    }

    if (($_POST['form'] ?? '') === 'fetch_now') {
        $result = news_refresh($userId);
        if ($result['fetched'] === 0) {
            flash('error', 'Nothing to search — add Content Pillars or news keywords in Settings first.');
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
        $stmt = db()->prepare('SELECT * FROM news_items WHERE id = ? AND user_id = ? AND status = "new"');
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();
        if (!$item) {
            flash('error', 'Headline not found (already used or dismissed?).');
            redirect('pages/news_studio.php');
        }
        if (!ai_configured($aiConfig)) {
            flash('error', 'Add an AI provider key in Settings first.');
            redirect('pages/news_studio.php');
        }
        try {
            $postId = news_generate_draft($userId, $item, $aiConfig);
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
}

// News drafts pending review: created by this pipeline (NEWS- campaign
// prefix), still drafts. Slide thumb comes from the first slide if any.
$draftStmt = db()->prepare(
    "SELECT p.*, (SELECT ps.filepath FROM post_slides ps WHERE ps.post_id = p.id ORDER BY ps.slide_order LIMIT 1) AS first_slide
     FROM posts p
     WHERE p.user_id = ? AND p.status = 'draft' AND p.campaign_id LIKE 'NEWS-%'
     ORDER BY p.created_at DESC
     LIMIT 50"
);
$draftStmt->execute([$userId]);
$newsDrafts = $draftStmt->fetchAll();

$itemStmt = db()->prepare(
    "SELECT * FROM news_items
     WHERE user_id = ? AND status = 'new'
     ORDER BY COALESCE(published_at, fetched_at) DESC
     LIMIT 60"
);
$itemStmt->execute([$userId]);
$headlines = $itemStmt->fetchAll();

$queries = news_build_queries($userId);
$autoEnabled = false;
$draftsPerDay = 2;
$uStmt = db()->prepare('SELECT news_auto_enabled, news_drafts_per_day FROM users WHERE id = ?');
$uStmt->execute([$userId]);
if ($uRow = $uStmt->fetch()) {
    $autoEnabled = (bool) $uRow['news_auto_enabled'];
    $draftsPerDay = (int) $uRow['news_drafts_per_day'];
}

$pageTitle  = 'News Studio';
$activePage = 'news_studio';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>News Studio</h1></div>

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
    Google News is searched for your <?= count($queries) ?> topic(s) — every Content Pillar name plus the news keywords in
    <a href="<?= h(app_path('pages/settings.php')) ?>">Settings</a>. Fresh headlines land below; each one can become a draft post
    written in your voice (your take on the story, not a summary). Drafts wait for your review — nothing posts without approval.
    <?php if ($autoEnabled): ?>
      Auto-drafting is <strong>on</strong>: the daily cron generates up to <?= $draftsPerDay ?> draft(s) each morning.
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
    <?php foreach ($newsDrafts as $d): ?>
      <div class="account-row">
        <div class="account-info">
          <?php if ($d['first_slide']): ?>
            <img src="<?= h(slide_public_url($d['first_slide'])) ?>" style="width:56px; height:56px; object-fit:cover; border-radius:6px;" alt="">
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
  <?php endif; ?>
</section>

<section class="card">
  <h2>Fresh headlines (<?= count($headlines) ?>)</h2>
  <?php if (!$headlines): ?>
    <p class="muted">No unused headlines stored. Click "Fetch news now" above<?= $queries ? '' : ' after adding Content Pillars or news keywords in Settings' ?>.</p>
  <?php else: ?>
    <?php foreach ($headlines as $item): ?>
      <div class="account-row">
        <div class="account-info">
          <div>
            <div>
              <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($item['title']) ?></a>
            </div>
            <div class="muted">
              <?= h($item['source'] ?: 'Unknown source') ?>
              <?= $item['published_at'] ? ' · ' . h(date('j M Y', strtotime($item['published_at']))) : '' ?>
              · matched "<?= h($item['topic_query']) ?>"
            </div>
          </div>
        </div>
        <div class="inline-form">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="create_draft">
            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
            <button type="submit" class="btn-tiny" <?= ai_configured($aiConfig) ? '' : 'disabled title="Add an AI provider key in Settings first"' ?>>Create Draft</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="form" value="dismiss_item">
            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
            <button type="submit" class="btn-tiny btn-danger">Dismiss</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
