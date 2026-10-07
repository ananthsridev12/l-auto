<?php
// Minimal OAuth 2.1 + PKCE authorization server for the remote MCP
// connector (see includes/mcp_server.php, includes/mcp_tools.php, mcp.php).
// Scoped to exactly what Claude/ChatGPT's client registration flow needs —
// no refresh tokens, no scopes beyond a single "app" scope, no ID tokens.
// Requires includes/auth.php (db(), csrf_token()/csrf_check(),
// attempt_login(), current_user_id(), app_path(), h()) to already be
// loaded, same no-self-require convention as includes/modules.php.

define('OAUTH_ISSUER', rtrim(APP_URL, '/') . '/oauth');
define('OAUTH_CODE_TTL_SECONDS', 600);

class OAuthError extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}

function oauth_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oauth_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function oauth_error_json(OAuthError $e): void
{
    oauth_json_response(['error' => $e->errorCode, 'error_description' => $e->getMessage()], $e->httpStatus);
}

function oauth_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
    header('Access-Control-Expose-Headers: WWW-Authenticate');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// --- Discovery metadata -----------------------------------------------

function oauth_protected_resource_metadata(): array
{
    return [
        'resource'               => rtrim(APP_URL, '/') . '/mcp',
        'authorization_servers'  => [OAUTH_ISSUER],
        'bearer_methods_supported' => ['header'],
        'scopes_supported'        => ['app'],
    ];
}

function oauth_authorization_server_metadata(): array
{
    return [
        'issuer'                                => OAUTH_ISSUER,
        'authorization_endpoint'                => OAUTH_ISSUER . '/authorize',
        'token_endpoint'                         => OAUTH_ISSUER . '/token',
        'registration_endpoint'                  => OAUTH_ISSUER . '/register',
        'response_types_supported'               => ['code'],
        'grant_types_supported'                  => ['authorization_code'],
        'code_challenge_methods_supported'       => ['S256'],
        'token_endpoint_auth_methods_supported'  => ['none', 'client_secret_post', 'client_secret_basic'],
        'scopes_supported'                       => ['app'],
        'jwks_uri'                                => OAUTH_ISSUER . '/jwks',
        'subject_types_supported'                 => ['public'],
        'id_token_signing_alg_values_supported'   => ['RS256'],
    ];
}

// --- Client registry -----------------------------------------------

function oauth_client_secret_for(string $clientId): string
{
    return hash_hmac('sha256', 'oauth-client:' . $clientId, APP_SECRET);
}

function oauth_find_client(string $clientId): ?array
{
    $stmt = db()->prepare('SELECT * FROM mcp_oauth_clients WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        return null;
    }
    $client['redirect_uris'] = json_decode($client['redirect_uris'], true) ?: [];
    return $client;
}

function oauth_redirect_uri_allowed(string $uri): bool
{
    $parts = parse_url($uri);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['fragment'])) {
        return false;
    }
    if ($parts['scheme'] === 'https') {
        return true;
    }
    if ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)) {
        return true;
    }
    return false;
}

// Dynamic Client Registration (RFC 7591), minimal subset. Throws
// OAuthError on anything invalid.
function oauth_register_client(array $body): array
{
    $redirectUris = $body['redirect_uris'] ?? null;
    if (!is_array($redirectUris) || empty($redirectUris)) {
        throw new OAuthError('invalid_client_metadata', 'redirect_uris is required and must be a non-empty array.');
    }
    foreach ($redirectUris as $uri) {
        if (!is_string($uri) || !oauth_redirect_uri_allowed($uri)) {
            throw new OAuthError('invalid_client_metadata', "redirect_uri \"{$uri}\" must be https, or http only to localhost/127.0.0.1, with no #fragment.");
        }
    }
    $authMethod = $body['token_endpoint_auth_method'] ?? 'client_secret_basic';
    if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
        throw new OAuthError('invalid_client_metadata', "Unsupported token_endpoint_auth_method \"{$authMethod}\".");
    }

    $clientId = bin2hex(random_bytes(16));
    $clientName = is_string($body['client_name'] ?? null) ? trim($body['client_name']) : null;
    db()->prepare('INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, auth_method) VALUES (?, ?, ?, ?)')
        ->execute([$clientId, $clientName, json_encode($redirectUris), $authMethod]);

    $response = [
        'client_id'                   => $clientId,
        'client_name'                 => $clientName,
        'redirect_uris'               => $redirectUris,
        'token_endpoint_auth_method'  => $authMethod,
        'grant_types'                 => ['authorization_code'],
        'response_types'              => ['code'],
    ];
    if ($authMethod !== 'none') {
        $response['client_secret'] = oauth_client_secret_for($clientId);
        $response['client_secret_expires_at'] = 0;
    }
    return $response;
}

