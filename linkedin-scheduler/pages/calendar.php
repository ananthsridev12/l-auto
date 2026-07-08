<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));

$stmt = db()->prepare(
    "SELECT id, campaign_id, title, format, status, DATE(scheduled_at) AS sched_date
     FROM posts
     WHERE user_id = ? AND scheduled_at IS NOT NULL
       AND YEAR(scheduled_at) = ? AND MONTH(scheduled_at) = ?"
);
$stmt->execute([$userId, $year, $month]);
$postsByDate = [];
foreach ($stmt->fetchAll() as $row) {
    $postsByDate[$row['sched_date']] = $row;
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

<div class="calendar-card">
  <div class="cal-weekdays">
    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
  </div>
  <div class="cal-grid">
    <?php foreach ($grid as $week): foreach ($week as $cell): ?>
      <?php if ($cell === null): ?>
        <div class="cal-cell empty"></div>
      <?php else: ?>
        <div class="cal-cell <?= $cell['date'] === $today ? 'is-today' : '' ?> <?= $cell['post'] ? 'has-post' : '' ?>"
             <?= $cell['post'] ? 'onclick="window.location=\'' . h(app_path('pages/post.php?id=' . $cell['post']['id'])) . '\'"' : '' ?>>
          <span class="cal-day"><?= (int) $cell['day'] ?></span>
          <?php if ($cell['post']): ?>
            <span class="cal-fmt cal-fmt-<?= h(strtolower(str_replace(' ', '-', $cell['post']['format']))) ?>"><?= h($cell['post']['format']) ?></span>
            <span class="cal-title"><?= h(mb_strimwidth($cell['post']['title'] ?? $cell['post']['campaign_id'], 0, 40, '…')) ?></span>
          <?php endif; ?>
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

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
