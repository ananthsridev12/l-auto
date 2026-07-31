<?php
// Organizations: teams that share a plan/module setting and can grant
// individual member users access to specific LinkedIn pages (workspaces)
// they don't personally own. Requires includes/db.php, includes/helpers.php,
// includes/workspace.php and includes/kb_seed.php to already be loaded —
// same no-self-require convention as includes/workspace.php.

// Unlike includes/modules.php's current_organization_id() (session-bound,
// request-cached — for page/nav rendering), this takes an explicit
// $userId and always hits the DB — for call sites like
// publish_post_now() where $userId is a passed-in parameter, not
// necessarily the session user.
function user_organization_id(int $userId): ?int
{
    $stmt = db()->prepare('SELECT organization_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $orgId = $stmt->fetchColumn();
    return $orgId ? (int) $orgId : null;
}

function fetch_organization(int $orgId): ?array
{
    $stmt = db()->prepare('SELECT * FROM organizations WHERE id = ?');
    $stmt->execute([$orgId]);
    return $stmt->fetch() ?: null;
}

// Every member of the org (owner/admin/member) — the roster shown in
// Settings > Organization and the superadmin panel.
function fetch_org_members(int $orgId): array
{
    $stmt = db()->prepare(
        "SELECT id, email, name, org_role, created_at FROM users WHERE organization_id = ?
         ORDER BY org_role = 'owner' DESC, org_role = 'admin' DESC, name"
    );
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function org_owner_count(int $orgId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE organization_id = ? AND org_role = 'owner'");
    $stmt->execute([$orgId]);
    return (int) $stmt->fetchColumn();
}

// Every workspace (LinkedIn page) created by any member of this org —
// what an owner/admin can grant page access to, regardless of which
// specific member originally created it. Explicit design decision:
// workspaces carry no organization_id of their own; an org's claim on a
// page is derived transitively through the creating user's
// organization_id, not stored redundantly.
function fetch_org_workspaces(int $orgId): array
{
    $stmt = db()->prepare(
        "SELECT w.* FROM workspaces w JOIN users u ON u.id = w.user_id
         WHERE u.organization_id = ? ORDER BY w.type = 'personal' DESC, w.name"
    );
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

// Workspace ids a user has been explicitly granted — does not include
// ones they own outright (ownership already implies full access).
function fetch_user_workspace_grants(int $userId): array
{
    $stmt = db()->prepare('SELECT workspace_id FROM workspace_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Caller (pages/settings.php) is responsible for checking $granterUserId
// is an owner/admin of the organization that owns $workspaceId before
// calling this — kept out of here to avoid a redundant round trip when
// the caller already has both rows in hand.
function grant_workspace_access(int $workspaceId, int $userId, int $granterUserId): void
{
    db()->prepare(
        'INSERT INTO workspace_members (workspace_id, user_id, granted_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by)'
    )->execute([$workspaceId, $userId, $granterUserId]);
}

function revoke_workspace_access(int $workspaceId, int $userId): void
{
    db()->prepare('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?')
        ->execute([$workspaceId, $userId]);
}

// ── Plan usage/limits ───────────────────────────────────────────────

function org_usage_count(int $orgId, string $metric): int
{
    switch ($metric) {
        case 'users':
            $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE organization_id = ?');
            $stmt->execute([$orgId]);
            return (int) $stmt->fetchColumn();
        case 'workspaces':
            $stmt = db()->prepare('SELECT COUNT(*) FROM workspaces w JOIN users u ON u.id = w.user_id WHERE u.organization_id = ?');
            $stmt->execute([$orgId]);
            return (int) $stmt->fetchColumn();
        case 'posts':
            // Published this calendar month — a usage metric for what's
            // actually gone out on LinkedIn, not everything sitting in
            // Drafts/scheduled.
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.user_id
                 WHERE u.organization_id = ? AND p.status = 'posted'
                 AND p.posted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );
            $stmt->execute([$orgId]);
            return (int) $stmt->fetchColumn();
        default:
            return 0;
    }
}

// Null = unlimited.
function org_plan_limit(int $orgId, string $metric): ?int
{
    $org = fetch_organization($orgId);
    if (!$org) {
        return null;
    }
    $stmt = db()->prepare('SELECT max_users, max_workspaces, max_posts_per_month FROM plans WHERE id = ?');
    $stmt->execute([$org['plan_id']]);
    $plan = $stmt->fetch();
    if (!$plan) {
        return null;
    }
    $column = ['users' => 'max_users', 'workspaces' => 'max_workspaces', 'posts' => 'max_posts_per_month'][$metric] ?? null;
    if (!$column || $plan[$column] === null) {
        return null;
    }
    return (int) $plan[$column];
}

function org_within_limit(int $orgId, string $metric): bool
{
    $limit = org_plan_limit($orgId, $metric);
    return $limit === null || org_usage_count($orgId, $metric) < $limit;
}

// ── Invites ─────────────────────────────────────────────────────────
// No email infrastructure exists in this app — "sending" an invite
// means generating a token/link the owner/admin copies and sends
// themselves. Real SMTP delivery is a fast-follow, not in this scope.

function fetch_org_invites(int $orgId): array
{
    $stmt = db()->prepare("SELECT * FROM organization_invites WHERE organization_id = ? AND status = 'pending' ORDER BY created_at DESC");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function fetch_invite_by_token(string $token): ?array
{
    $stmt = db()->prepare("SELECT * FROM organization_invites WHERE token = ? AND status = 'pending'");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
    if (!$invite) {
        return null;
    }
    if ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
        db()->prepare("UPDATE organization_invites SET status = 'expired' WHERE id = ?")->execute([$invite['id']]);
        return null;
    }
    return $invite;
}

// $workspaceIds is filtered down to only pages this org actually owns —
// an owner/admin can't grant access to a page outside their own org.
function create_invite(int $orgId, string $email, string $role, array $workspaceIds, int $invitedBy): array
{
    $email = trim(strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Enter a valid email address.', null];
    }
    if (!in_array($role, ['admin', 'member'], true)) {
        $role = 'member';
    }
    if (!org_within_limit($orgId, 'users')) {
        return [false, "This organization has reached its plan's user limit.", null];
    }

    $orgWorkspaceIds = array_column(fetch_org_workspaces($orgId), 'id');
    $workspaceIds = array_values(array_intersect(array_map('intval', $workspaceIds), $orgWorkspaceIds));

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO organization_invites (organization_id, email, token, role, workspace_ids, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    )->execute([$orgId, $email, $token, $role, $workspaceIds ? implode(',', $workspaceIds) : null, $invitedBy]);

    return [true, null, $token];
}

function revoke_invite(int $inviteId, int $orgId): void
{
    db()->prepare("UPDATE organization_invites SET status = 'revoked' WHERE id = ? AND organization_id = ? AND status = 'pending'")
        ->execute([$inviteId, $orgId]);
}

// Only supports brand-new accounts — an email that already has an
// account can't switch organizations this way, since their existing
// workspaces would silently become visible to the new org's owner/admin
// (an org's claim on a page is derived transitively through the
// creating user's organization_id — see fetch_org_workspaces()). An
// existing user can still be granted access to a specific page directly
// via grant_workspace_access(), independent of org membership.
function accept_invite_new_user(string $token, string $password, string $name): array
{
    $invite = fetch_invite_by_token($token);
    if (!$invite) {
        return [false, 'This invite link is invalid or has expired.'];
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$invite['email']]);
    if ($stmt->fetch()) {
        return [false, 'An account with this email already exists — ask your organization owner to grant your existing account access to the page(s) directly.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    if (!org_within_limit((int) $invite['organization_id'], 'users')) {
        return [false, "This organization has reached its plan's user limit — ask the owner to upgrade before accepting."];
    }

    db()->prepare('INSERT INTO users (email, password_hash, name, organization_id, org_role) VALUES (?, ?, ?, ?, ?)')
        ->execute([$invite['email'], password_hash($password, PASSWORD_DEFAULT), trim($name), $invite['organization_id'], $invite['role']]);
    $newUserId = (int) db()->lastInsertId();

    // Every user gets their own Personal workspace, same as a normal
    // signup — current_workspace_id()'s fallback relies on it existing.
    $wsId = create_workspace($newUserId, 'personal', 'Personal');
    seed_default_knowledge_base($newUserId, $wsId);

    if ($invite['workspace_ids']) {
        foreach (explode(',', $invite['workspace_ids']) as $grantWsId) {
            grant_workspace_access((int) $grantWsId, $newUserId, (int) $invite['invited_by']);
        }
    }

    db()->prepare("UPDATE organization_invites SET status = 'accepted', accepted_at = NOW() WHERE id = ?")
        ->execute([$invite['id']]);

    return [true, null];
}