// --- Authorize (login + consent) -----------------------------------

const OAUTH_AUTHORIZE_PARAM_NAMES = ['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'];

// Validates the request enough to know whether it's even safe to show a
// consent page (a bad client_id/redirect_uri must never redirect the
// browser anywhere — it's an error page). Returns the validated client
// row; throws OAuthError (rendered as a page, not JSON, by the caller)
// otherwise.
function oauth_validate_authorize_request(array $params): array
{
    $clientId = $params['client_id'] ?? '';
    $client = $clientId !== '' ? oauth_find_client($clientId) : null;
    if (!$client) {
        throw new OAuthError('invalid_client', 'Unknown client_id. This connector may need to reconnect from scratch.');
    }
    $redirectUri = $params['redirect_uri'] ?? '';
    if (!in_array($redirectUri, $client['redirect_uris'], true)) {
        throw new OAuthError('invalid_request', 'redirect_uri does not exactly match one registered for this client.');
    }
    if (($params['response_type'] ?? '') !== 'code') {
        throw new OAuthError('unsupported_response_type', 'Only response_type=code is supported.');
    }
    if (empty($params['code_challenge']) || ($params['code_challenge_method'] ?? '') !== 'S256') {
        throw new OAuthError('invalid_request', 'A PKCE code_challenge with code_challenge_method=S256 is required.');
    }
    return $client;
}

function oauth_render_error_page(OAuthError $e): void
{
    http_response_code($e->httpStatus);
    $pageTitle = 'Connection error';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <h1>Couldn't connect</h1>
  <p class="subtitle"><?= h($e->getMessage()) ?></p>
  <a href="<?= h(app_path('pages/accounts.php')) ?>" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">Back to Accounts</a>
</div>
</body>
</html>
    <?php
    exit;
}

function oauth_render_consent_page(array $client, array $params, ?string $loginError = null): void
{
    $pageTitle = 'Connect ' . ($client['client_name'] ?: 'an app');
    $loggedIn = current_user_id() !== null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <meta http-equiv="X-Frame-Options" content="DENY">
  <meta http-equiv="Content-Security-Policy" content="frame-ancestors 'none'">
  <link rel="stylesheet" href="<?= h(app_path('assets/css/style.css')) ?>">
</head>
<body class="centered-page">
<div class="auth-card">
  <h1><?= h($client['client_name'] ?: 'An app') ?> wants to connect to PostPilot</h1>
  <p class="subtitle">It will be able to read and manage your posts, drafts, schedule, and connected accounts on your behalf.</p>

  <?php if ($loginError): ?><p class="badge badge-warning"><?= h($loginError) ?></p><?php endif; ?>

  <form method="post" class="stacked-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php foreach (OAUTH_AUTHORIZE_PARAM_NAMES as $name): ?>
      <input type="hidden" name="<?= h($name) ?>" value="<?= h($params[$name] ?? '') ?>">
    <?php endforeach; ?>

    <?php if (!$loggedIn): ?>
      <label>Email <input type="email" name="email" required autofocus></label>
      <label>Password <input type="password" name="password" required></label>
    <?php else: ?>
      <p class="muted">Signed in as <?= h(current_user()['email'] ?? '') ?></p>
    <?php endif; ?>

    <div class="button-row">
      <button type="submit" name="action" value="deny" class="btn-secondary">Deny</button>
      <button type="submit" name="action" value="allow" class="btn-primary">Allow</button>
    </div>
  </form>
</div>
</body>
</html>
    <?php
    exit;
}

function oauth_handle_authorize_get(array $params): void
{
    try {
        $client = oauth_validate_authorize_request($params);
    } catch (OAuthError $e) {
        oauth_render_error_page($e);
    }
    oauth_render_consent_page($client, $params);
}

