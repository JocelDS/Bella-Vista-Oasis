<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (!empty($_SESSION['admin_id'])) {
        db()->prepare('UPDATE administrators SET remember_token = NULL WHERE id = ?')->execute([(int)$_SESSION['admin_id']]);
    }
    clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

redirect('/auth/login.php');