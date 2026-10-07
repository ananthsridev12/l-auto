<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/oauth_server.php';
require_once __DIR__ . '/includes/mcp_server.php';

oauth_cors_headers();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/event-stream') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '*/*', 'text/html')) {
    mcp_render_info_page();
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    exit;
}

$userId = oauth_resolve_bearer_token();
if (!$userId) {
    header('WWW-Authenticate: Bearer resource_metadata="' . rtrim(APP_URL, '/') . '/oauth/protected-resource"');
    oauth_json_response(['error' => 'invalid_token', 'error_description' => 'A valid bearer token is required.'], 401);
}

$orgStmt = db()->prepare('SELECT organization_id FROM users WHERE id = ?');
$orgStmt->execute([$userId]);
$orgId = (int) $orgStmt->fetchColumn();
if ($orgId && !in_array('mcp', org_enabled_modules($orgId), true)) {
    oauth_json_response(['error' => 'invalid_scope', 'error_description' => 'MCP Access is not enabled for this organization.'], 403);
}

$raw = file_get_contents('php://input');
$result = mcp_handle_request_body($raw, $userId);
http_response_code($result['status']);
if ($result['body'] !== null) {
    header('Content-Type: application/json');
    echo $result['body'];
}
exit;

// Browser-visible setup check — mirrors the recipe's /mcp info page:
// confirms app_url shape, that the new tables exist, and that the
// discovery URLs are actually reachable (not reset by this host's
// firewall) via a server-side curl probe.
function mcp_render_info_page(): void
{
    $checks = [];

    $appUrlOk = (bool) preg_match('#^https://[^/]+$#', rtrim(APP_URL, '/'));
    $checks[] = ['APP_URL is https with no path', $appUrlOk, APP_URL];

    try {
        $tableCount = (int) db()->query("SHOW TABLES LIKE 'mcp_tokens'")->rowCount();
        $checks[] = ['mcp_tokens table exists', $tableCount > 0, $tableCount > 0 ? 'OK' : 'Run migrations/0030_mcp_oauth.sql'];
    } catch (Throwable $e) {
        $checks[] = ['mcp_tokens table exists', false, $e->getMessage()];
    }

    foreach ([
        'Protected resource metadata' => OAUTH_ISSUER . '/protected-resource',
        'Authorization server metadata' => OAUTH_ISSUER . '/.well-known/openid-configuration',
    ] as $label => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $checks[] = [$label . ' is reachable', $status === 200, $status === 200 ? $url : "HTTP {$status} fetching {$url}"];
    }

    $rootWellKnownOk = null;
    $ch = curl_init(rtrim(APP_URL, '/') . '/.well-known/oauth-protected-resource');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    curl_exec($ch);
    $rootStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $pageTitle = 'MCP Server — PostPilot';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card" style="max-width:640px;">
  <h1>PostPilot MCP Server</h1>
  <p class="subtitle">Connect Claude or ChatGPT to this URL to manage posts, drafts, and your schedule by chat.</p>
  <ul>
    <?php foreach ($checks as [$label, $ok, $detail]): ?>
      <li><?= $ok ? '✅' : '❌' ?> <?= h($label) ?> — <span class="muted"><?= h($detail) ?></span></li>
    <?php endforeach; ?>
    <li><?= $rootStatus === 200 ? '✅' : '⚠️' ?> Root <code>/.well-known/oauth-protected-resource</code> — <span class="muted"><?= $rootStatus === 200 ? 'reachable' : "HTTP {$rootStatus} (fine if this host's firewall resets root /.well-known/* — the /oauth-prefixed URLs above are what actually matters)" ?></span></li>
  </ul>
</div>
</body>
</html>
    <?php
}
