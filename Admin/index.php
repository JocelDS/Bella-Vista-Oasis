<?php
require_once __DIR__ . '/config/database.php';
redirect(restore_from_cookie() ? '/dashboard.php' : '/auth/login.php');
 