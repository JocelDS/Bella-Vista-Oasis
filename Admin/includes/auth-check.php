<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

if (!restore_from_cookie()) {
    redirect('/auth/login.php');
}

// Confirm the admin still exists and is active
$stmt = db()->prepare("SELECT id FROM administrators WHERE id = ? AND status = 'active'");
$stmt->execute([$_SESSION['admin_id']]);
if (!$stmt->fetch()) {
    $_SESSION = [];
    session_destroy();
    clear_remember_cookie();
    redirect('/auth/login.php');
}