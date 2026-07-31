<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/organizations.php';
require_once __DIR__ . '/../includes/modules.php';

require_superadmin();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/admin.php');
    }

    $form = $_POST['form'] ?? '';

    if ($form === 'assign_plan') {
        $orgId = (int) ($_POST['org_id'] ?? 0);
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $stmt = db()->prepare('SELECT id FROM plans WHERE id = ? AND is_active = 1');
        $stmt->execute([$planId]);
        if ($orgId && $stmt->fetchColumn()) {
            db()->prepare('UPDATE organizations SET plan_id = ? WHERE id = ?')->execute([$planId, $orgId]);
            flash('success', 'Plan updated.');
        } else {
            flash('error', 'Invalid organization or plan.');
        }
        redirect('pages/admin.php#organizations');
    }

    if ($form === 'org_modules') {
        $orgId = (int) ($_POST['org_id'] ?? 0);
        if ($orgId) {
            // Unchecked "override" = go back to inheriting the plan's
            // default_modules (NULL), same NULL-means-default convention
            // as the org's own enabled_modules column.
            $useOverride = !empty($_POST['use_override']);
            set_org_enabled_modules($orgId, $useOverride ? ($_POST['modules'] ?? []) : null);
            flash('success', 'Modules updated.');
        }
        redirect('pages/admin.php#organizations');
    }

    if ($form === 'save_plan') {
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $maxUsers = trim($_POST['max_users'] ?? '') === '' ? null : max(0, (int) $_POST['max_users']);
        $maxWorkspaces = trim($_POST['max_workspaces'] ?? '') === '' ? null : max(0, (int) $_POST['max_workspaces']);
        $maxPosts = trim($_POST['max_posts_per_month'] ?? '') === '' ? null : max(0, (int) $_POST['max_posts_per_month']);
        $modules = implode(',', array_values(array_intersect(MODULE_KEYS, $_POST['modules'] ?? [])));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            flash('error', 'Plan name and a valid slug (lowercase letters/numbers/-/_ only) are required.');
            redirect('pages/admin.php#plans');
        }

        if ($planId) {
            db()->prepare(
                'UPDATE plans SET name = ?, slug = ?, max_users = ?, max_workspaces = ?, max_posts_per_month = ?, default_modules = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $slug, $maxUsers, $maxWorkspaces, $maxPosts, $modules, $isActive, $planId]);
            flash('success', 'Plan updated.');
        } else {
            $stmt = db()->prepare('SELECT id FROM plans WHERE slug = ?');
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn()) {
                flash('error', 'A plan with that slug already exists.');
                redirect('pages/admin.php#plans');
            }
            db()->prepare(
                'INSERT INTO plans (name, slug, max_users, max_workspaces, max_posts_per_month, default_modules, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$name, $slug, $maxUsers, $maxWorkspaces, $maxPosts, $modules, $isActive]);
            flash('success', 'Plan created.');
        }
        redirect('pages/admin.php#plans');
    }
}

// ── Organizations list ──────────────────────────────────────────────
$orgs = db()->query(
    "SELECT o.*, p.name AS plan_name,
            (SELECT email FROM users WHERE organization_id = o.id AND org_role = 'owner' LIMIT 1) AS owner_email
     FROM organizations o JOIN plans p ON p.id = o.plan_id
     ORDER BY o.created_at DESC"
)->fetchAll();

$plans = db()->query('SELECT * FROM plans ORDER BY is_active DESC, name')->fetchAll();
$activePlans = array_filter($plans, fn ($p) => (int) $p['is_active'] === 1);

$pageTitle  = 'Admin';
$activePage = 'admin';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Admin</h1></div>

<nav class="settings-tabs" id="adminTabs">
  <button type="button" class="settings-tab-btn" data-tab-target="organizations">Organizations</button>
  <button type="button" class="settings-tab-btn" data-tab-target="plans">Plans</button>
</nav>

