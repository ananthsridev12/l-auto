<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/bulk_schedule.php');
    }

    $selectedIds = array_values(array_unique(array_map('intval', $_POST['post_ids'] ?? [])));
    $mode = $_POST['mode'] ?? '';

    if (empty($selectedIds)) {
        flash('error', 'Select at least one post first.');
        redirect('pages/bulk_schedule.php');
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM posts WHERE user_id = ? AND id IN ($placeholders) AND status IN ('draft','scheduled')
         ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC"
    );
    $stmt->execute(array_merge([$userId], $selectedIds));
    $selectedPosts = $stmt->fetchAll();

    $enabledFormats = get_enabled_formats($userId);
    $updateStmt = db()->prepare('UPDATE posts SET scheduled_at = ?, status = "scheduled" WHERE id = ?');
    $toDraftStmt = db()->prepare('UPDATE posts SET scheduled_at = NULL, status = "draft" WHERE id = ?');

    $updated = 0;
    $skippedNoAccount = 0;
    $skippedFormat = 0;
    $skippedNotScheduled = 0;
    $skippedAlreadyDraft = 0;

    if ($mode === 'to_draft') {
        foreach ($selectedPosts as $p) {
            if ($p['status'] !== 'scheduled') {
                $skippedAlreadyDraft++;
                continue;
            }
            $toDraftStmt->execute([$p['id']]);
            $updated++;
        }
    } elseif ($mode === 'shift') {
        $days = (int) ($_POST['shift_days'] ?? 0);
        foreach ($selectedPosts as $p) {
            if ($p['status'] !== 'scheduled' || !$p['scheduled_at']) {
                $skippedNotScheduled++;
                continue;
            }
            $newDate = date('Y-m-d H:i:s', strtotime($p['scheduled_at'] . " {$days} days"));
            $updateStmt->execute([$newDate, $p['id']]);
            $updated++;
        }
    } elseif ($mode === 'same' || $mode === 'spread') {
        $startDate = trim($_POST['bulk_date'] ?? '');
        $time = trim($_POST['bulk_time'] ?? '09:00') ?: '09:00';
        if ($startDate === '') {
            flash('error', 'Choose a date first.');
            redirect('pages/bulk_schedule.php');
        }
        $cursor = new DateTime($startDate);
        foreach ($selectedPosts as $p) {
            if (!$p['linkedin_account_id']) {
                $skippedNoAccount++;
                continue;
            }
            if (!in_array($p['format'], $enabledFormats, true)) {
                $skippedFormat++;
                continue;
            }
            $newDate = $cursor->format('Y-m-d') . " {$time}:00";
            $updateStmt->execute([$newDate, $p['id']]);
            $updated++;
            if ($mode === 'spread') {
                $cursor->modify('+1 day');
            }
        }
    } else {
        flash('error', 'Choose a bulk action mode.');
        redirect('pages/bulk_schedule.php');
    }

    $parts = ["{$updated} updated"];
    if ($skippedNoAccount > 0) {
        $parts[] = "{$skippedNoAccount} skipped (no LinkedIn account assigned)";
    }
    if ($skippedFormat > 0) {
        $parts[] = "{$skippedFormat} skipped (format disabled in Settings)";
    }
    if ($skippedAlreadyDraft > 0) {
        $parts[] = "{$skippedAlreadyDraft} skipped (already a draft)";
    }
    if ($skippedNotScheduled > 0) {
        $parts[] = "{$skippedNotScheduled} skipped (not currently scheduled — shift only applies to already-scheduled posts)";
    }
    flash($updated > 0 ? 'success' : 'error', implode(', ', $parts) . '.');
    redirect('pages/bulk_schedule.php');
}

$stmt = db()->prepare(
    'SELECT p.id, p.campaign_id, p.title, p.format, p.status, p.scheduled_at, p.linkedin_account_id, la.display_name AS account_name
     FROM posts p
     LEFT JOIN linkedin_accounts la ON la.id = p.linkedin_account_id
     WHERE p.user_id = ? AND (p.workspace_id = ? OR p.workspace_id IS NULL) AND p.status IN ("draft","scheduled")
     ORDER BY COALESCE(p.scheduled_at, p.created_at) ASC, p.id ASC'
);
$stmt->execute([$userId, current_workspace_id()]);
$posts = $stmt->fetchAll();

$pageTitle  = 'Bulk Schedule';
$activePage = 'bulk_schedule';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Bulk Schedule</h1></div>
<p class="muted">Select drafts and/or scheduled posts below, then apply one change to all of them at once. Posted posts never show up here and can't be touched.</p>

<?php if (empty($posts)): ?>
  <section class="card"><p class="muted">No drafts or scheduled posts right now.</p></section>
<?php else: ?>
<form method="post" id="bulkForm">
  <input type="hidden" name="csrf" value="<?= h($token) ?>">

  <section class="card">
    <table class="preview-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>Campaign ID</th><th>Title</th><th>Format</th><th>Status</th><th>Account</th><th>Current Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($posts as $p): ?>
          <tr>
            <td><input type="checkbox" name="post_ids[]" value="<?= (int) $p['id'] ?>" class="row-check"></td>
            <td><a href="<?= h(app_path('pages/post.php?id=' . $p['id'])) ?>"><?= h($p['campaign_id']) ?></a></td>
            <td><?= h($p['title']) ?></td>
            <td><?= h($p['format']) ?></td>
            <td><span class="badge badge-<?= h(strtolower($p['status'])) ?>"><?= h(ucfirst($p['status'])) ?></span></td>
            <td><?= h($p['account_name'] ?? '— unassigned —') ?></td>
            <td><?= h($p['scheduled_at'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <h2>Apply to selected</h2>
    <div class="stacked-form">
      <label class="checkbox-row"><input type="radio" name="mode" value="same" checked> Set the same date/time for all selected</label>
      <label class="checkbox-row"><input type="radio" name="mode" value="spread"> Auto-spread one per day, starting from a date</label>
      <label class="checkbox-row"><input type="radio" name="mode" value="shift"> Shift already-scheduled posts by N days (ignores drafts)</label>
      <label class="checkbox-row"><input type="radio" name="mode" value="to_draft"> Move selected back to Draft (unschedule, ignores existing drafts)</label>

      <div id="dateFields" class="schedule-row">
        <label>Date <input type="date" name="bulk_date"></label>
        <label>Time <input type="time" name="bulk_time" value="09:00"></label>
      </div>
      <div id="shiftFields" style="display:none;">
        <label>Days to shift <span class="muted">(negative moves earlier)</span>
          <input type="number" name="shift_days" value="1" style="width:100px;">
        </label>
      </div>

      <button type="submit" class="btn-primary">Apply</button>
    </div>
  </section>
</form>

<script>
  document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
  });
  const modeRadios = document.querySelectorAll('input[name=mode]');
  const dateFields = document.getElementById('dateFields');
  const shiftFields = document.getElementById('shiftFields');
  function updateModeFields() {
    const mode = document.querySelector('input[name=mode]:checked').value;
    dateFields.style.display = (mode === 'same' || mode === 'spread') ? 'flex' : 'none';
    shiftFields.style.display = mode === 'shift' ? 'block' : 'none';
  }
  modeRadios.forEach(r => r.addEventListener('change', updateModeFields));
  updateModeFields();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
