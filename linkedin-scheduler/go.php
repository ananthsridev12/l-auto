<?php
// Public, unauthenticated short-link redirect for Link Tracking
// (Knowledge Hub > "Link Tracking" tab, includes/link_tracking.php).
// Deliberately loads only db.php, not the full auth.php/session stack —
// this is a hot path hit by anyone who clicks a tracked link, not the
// account owner. The destination is never attacker-supplied at click
// time (only ?s={slug} is read; target_url was pinned by an
// authenticated user when the link was created), so this can't be used
// as an open redirector for an arbitrary URL.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/link_tracking.php';

$slug = trim((string) ($_GET['s'] ?? ''));
$target = $slug !== '' ? record_link_click($slug) : null;

if ($target === null) {
    http_response_code(404);
    echo 'This link is no longer valid.';
    exit;
}

header('Location: ' . $target, true, 302);
exit;