<section class="card" data-tab="organizations">
  <h2>Organizations</h2>
  <p class="muted">Every signup gets its own organization automatically. Assign a plan and, if needed, override which modules are enabled — independent of the plan's own defaults.</p>

  <table class="preview-table">
    <thead><tr><th>Organization</th><th>Owner</th><th>Plan</th><th>Users</th><th>Workspaces</th><th>Modules</th></tr></thead>
    <tbody>
      <?php foreach ($orgs as $org): ?>
        <?php
        $orgId = (int) $org['id'];
        $userCount = org_usage_count($orgId, 'users');
        $wsCount = org_usage_count($orgId, 'workspaces');
        $enabledMods = org_enabled_modules($orgId);
        $isOverridden = $org['enabled_modules'] !== null;
        ?>
        <tr>
          <td><?= h($org['name']) ?></td>
          <td><?= h($org['owner_email'] ?? '—') ?></td>
          <td>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="form" value="assign_plan">
              <input type="hidden" name="org_id" value="<?= $orgId ?>">
              <select name="plan_id" onchange="this.form.submit()">
                <?php foreach ($activePlans as $p): ?>
                  <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $org['plan_id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td><?= $userCount ?><?= $org['max_users'] !== null ? ' / ' . (int) $org['max_users'] : '' ?></td>
          <td><?= $wsCount ?><?= $org['max_workspaces'] !== null ? ' / ' . (int) $org['max_workspaces'] : '' ?></td>
          <td><?= $isOverridden ? '<span class="badge badge-warning">Overridden</span> ' : '' ?><?= h(implode(', ', array_map(fn ($k) => MODULE_LABELS[$k] ?? $k, $enabledMods))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php foreach ($orgs as $org): ?>
    <?php
    $orgId = (int) $org['id'];
    $enabledMods = org_enabled_modules($orgId);
    $isOverridden = $org['enabled_modules'] !== null;
    $members = fetch_org_members($orgId);
    ?>
    <details class="card" style="margin-top: var(--space-4)">
      <summary><strong><?= h($org['name']) ?></strong> — edit modules &amp; view members</summary>

      <form method="post" class="stacked-form" style="margin-top: var(--space-3)">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="form" value="org_modules">
        <input type="hidden" name="org_id" value="<?= $orgId ?>">
        <label class="checkbox-row">
          <input type="checkbox" name="use_override" value="1" <?= $isOverridden ? 'checked' : '' ?> onchange="document.getElementById('modChecks<?= $orgId ?>').querySelectorAll('input').forEach(function(c){c.disabled=!this.checked}, this)">
          Override this organization's modules (unchecked = follow the plan's defaults)
        </label>
        <div id="modChecks<?= $orgId ?>">
          <?php foreach (MODULE_KEYS as $key): ?>
            <label class="checkbox-row">
              <input type="checkbox" name="modules[]" value="<?= h($key) ?>" <?= in_array($key, $enabledMods, true) ? 'checked' : '' ?> <?= $isOverridden ? '' : 'disabled' ?>>
              <?= h(MODULE_LABELS[$key]) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-primary">Save Modules</button>
      </form>

      <h3 style="margin-top: var(--space-4)">Members (<?= count($members) ?>)</h3>
      <table class="preview-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr><td><?= h($m['name'] ?: '—') ?></td><td><?= h($m['email']) ?></td><td><?= h(ucfirst($m['org_role'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  <?php endforeach; ?>
</section>

<section class="card" data-tab="plans">
  <h2>Plans</h2>
  <p class="muted">No payment gateway yet — a plan is just a named bundle of usage limits and a default module set. Leave a limit blank for unlimited.</p>

  <table class="preview-table">
    <thead><tr><th>Name</th><th>Slug</th><th>Max Users</th><th>Max Workspaces</th><th>Max Posts/mo</th><th>Active</th></tr></thead>
    <tbody>
      <?php foreach ($plans as $p): ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><?= h($p['slug']) ?></td>
          <td><?= $p['max_users'] ?? '∞' ?></td>
          <td><?= $p['max_workspaces'] ?? '∞' ?></td>
          <td><?= $p['max_posts_per_month'] ?? '∞' ?></td>
          <td><?= $p['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-draft">Inactive</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php foreach ($plans as $p): ?>
    <details class="card" style="margin-top: var(--space-4)">
      <summary>Edit <strong><?= h($p['name']) ?></strong></summary>
      <?php $planModules = array_filter(explode(',', $p['default_modules'])); ?>
      <form method="post" class="stacked-form" style="margin-top: var(--space-3)">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="form" value="save_plan">
        <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
        <label>Name <input type="text" name="name" value="<?= h($p['name']) ?>" required></label>
        <label>Slug <input type="text" name="slug" value="<?= h($p['slug']) ?>" required pattern="[a-z0-9_-]+"></label>
        <label>Max Users (blank = unlimited) <input type="number" name="max_users" min="0" value="<?= h((string) $p['max_users']) ?>"></label>
        <label>Max Workspaces (blank = unlimited) <input type="number" name="max_workspaces" min="0" value="<?= h((string) $p['max_workspaces']) ?>"></label>
        <label>Max Posts/Month (blank = unlimited) <input type="number" name="max_posts_per_month" min="0" value="<?= h((string) $p['max_posts_per_month']) ?>"></label>
        <?php foreach (MODULE_KEYS as $key): ?>
          <label class="checkbox-row">
            <input type="checkbox" name="modules[]" value="<?= h($key) ?>" <?= in_array($key, $planModules, true) ? 'checked' : '' ?>>
            <?= h(MODULE_LABELS[$key]) ?>
          </label>
        <?php endforeach; ?>
        <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Active (selectable when assigning a plan)</label>
        <button type="submit" class="btn-primary">Save Plan</button>
      </form>
    </details>
  <?php endforeach; ?>

  <details class="card" style="margin-top: var(--space-4)">
    <summary>+ New Plan</summary>
    <form method="post" class="stacked-form" style="margin-top: var(--space-3)">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="form" value="save_plan">
      <label>Name <input type="text" name="name" required></label>
      <label>Slug <input type="text" name="slug" required pattern="[a-z0-9_-]+" placeholder="e.g. starter"></label>
      <label>Max Users (blank = unlimited) <input type="number" name="max_users" min="0"></label>
      <label>Max Workspaces (blank = unlimited) <input type="number" name="max_workspaces" min="0"></label>
      <label>Max Posts/Month (blank = unlimited) <input type="number" name="max_posts_per_month" min="0"></label>
      <?php foreach (MODULE_KEYS as $key): ?>
        <label class="checkbox-row">
          <input type="checkbox" name="modules[]" value="<?= h($key) ?>" checked>
          <?= h(MODULE_LABELS[$key]) ?>
        </label>
      <?php endforeach; ?>
      <label class="checkbox-row"><input type="checkbox" name="is_active" value="1" checked> Active (selectable when assigning a plan)</label>
      <button type="submit" class="btn-primary">Create Plan</button>
    </form>
  </details>
</section>

<script>
  (function () {
    var VALID_TABS = ['organizations', 'plans'];
    var tabBtns = document.querySelectorAll('#adminTabs .settings-tab-btn');
    var panels = document.querySelectorAll('[data-tab]');

    function activate(tab) {
      if (VALID_TABS.indexOf(tab) === -1) tab = VALID_TABS[0];
      tabBtns.forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tabTarget === tab);
      });
      panels.forEach(function (panel) {
        panel.style.display = panel.dataset.tab === tab ? '' : 'none';
      });
    }

    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        activate(btn.dataset.tabTarget);
        history.replaceState(null, '', '#' + btn.dataset.tabTarget);
      });
    });

    activate((location.hash || '').replace('#', ''));
  })();
</script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
