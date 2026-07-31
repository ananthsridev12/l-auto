<?php
// Module gating: which broad feature bundles an organization has
// access to. Mirrors the users.enabled_formats / get_enabled_formats()
// pattern in includes/helpers.php exactly — a CSV column, NULL-means-
// "use the default", checked both in page/nav rendering (hide/redirect)
// and independently at API/cron entry points as defense in depth.
// Requires includes/db.php and includes/helpers.php (flash()/redirect())
// to already be loaded — same no-self-require convention as
// includes/workspace.php.

const MODULE_KEYS = ['post_scheduling', 'ai_generation', 'content_studio', 'blog_studio', 'news_studio'];

const MODULE_LABELS = [
    'post_scheduling' => 'Post Scheduling',
    'ai_generation'   => 'AI Generation',
    'content_studio'  => 'Content Studio & Calendar',
    'blog_studio'     => 'Blog Studio',
    'news_studio'     => 'News Studio',
];

// A plan/org row that predates a MODULE_KEYS addition should behave as
// "everything on" rather than silently losing a module nobody
// explicitly disabled — same reasoning as DEFAULT_ENABLED_FORMATS.
const DEFAULT_ENABLED_MODULES = ['post_scheduling', 'ai_generation', 'content_studio', 'blog_studio', 'news_studio'];

// The current session user's organization, cached per-request. Every
// user is expected to have one (see register_user() and
// scripts/migrate_organizations.php) but this fails soft to null on
// stale/missing state rather than fatal — callers treat null as "no
// restriction" (see module_enabled()), the same resilience philosophy
// current_workspace_id() already uses for a stale workspace_id.
function current_organization_id(): ?int
{
    static $cache = [];
    $userId = current_user_id();
    if (!$userId) {
        return null;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $stmt = db()->prepare('SELECT organization_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $orgId = $stmt->fetchColumn();
    return $cache[$userId] = ($orgId ? (int) $orgId : null);
}

// org.enabled_modules ?? plan.default_modules, intersected against
// MODULE_KEYS and request-cached — this is checked on every nav render
// plus every gated page, so an unbounded number of calls per request is
// realistic (unlike get_enabled_formats(), which is normally called once
// per page).
function org_enabled_modules(int $orgId): array
{
    static $cache = [];
    if (isset($cache[$orgId])) {
        return $cache[$orgId];
    }
    $stmt = db()->prepare(
        'SELECT o.enabled_modules, p.default_modules
         FROM organizations o JOIN plans p ON p.id = o.plan_id
         WHERE o.id = ?'
    );
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $cache[$orgId] = DEFAULT_ENABLED_MODULES;
    }
    $raw = $row['enabled_modules'] !== null && $row['enabled_modules'] !== ''
        ? $row['enabled_modules']
        : $row['default_modules'];
    $selected = array_map('trim', explode(',', (string) $raw));
    return $cache[$orgId] = array_values(array_intersect(MODULE_KEYS, $selected));
}

function set_org_enabled_modules(int $orgId, ?array $modules): void
{
    $csv = $modules === null ? null : implode(',', array_values(array_intersect(MODULE_KEYS, $modules)));
    db()->prepare('UPDATE organizations SET enabled_modules = ? WHERE id = ?')->execute([$csv, $orgId]);
}

function module_enabled(string $key): bool
{
    $orgId = current_organization_id();
    if (!$orgId) {
        // No org resolved for this session — fail open rather than
        // locking the user out of the whole app over missing org
        // plumbing (shouldn't happen post-migration, but this is the
        // same "don't hard-fail on stale state" call current_workspace_id()
        // makes).
        return true;
    }
    return in_array($key, org_enabled_modules($orgId), true);
}

// Page-level guard, mirrors require_login()'s style. Settings stays
// reachable regardless (never itself gated) so a locked-out user always
// has somewhere to go and can see why.
function require_module(string $key): void
{
    if (!module_enabled($key)) {
        flash('error', (MODULE_LABELS[$key] ?? $key) . ' is not enabled for your organization. Contact your organization admin.');
        redirect('pages/settings.php');
    }
}
