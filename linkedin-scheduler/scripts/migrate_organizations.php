<?php
// One-time migration for Organizations — run ONCE after applying
// migrations/0012_organizations.sql:
//   php scripts/migrate_organizations.php          (CLI)
// or open scripts/migrate_organizations.php in the browser while logged in.
//
// 1. Seeds the 3 starter plans (Free/Pro/Agency) if they don't exist yet
//    — placeholder limits, no payment gateway wired up (see includes/
//    organizations.php org_within_limit()). All modules enabled by
//    default on every plan; a superadmin trims per-organization from
//    there via includes/modules.php set_org_enabled_modules().
// 2. For every existing user with no organization_id: creates a
//    1-person organization on the Free plan (org_role stays the column
//    default 'owner') and assigns it — mirrors scripts/migrate_workspaces.php's
//    per-user backfill loop.
// Idempotent: users that already have an organization_id are untouched,
// and existing plans (matched by slug) are never duplicated.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/modules.php';

$pdo = db();

$plans = [
    ['slug' => 'free',   'name' => 'Free',   'max_users' => 1,    'max_workspaces' => 2,    'max_posts_per_month' => 30],
    ['slug' => 'pro',    'name' => 'Pro',    'max_users' => 10,   'max_workspaces' => 10,   'max_posts_per_month' => 200],
    ['slug' => 'agency', 'name' => 'Agency', 'max_users' => null, 'max_workspaces' => null, 'max_posts_per_month' => null],
];
$allModules = implode(',', MODULE_KEYS);
foreach ($plans as $plan) {
    $stmt = $pdo->prepare('SELECT id FROM plans WHERE slug = ?');
    $stmt->execute([$plan['slug']]);
    if ($stmt->fetchColumn()) {
        continue;
    }
    $pdo->prepare(
        'INSERT INTO plans (name, slug, max_users, max_workspaces, max_posts_per_month, default_modules) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$plan['name'], $plan['slug'], $plan['max_users'], $plan['max_workspaces'], $plan['max_posts_per_month'], $allModules]);
    echo "seeded plan: {$plan['name']}\n";
}

$freePlanId = (int) $pdo->query("SELECT id FROM plans WHERE slug = 'free' LIMIT 1")->fetchColumn();

$users = $pdo->query('SELECT id, name, email FROM users WHERE organization_id IS NULL')->fetchAll();
foreach ($users as $u) {
    $userId = (int) $u['id'];
    $orgName = trim((string) $u['name']) !== '' ? trim((string) $u['name']) : $u['email'];
    $pdo->prepare('INSERT INTO organizations (name, plan_id) VALUES (?, ?)')->execute([$orgName, $freePlanId]);
    $orgId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET organization_id = ?, org_role = 'owner' WHERE id = ?")->execute([$orgId, $userId]);
    echo "user {$userId} ({$u['email']}): created org #{$orgId}\n";
}

echo 'done — ' . count($users) . " user(s) backfilled\n";
