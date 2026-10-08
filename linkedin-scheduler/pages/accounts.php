<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash('error', 'Your session expired, please try again.');
        redirect('pages/accounts.php');
    }
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'revoke') {
        $stmt = db()->prepare('UPDATE linkedin_accounts SET status = "revoked" WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        flash('success', 'Account removed.');
    } elseif ($action === 'rename') {
        $name = trim($_POST['display_name'] ?? '');
        if ($name !== '') {
            $stmt = db()->prepare('UPDATE linkedin_accounts SET display_name = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$name, $id, $userId]);
            flash('success', 'Nickname updated.');
        }
    } elseif ($action === 'social_revoke') {
        $stmt = db()->prepare('UPDATE social_accounts SET status = "revoked" WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        flash('success', 'Account removed.');
    } elseif ($action === 'social_rename') {
        $name = trim($_POST['display_name'] ?? '');
        if ($name !== '') {
            $stmt = db()->prepare('UPDATE social_accounts SET display_name = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$name, $id, $userId]);
            flash('success', 'Nickname updated.');
        }
    } elseif ($action === 'mcp_revoke') {
        $stmt = db()->prepare('DELETE FROM mcp_tokens WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        flash('success', 'Disconnected.');
    }
    redirect('pages/accounts.php');
}

$stmt = db()->prepare('SELECT * FROM linkedin_accounts WHERE user_id = ? AND status != "revoked" ORDER BY account_type, display_name');
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();
$personal = array_values(array_filter($accounts, fn ($a) => $a['account_type'] === 'personal'));
$company  = array_values(array_filter($accounts, fn ($a) => $a['account_type'] === 'company'));

$socialStmt = db()->prepare('SELECT * FROM social_accounts WHERE user_id = ? AND status != "revoked" ORDER BY display_name');
$socialStmt->execute([$userId]);
$socialAccounts = $socialStmt->fetchAll();
$facebookAccounts = array_values(array_filter($socialAccounts, fn ($a) => $a['platform'] === 'facebook'));
$instagramAccounts = array_values(array_filter($socialAccounts, fn ($a) => $a['platform'] === 'instagram'));
$pinterestAccounts = array_values(array_filter($socialAccounts, fn ($a) => $a['platform'] === 'pinterest'));
$gbpAccounts = array_values(array_filter($socialAccounts, fn ($a) => $a['platform'] === 'google_business'));

$mcpStmt = db()->prepare('SELECT * FROM mcp_tokens WHERE user_id = ? ORDER BY created_at DESC');
$mcpStmt->execute([$userId]);
$mcpTokens = $mcpStmt->fetchAll();

$pageTitle  = 'Connected Accounts';
$activePage = 'accounts';
$token = csrf_token();
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Connected Accounts</h1>
  <p class="subtitle">Your CSV import's "LinkedIn Page" column is matched against the nickname below — keep them in sync.</p>
</div>

<section class="card">
  <div class="card-header">
    <h2>Personal Profile</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/linkedin_start.php?type=personal')) ?>">
      <?= empty($personal) ? 'Connect Personal Profile' : 'Reconnect' ?>
    </a>
  </div>
  <?php if (empty($personal)): ?>
    <p class="muted">No personal profile connected yet.</p>
  <?php else: foreach ($personal as $acct): ?>
    <?php include __DIR__ . '/_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Company Pages</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/linkedin_start.php?type=company')) ?>">Add Company Page(s)</a>
  </div>
  <p class="muted">Requires your LinkedIn Developer App to be approved for the Advertising API or Community Management API product — if this fails, that approval is the usual reason.</p>
  <?php if (empty($company)): ?>
    <p class="muted">No Company Pages connected yet.</p>
  <?php else: foreach ($company as $acct): ?>
    <?php include __DIR__ . '/_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Facebook Pages</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/meta_start.php')) ?>">Connect Facebook</a>
  </div>
  <p class="muted">Requires Meta App Review before this works for anyone but the app's own developer/testers.</p>
  <?php if (empty($facebookAccounts)): ?>
    <p class="muted">No Facebook Pages connected yet.</p>
  <?php else: foreach ($facebookAccounts as $acct): ?>
    <?php include __DIR__ . '/_social_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Instagram</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/meta_start.php')) ?>">Connect Instagram</a>
  </div>
  <p class="muted">Uses the same Facebook connection above — an Instagram Business account must be linked to a Facebook Page you administer.</p>
  <?php if (empty($instagramAccounts)): ?>
    <p class="muted">No Instagram accounts connected yet.</p>
  <?php else: foreach ($instagramAccounts as $acct): ?>
    <?php include __DIR__ . '/_social_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Pinterest</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/pinterest_start.php')) ?>">Connect Pinterest</a>
  </div>
  <p class="muted">Connects individual boards — pick which ones during setup, and reconnect any time to add more.</p>
  <?php if (empty($pinterestAccounts)): ?>
    <p class="muted">No Pinterest boards connected yet.</p>
  <?php else: foreach ($pinterestAccounts as $acct): ?>
    <?php include __DIR__ . '/_social_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Google Business Profile</h2>
    <a class="btn-secondary" href="<?= h(app_path('auth/gbp_start.php')) ?>">Connect Google Business Profile</a>
  </div>
  <p class="muted">Requires a verified Business Profile active 60+ days and Google's manual API access approval before this works.</p>
  <?php if (empty($gbpAccounts)): ?>
    <p class="muted">No locations connected yet.</p>
  <?php else: foreach ($gbpAccounts as $acct): ?>
    <?php include __DIR__ . '/_social_account_row.php'; ?>
  <?php endforeach; endif; ?>
</section>

<section class="card">
  <div class="card-header">
    <h2>Claude / ChatGPT (MCP)</h2>
    <a class="btn-secondary" href="<?= h(app_path('mcp.php')) ?>">Connection info</a>
  </div>
  <p class="muted">Connect Claude or ChatGPT to <code><?= h(app_path('mcp.php')) ?></code> to manage your posts and schedule by chat. Requires signing in and allowing the connection there — nothing to set up here beyond revoking access below.</p>
  <p class="muted">Got an image or video too large to send through the chat itself? <a href="<?= h(app_path('pages/mcp_upload.php')) ?>">Upload it here</a> and give the assistant the link it returns instead.</p>
  <?php if (empty($mcpTokens)): ?>
    <p class="muted">No chat assistants connected yet.</p>
  <?php else: foreach ($mcpTokens as $t): ?>
    <div class="account-row">
      <div class="account-info">
        <span><?= h($t['client_name'] ?: 'Unnamed connection') ?></span>
        <span class="muted">Connected <?= h($t['created_at']) ?><?= $t['last_used_at'] ? ', last used ' . h($t['last_used_at']) : '' ?></span>
      </div>
      <form method="post" class="inline-form" onsubmit="return confirm('Disconnect this assistant? It will no longer be able to access your posts.');">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="mcp_revoke">
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button type="submit" class="btn-tiny btn-danger">Disconnect</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
