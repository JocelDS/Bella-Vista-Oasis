<?php
declare(strict_types=1);
require_once __DIR__ . '/customer-session.php';

if (!cu_logged_in()) {
    cu_flash('error', 'Please sign in to continue.');
    cu_redirect('/login.php');
}

$st = db()->prepare("SELECT * FROM customers WHERE id = ? AND customer_status = 'active'");
$st->execute([$_SESSION['customer_id']]);
$currentCustomer = $st->fetch();
if (!$currentCustomer) {
    $_SESSION = [];
    session_destroy();
    cu_clear_remember_cookie();
    header('Location: ' . cu_url('/login.php'));
    exit;
}
