<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/kb_seed.php';
require_once __DIR__ . '/organizations.php';
require_once __DIR__ . '/modules.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function current_user(): ?array
{
    $id = current_user_id();
    if (!$id) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, email, name, created_at, organization_id, org_role, is_superadmin FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): void
{
    if (!current_user_id()) {
        header('Location: ' . app_path('index.php'));
        exit;
    }
}

// Superadmin is granted only via scripts/grant_superadmin.php, run
// directly by the site owner — is_superadmin is never settable through
// any user-facing form.
function require_superadmin(): void
{
    require_login();
    $user = current_user();
    if (!$user || !$user['is_superadmin']) {
        header('Location: ' . app_path('pages/today.php'));
        exit;
    }
}

function app_path(string $relative): string
{
    // Resolves a path relative to the app root regardless of which
    // subfolder (auth/, pages/, api/) the current script lives in.
    // Strips a trailing ".php" from the path portion only (leaves any
    // "?query=string" after it untouched) so every generated link/
    // redirect uses the clean, extensionless URL the root .htaccess
    // rewrites back to the real file — e.g. "pages/post.php?id=5"
    // becomes ".../pages/post?id=5".
    $relative = preg_replace('/\.php(?=$|\?)/', '', ltrim($relative, '/'), 1);
    return rtrim(APP_URL, '/') . '/' . $relative;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

function register_user(string $email, string $password, string $name): array
{
    $email = trim(strtolower($email));
    if ($email === '' || strlen($password) < 8) {
        return [false, 'Enter a valid email and a password of at least 8 characters.'];
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [false, 'An account with that email already exists.'];
    }
    $stmt = db()->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), trim($name)]);
    $newUserId = (int) db()->lastInsertId();

    // Every signup gets their own 1-person organization (org_role stays
    // the default 'owner') on the Free plan — every user belongs to
    // exactly one organization, so module/plan-limit lookups never need
    // a "user without an org" special case. Inviting teammates later
    // just adds more members to this same org; nothing here changes for
    // solo users.
    $freePlanId = (int) db()->query("SELECT id FROM plans WHERE slug = 'free' LIMIT 1")->fetchColumn();
    $orgName = trim($name) !== '' ? trim($name) : $email;
    db()->prepare('INSERT INTO organizations (name, plan_id) VALUES (?, ?)')->execute([$orgName, $freePlanId]);
    $orgId = (int) db()->lastInsertId();
    db()->prepare('UPDATE users SET organization_id = ? WHERE id = ?')->execute([$orgId, $newUserId]);

    // Every user gets their Personal workspace immediately; the default
    // knowledge base seeds into it (company pillars land in a company
    // workspace only once the user creates one).
    $wsId = create_workspace($newUserId, 'personal', 'Personal');
    seed_default_knowledge_base($newUserId, $wsId);
    return [true, null];
}

function attempt_login(string $email, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([trim(strtolower($email))]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [false, 'Invalid email or password.'];
    }
    login_user($user);
    return [true, null];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
