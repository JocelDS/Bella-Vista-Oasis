<?php
declare(strict_types=1);

define('CUSTOMER_URL_SUFFIX', '/customer');
define('CUSTOMER_REMEMBER_COOKIE', 'bvo_customer_remember');
define('CUSTOMER_REMEMBER_DAYS', 30);
define('CUSTOMER_MAGIC_MINUTES', 15);
define('CUSTOMER_RESET_MINUTES', 60);
define('CUSTOMER_WELCOME_POINTS', 500);
define('CUSTOMER_LOGIN_MAX', 5);
define('CUSTOMER_LOGIN_WINDOW_MIN', 15);

$cuBase = '/bella-vista-oasis';
$cuHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    session_name('BVO_CUSTOMER_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cuBase . CUSTOMER_URL_SUFFIX,
        'secure'   => $cuHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../../Admin/config/database.php';
