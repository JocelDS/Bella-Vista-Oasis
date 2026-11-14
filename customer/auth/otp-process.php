<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!csrf_verify()) {
        cu_flash('error', 'Your session expired. Please try again.');
        cu_redirect('/login.php');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cu_flash('error', 'Enter a valid email address to receive a sign-in link.');
        cu_redirect('/login.php');
    }
    if (cu_rate_count('magic', $email, 60) >= 3) {
        cu_flash('error', 'Too many requests. Please try again in an hour.');
        cu_redirect('/login.php');
    }
    cu_rate_hit('magic', $email);
    $st = db()->prepare("SELECT id FROM customers WHERE email = ? AND customer_status = 'active' LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row) {
        [$selector, $token] = cu_token_create((int)$row['id'], 'magic', CUSTOMER_MAGIC_MINUTES);
        $link = cu_absolute(cu_url('/auth/otp-process.php?s=' . $selector . '&t=' . $token));
        $dev = cu_deliver_link($email, 'Your Bella Vista Oasis sign-in link', 'Use the one-time link below to sign in. It expires in ' . CUSTOMER_MAGIC_MINUTES . ' minutes.', $link);
        if ($dev) $_SESSION['cu_dev_link'] = $dev;
    }
    cu_flash('success', 'If that email belongs to a guest profile, a one-time sign-in link is on its way.');
    cu_redirect('/login.php');
}

$selector = (string)($_GET['s'] ?? '');
$token = (string)($_GET['t'] ?? '');
$row = cu_token_find('magic', $selector, $token);
if (!$row) {
    cu_flash('error', 'This sign-in link is invalid or has expired.');
    cu_redirect('/login.php');
}
$st = db()->prepare("SELECT * FROM customers WHERE id = ? AND customer_status = 'active'");
$st->execute([$row['customer_id']]);
$customer = $st->fetch();
if (!$customer) {
    cu_flash('error', 'This sign-in link is invalid or has expired.');
    cu_redirect('/login.php');
}
cu_token_burn((int)$row['id']);
cu_start_session($customer);
cu_login_redirect();