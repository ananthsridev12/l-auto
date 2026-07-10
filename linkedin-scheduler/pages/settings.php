<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/post_helpers.php';

require_login();
$userId = current_user_id();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'formats') {
        set_enabled_formats($userId, $_POST['formats'] ?? []);
        flash('success', 'Post formats updated.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'tag_add') {
        $name = trim($_POST['tag_name'] ?? '');
        $urn = normalize_organization_input($_POST['tag_org_id'] ?? '');
        if ($name === '' || $urn === null) {
            flash('error', 'Enter a name and a valid numeric LinkedIn organization ID.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO tag_directory (user_id, display_name, target_urn) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE target_urn = VALUES(target_urn)'
        );
        $stmt->execute([$userId, $name, $urn]);
        flash('success', "\"{$name}\" added to your tag directory.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'tag_delete') {
        $stmt = db()->prepare('DELETE FROM tag_directory WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['tag_id'] ?? 0), $userId]);
        flash('success', 'Removed from tag directory.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'ai_provider') {
        set_ai_provider($userId, $_POST['ai_provider'] ?? '');
        set_gemini_api_key($userId, $_POST['gemini_api_key'] ?? '');
        set_claude_api_key($userId, $_POST['claude_api_key'] ?? '');
        set_openai_api_key($userId, $_POST['openai_api_key'] ?? '');
        flash('success', 'AI provider settings saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'brand_brief') {
        set_brand_brief($userId, $_POST['brand_brief'] ?? '');
        set_self_brief($userId, $_POST['self_brief'] ?? '');
        flash('success', 'Brand brief saved.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'seed_kb') {
        seed_default_knowledge_base($userId);
        flash('success', 'Starter personas, content pillars, and CTAs added — anything you already had was left untouched.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'persona_add') {
        $name = trim($_POST['persona_name'] ?? '');
        $desc = trim($_POST['persona_description'] ?? '');
        if ($name === '') {
            flash('error', 'Enter a persona name.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO personas (user_id, name, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description)'
        );
        $stmt->execute([$userId, $name, $desc]);
        flash('success', "Persona \"{$name}\" saved.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'persona_delete') {
        $stmt = db()->prepare('DELETE FROM personas WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['persona_id'] ?? 0), $userId]);
        flash('success', 'Persona removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'pillar_add') {
        $name = trim($_POST['pillar_name'] ?? '');
        $desc = trim($_POST['pillar_description'] ?? '');
        $category = ($_POST['pillar_category'] ?? '') === 'personal' ? 'personal' : 'company';
        if ($name === '') {
            flash('error', 'Enter a content pillar name.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare(
            'INSERT INTO content_pillars (user_id, name, description, category) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category)'
        );
        $stmt->execute([$userId, $name, $desc, $category]);
        flash('success', "Content pillar \"{$name}\" saved.");
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'pillar_delete') {
        $stmt = db()->prepare('DELETE FROM content_pillars WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['pillar_id'] ?? 0), $userId]);
        flash('success', 'Content pillar removed.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'cta_add') {
        $text = trim($_POST['cta_text'] ?? '');
        $stage = $_POST['cta_funnel_stage'] ?? '';
        $stage = in_array($stage, ['Awareness', 'Consideration', 'Decision', 'Retention'], true) ? $stage : null;
        if ($text === '') {
            flash('error', 'Enter the CTA text.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare('INSERT INTO cta_library (user_id, text, funnel_stage) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $text, $stage]);
        flash('success', 'CTA added to your library.');
        redirect('pages/settings.php');
    }

    if (($_POST['form'] ?? '') === 'cta_delete') {
        $stmt = db()->prepare('DELETE FROM cta_library WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['cta_id'] ?? 0), $userId]);
        flash('success', 'CTA removed.');
        redirect('pages/settings.php');
    }

    $name = trim($_POST['name'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    $stmt = db()->prepare('UPDATE users SET name = ? WHERE id = ?');
    $stmt->execute([$name, $userId]);

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect('pages/settings.php');
        }
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    flash('success', 'Settings saved.');
    redirect('pages/settings.php');
}

$enabledFormats = get_enabled_formats($userId);
$tagDirectory = fetch_tag_directory($userId);
$geminiKey = get_gemini_api_key($userId);
$claudeKey = get_claude_api_key($userId);
$openaiKey = get_openai_api_key($userId);
$aiProvider = get_ai_provider($userId) ?: AI_PROVIDER_DEFAULT;
$brandBrief = get_brand_brief($userId);
$selfBrief = get_self_brief($userId);
$personas = fetch_personas($userId);
$contentPillars = fetch_content_pillars($userId);
$ctaLibrary = fetch_cta_library($userId);
$funnelStages = ['Awareness', 'Consideration', 'Decision', 'Retention'];

$pageTitle  = 'Settings';
$activePage = 'settings';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Settings</h1></div>

<section class="card">
  <h2>Profile</h2>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Name
      <input type="text" name="name" value="<?= h($user['name']) ?>">
    </label>
    <label>Email
      <input type="email" value="<?= h($user['email']) ?>" disabled>
    </label>
    <label>New Password <span class="muted">(leave blank to keep current)</span>
      <input type="password" name="new_password" minlength="8">
    </label>
    <button type="submit" class="btn-primary">Save</button>
  </form>
</section>

<section class="card">
  <h2>LinkedIn Accounts</h2>
  <p class="muted">Manage which personal profile and Company Pages are connected from the <a href="<?= h(app_path('pages/accounts.php')) ?>">Accounts</a> page.</p>
</section>

<section class="card">
  <h2>Post Formats</h2>
  <p class="muted">Only checked formats will auto-schedule from CSV import or be schedulable from the post editor. Anything else lands in Drafts instead. Poll is unchecked by default because LinkedIn's API has no way to actually publish a real poll — a "Poll" post would otherwise just go out as plain text under a misleading label.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="formats">
    <?php foreach (ALL_POST_FORMATS as $fmt): ?>
      <label class="checkbox-row">
        <input type="checkbox" name="formats[]" value="<?= h($fmt) ?>" <?= in_array($fmt, $enabledFormats, true) ? 'checked' : '' ?>>
        <?= h($fmt) ?>
        <?= $fmt === 'Poll' ? '<span class="muted">(cannot actually publish as a poll — see note above)</span>' : '' ?>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn-primary">Save Formats</button>
  </form>
</section>

<section class="card">
  <h2>AI Provider</h2>
  <p class="muted">Used by <a href="<?= h(app_path('pages/content_studio.php')) ?>">Content Studio</a> and New Post's "Generate with AI" to write captions and slide copy when you haven't written them yourself. Pick which provider to use and paste your key for it — only the selected provider's key matters. Gemini has a free tier (get a key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>); Claude and OpenAI are paid per-call unless this site has a shared key configured for them, in which case leaving your own key blank will use that instead.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="ai_provider">
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="gemini" <?= $aiProvider === 'gemini' ? 'checked' : '' ?>> Gemini</label>
    <label>Gemini API Key
      <input type="password" name="gemini_api_key" value="<?= h($geminiKey ?? '') ?>" placeholder="AIza..." autocomplete="off">
    </label>
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="claude" <?= $aiProvider === 'claude' ? 'checked' : '' ?>> Claude</label>
    <label>Claude API Key
      <input type="password" name="claude_api_key" value="<?= h($claudeKey ?? '') ?>" placeholder="sk-ant-..." autocomplete="off">
    </label>
    <label class="checkbox-row"><input type="radio" name="ai_provider" value="openai" <?= $aiProvider === 'openai' ? 'checked' : '' ?>> OpenAI</label>
    <label>OpenAI API Key
      <input type="password" name="openai_api_key" value="<?= h($openaiKey ?? '') ?>" placeholder="sk-..." autocomplete="off">
    </label>
    <button type="submit" class="btn-primary">Save AI Provider</button>
  </form>
</section>

<section class="card">
  <h2>Brand Brief &amp; Self Brief</h2>
  <p class="muted">Brand Brief covers company-related posts (services, case studies, industry expertise). Self Brief is the personal-voice counterpart used for personal content pillars (achievements, opinions, life events) — see the Company/Personal tag on each pillar below. Both are automatically added as context to every AI generation call, so you don't have to retype them each time.</p>
  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="brand_brief">
    <label>Brand Brief
      <textarea name="brand_brief" rows="4" placeholder="e.g. We sell predictive-maintenance sensors to mid-size manufacturing plants. Voice: direct, data-driven, not salesy."><?= h($brandBrief ?? '') ?></textarea>
    </label>
    <label>Self Brief
      <textarea name="self_brief" rows="4" placeholder="e.g. I'm a reliability engineer turned founder. Voice: candid, a bit informal, share real lessons not just wins."><?= h($selfBrief ?? '') ?></textarea>
    </label>
    <button type="submit" class="btn-primary">Save Briefs</button>
  </form>
</section>

<section class="card">
  <h2>Personas, Pillars &amp; CTAs</h2>
  <p class="muted">Every new account starts with a generic starter set of personas, content pillars, and CTAs (editable/removable below). If you deleted them or never got them, load them again any time — this only adds what's missing, it never duplicates.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="seed_kb">
    <button type="submit" class="btn-secondary">Load Starter Content</button>
  </form>
</section>

<section class="card">
  <h2>Personas</h2>
  <p class="muted">Target audiences you write for. Pick one from New Post's "Generate with AI" panel instead of retyping who the post is for.</p>
  <?php if ($personas): ?>
    <?php foreach ($personas as $p): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($p['name']) ?></span>
          <span class="muted"><?= h(mb_strimwidth($p['description'] ?? '', 0, 80, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this persona?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="persona_delete">
          <input type="hidden" name="persona_id" value="<?= (int) $p['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No personas added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="persona_add">
    <label>Name
      <input type="text" name="persona_name" placeholder="e.g. Plant Reliability Manager" required>
    </label>
    <label>Description <span class="muted">(optional — pain points, goals, what they care about)</span>
      <textarea name="persona_description" rows="2"></textarea>
    </label>
    <button type="submit" class="btn-secondary">Add Persona</button>
  </form>
</section>

<section class="card">
  <h2>Content Pillars</h2>
  <p class="muted">The recurring themes you post about. Pick one from New Post's "Generate with AI" panel to keep content on-strategy.</p>
  <?php if ($contentPillars): ?>
    <?php foreach ($contentPillars as $cp): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($cp['name']) ?></span>
          <span class="badge <?= $cp['category'] === 'personal' ? 'badge-format' : 'badge-active' ?>"><?= $cp['category'] === 'personal' ? 'Personal' : 'Company' ?></span>
          <span class="muted"><?= h(mb_strimwidth($cp['description'] ?? '', 0, 80, '…')) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this content pillar?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="pillar_delete">
          <input type="hidden" name="pillar_id" value="<?= (int) $cp['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No content pillars added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="pillar_add">
    <label>Name
      <input type="text" name="pillar_name" placeholder="e.g. Case Studies" required>
    </label>
    <label>Category
      <select name="pillar_category">
        <option value="company">Company</option>
        <option value="personal">Personal</option>
      </select>
    </label>
    <label>Description <span class="muted">(optional)</span>
      <textarea name="pillar_description" rows="2"></textarea>
    </label>
    <button type="submit" class="btn-secondary">Add Content Pillar</button>
  </form>
</section>

<section class="card">
  <h2>CTA Library</h2>
  <p class="muted">Reusable calls-to-action, optionally tagged with a funnel stage. Pick one from New Post's "Generate with AI" panel instead of writing a CTA from scratch each time.</p>
  <?php if ($ctaLibrary): ?>
    <?php foreach ($ctaLibrary as $cta): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($cta['text']) ?></span>
          <?php if ($cta['funnel_stage']): ?><span class="badge badge-format"><?= h($cta['funnel_stage']) ?></span><?php endif; ?>
        </div>
        <form method="post" onsubmit="return confirm('Remove this CTA?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="cta_delete">
          <input type="hidden" name="cta_id" value="<?= (int) $cta['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No CTAs added yet.</p>
  <?php endif; ?>
  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="cta_add">
    <label>CTA Text
      <input type="text" name="cta_text" placeholder="e.g. Book a call with our team" required>
    </label>
    <label>Funnel Stage <span class="muted">(optional)</span>
      <select name="cta_funnel_stage">
        <option value="">— None —</option>
        <?php foreach ($funnelStages as $stage): ?>
          <option value="<?= h($stage) ?>"><?= h($stage) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-secondary">Add CTA</button>
  </form>
</section>

<section class="card">
  <h2>Tag Directory</h2>
  <p class="muted">LinkedIn only lets an app look up pages you administer — there's no way to search other companies by name. To tag a page you don't manage, find its numeric LinkedIn organization ID (visible in that page's public HTML source, e.g. as "urn:li:organization:12345") and add it here once. It'll then show up in the "@ Tag" button in the caption editor for every future post.</p>

  <?php if ($tagDirectory): ?>
    <?php foreach ($tagDirectory as $entry): ?>
      <div class="account-row">
        <div class="account-info">
          <span><?= h($entry['display_name']) ?></span>
          <span class="muted"><?= h($entry['target_urn']) ?></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove this from your tag directory?');">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="form" value="tag_delete">
          <input type="hidden" name="tag_id" value="<?= (int) $entry['id'] ?>">
          <button type="submit" class="btn-tiny btn-danger">Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No custom tags added yet.</p>
  <?php endif; ?>

  <form method="post" class="stacked-form" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <input type="hidden" name="form" value="tag_add">
    <label>Display Name
      <input type="text" name="tag_name" placeholder="e.g. Acme Corp" required>
    </label>
    <label>LinkedIn Organization ID <span class="muted">(number, URN, or /company/&lt;number&gt;/ URL)</span>
      <input type="text" name="tag_org_id" placeholder="e.g. 12345 or urn:li:organization:12345" required>
    </label>
    <button type="submit" class="btn-secondary">Add to Directory</button>
  </form>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
