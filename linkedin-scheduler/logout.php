<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

logout_user();
redirect('index.php');
