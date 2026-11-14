<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';
cu_redirect(cu_logged_in() ? '/dashboard.php' : '/login.php');