function oauth_handle_authorize_post(array $post): void
{
    try {
        $client = oauth_validate_authorize_request($post);
    } catch (OAuthError $e) {
        oauth_render_error_page($e);
    }

    if (!csrf_check($post['csrf'] ?? null)) {
        oauth_render_error_page(new OAuthError('invalid_request', 'Your session expired — please try connecting again.'));
    }

    $redirectUri = $post['redirect_uri'];
    $state = $post['state'] ?? '';

    if (($post['action'] ?? '') === 'deny') {
        header('Location: ' . $redirectUri . '?' . http_build_query(['error' => 'access_denied', 'state' => $state, 'iss' => OAUTH_ISSUER]));
        exit;
    }

    if (!current_user_id()) {
        [$ok, $err] = attempt_login((string) ($post['email'] ?? ''), (string) ($post['password'] ?? ''));
        if (!$ok) {
            oauth_render_consent_page($client, $post, $err);
        }
    }

    $userId = current_user_id();
    $orgStmt = db()->prepare('SELECT organization_id FROM users WHERE id = ?');
    $orgStmt->execute([$userId]);
    $orgId = (int) $orgStmt->fetchColumn();
    if ($orgId && !in_array('mcp', org_enabled_modules($orgId), true)) {
        oauth_render_error_page(new OAuthError('access_denied', 'MCP Access is not enabled for your organization. Contact your organization admin.', 403));
    }

    $code = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + OAUTH_CODE_TTL_SECONDS);
    db()->prepare('INSERT INTO mcp_oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $code), $client['client_id'], $userId, $redirectUri, $post['code_challenge'], $expiresAt]);

    header('Location: ' . $redirectUri . '?' . http_build_query(['code' => $code, 'state' => $state, 'iss' => OAUTH_ISSUER]));
    exit;
}

// --- Token exchange ---------------------------------------------------

// The token endpoint must accept either form-encoded (the OAuth2 spec's
// own default) or JSON (what some MCP clients send anyway) bodies.
function oauth_read_token_request_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        return read_json_body();
    }
    return $_POST;
}

// Supports a client_secret presented in the body (client_secret_post) or
// as HTTP Basic (client_secret_basic); 'none' clients present neither.
function oauth_extract_client_credentials(array $body): array
{
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
    }
    return [(string) ($body['client_id'] ?? ''), (string) ($body['client_secret'] ?? '')];
}

function oauth_issue_token(array $body): array
{
    if (($body['grant_type'] ?? '') !== 'authorization_code') {
        throw new OAuthError('unsupported_grant_type', 'Only grant_type=authorization_code is supported.');
    }
    [$clientId, $clientSecret] = oauth_extract_client_credentials($body);
    $client = $clientId !== '' ? oauth_find_client($clientId) : null;
    if (!$client) {
        throw new OAuthError('invalid_client', 'Unknown client_id.', 401);
    }
    if ($client['auth_method'] !== 'none') {
        if (!hash_equals(oauth_client_secret_for($clientId), $clientSecret)) {
            throw new OAuthError('invalid_client', 'Invalid client_secret.', 401);
        }
    }

    $code = (string) ($body['code'] ?? '');
    $codeVerifier = (string) ($body['code_verifier'] ?? '');
    if ($code === '' || $codeVerifier === '') {
        throw new OAuthError('invalid_request', 'code and code_verifier are required.');
    }
    $codeHash = hash('sha256', $code);

    // Single-use: look up and delete in the same request, before any
    // further validation, so a code can never be redeemed twice even if
    // a later check in this function fails and the caller retries.
    $stmt = db()->prepare('SELECT * FROM mcp_oauth_codes WHERE code_hash = ?');
    $stmt->execute([$codeHash]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('DELETE FROM mcp_oauth_codes WHERE id = ?')->execute([$row['id']]);
    }
    if (!$row) {
        throw new OAuthError('invalid_grant', 'Unknown or already-used authorization code.');
    }
    if (strtotime($row['expires_at']) < time()) {
        throw new OAuthError('invalid_grant', 'This authorization code has expired. Please reconnect.');
    }
    if (!hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], (string) ($body['redirect_uri'] ?? ''))) {
        throw new OAuthError('invalid_grant', 'client_id or redirect_uri does not match the authorization request.');
    }
    $expectedChallenge = oauth_base64url_encode(hash('sha256', $codeVerifier, true));
    if (!hash_equals($row['code_challenge'], $expectedChallenge)) {
        throw new OAuthError('invalid_grant', 'code_verifier does not match the original code_challenge.');
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO mcp_tokens (user_id, token_hash, client_name) VALUES (?, ?, ?)')
        ->execute([$row['user_id'], hash('sha256', $token), $client['client_name']]);

    return ['access_token' => $token, 'token_type' => 'Bearer', 'scope' => 'app'];
}

// --- Bearer token resolution (used by mcp.php) -------------------------

function oauth_bearer_token_from_request(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);
    if (!$header || !str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return trim(substr($header, 7));
}

// Returns the resolved user_id, or null if the token is missing/invalid.
// Updates last_used_at on success.
function oauth_resolve_bearer_token(): ?int
{
    $token = oauth_bearer_token_from_request();
    if (!$token) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, user_id FROM mcp_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    db()->prepare('UPDATE mcp_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return (int) $row['user_id'];
}
