<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));

$workspaceId = current_workspace_id();
$stmt = db()->prepare(
    "SELECT id, campaign_id, title, format, status, DATE(scheduled_at) AS sched_date
     FROM posts
     WHERE user_id = ? AND (workspace_id = ? OR workspace_id IS NULL) AND scheduled_at IS NOT NULL
       AND YEAR(scheduled_at) = ? AND MONTH(scheduled_at) = ?"
);
$stmt->execute([$userId, $workspaceId, $year, $month]);
$postsByDate = [];
foreach ($stmt->fetchAll() as $row) {
    $postsByDate[$row['sched_date']][] = $row;
}

$grid = build_calendar_grid($year, $month, $postsByDate);
$monthName = (new DateTime("{$year}-{$month}-01"))->format('F');
$today = date('Y-m-d');

$prevMonth = $month === 1 ? 12 : $month - 1;
$prevYear  = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear  = $month === 12 ? $year + 1 : $year;

$pageTitle  = "{$monthName} {$year}";
$activePage = 'calendar';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1><?= h($monthName) ?> <?= (int) $year ?></h1>
  <div class="cal-nav">
    <a href="<?= h(app_path("pages/calendar.php?year={$prevYear}&month={$prevMonth}")) ?>">&larr; Prev</a>
    <a href="<?= h(app_path("pages/calendar.php?year={$nextYear}&month={$nextMonth}")) ?>">Next &rarr;</a>
  </div>
</div>

<p class="muted cal-scroll-hint">Swipe sideways to see the full week &rarr;</p>
<div class="calendar-card">
  <div class="cal-weekdays">
    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
  </div>
  <div class="cal-grid">
    <?php foreach ($grid as $week): foreach ($week as $cell): ?>
      <?php if ($cell === null): ?>
        <div class="cal-cell empty"></div>
      <?php else: ?>
        <div class="cal-cell <?= $cell['date'] === $today ? 'is-today' : '' ?> <?= $cell['posts'] ? 'has-post' : '' ?>">
          <span class="cal-day"><?= (int) $cell['day'] ?></span>
          <?php foreach ($cell['posts'] as $post): ?>
            <a class="cal-post-row" href="<?= h(app_path('pages/post.php?id=' . $post['id'])) ?>">
              <span class="cal-badges">
                <span class="cal-fmt cal-fmt-<?= h(strtolower(str_replace(' ', '-', $post['format']))) ?>"><?= h($post['format']) ?></span>
                <span class="cal-status cal-status-<?= h(strtolower($post['status'])) ?>"><?= h(ucfirst($post['status'])) ?></span>
              </span>
              <span class="cal-title"><?= h(mb_strimwidth($post['title'] ?? $post['campaign_id'], 0, 30, '…')) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; endforeach; ?>
  </div>
</div>

<div class="cal-legend">
  <span class="legend-item"><span class="dot dot-image"></span>Single Image</span>
  <span class="legend-item"><span class="dot dot-carousel"></span>Carousel</span>
  <span class="legend-item"><span class="dot dot-text-post"></span>Text Post</span>
  <span class="legend-item"><span class="dot dot-poll"></span>Poll</span>
</div>
<div class="cal-legend">
  <span class="legend-item"><span class="cal-status cal-status-draft">Draft</span></span>
  <span class="legend-item"><span class="cal-status cal-status-scheduled">Scheduled</span></span>
  <span class="legend-item"><span class="cal-status cal-status-posted">Posted</span></span>
  <span class="legend-item"><span class="cal-status cal-status-failed">Failed</span></span>
</div>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
