<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
redirect('pages/today.php');
