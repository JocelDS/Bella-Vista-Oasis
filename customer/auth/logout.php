<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    cu_clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
    session_start();
    cu_flash('success', 'You have been signed out.');
}
cu_redirect('/login.php');
