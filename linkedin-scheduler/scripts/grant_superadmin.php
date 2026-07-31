<?php
// One-off CLI to bootstrap the site's first (or Nth) superadmin.
// is_superadmin is deliberately never settable through any UI form —
// this script (run directly by the site owner, who already has shell/DB
// access) is the only way to grant it.
//
// Usage: php scripts/grant_superadmin.php <email>
//        php scripts/grant_superadmin.php <email> --revoke

require_once __DIR__ . '/../includes/db.php';

$email = trim(strtolower($argv[1] ?? ''));
$revoke = in_array('--revoke', $argv, true);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/grant_superadmin.php <email> [--revoke]\n");
    exit(1);
}

$stmt = db()->prepare('SELECT id, name FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) {
    fwrite(STDERR, "No user found with email {$email}\n");
    exit(1);
}

db()->prepare('UPDATE users SET is_superadmin = ? WHERE id = ?')->execute([$revoke ? 0 : 1, $user['id']]);

echo ($revoke ? 'Revoked' : 'Granted') . " superadmin for {$email} (#{$user['id']}, {$user['name']})\n";
