<?php
// Sets the session's active workspace (the sidebar switcher in
// includes/layout_top.php posts here), then returns to the page the
// user was on.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    redirect('pages/today.php');
}

set_current_workspace(current_user_id(), (int) ($_POST['workspace_id'] ?? 0));

$back = $_POST['return_to'] ?? '';
// Only same-app relative paths — never redirect off-site.
if ($back === '' || !preg_match('#^[a-z0-9_/\.\-]+\.php(\?[^\s]*)?$#i', $back) || str_contains($back, '..')) {
    $back = 'pages/today.php';
}
redirect($back);
