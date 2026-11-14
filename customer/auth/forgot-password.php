<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/forgot-password.php');

$email = strtolower(trim((string)($_POST['email'] ?? '')));
cu_set_old(['email' => $email]);

if (!csrf_verify()) {
    cu_flash('error', 'Your session expired. Please try again.');
    cu_redirect('/forgot-password.php');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cu_set_errors(['email' => 'Enter a valid email address.']);
    cu_flash('error', 'Enter a valid email address.');
    cu_redirect('/forgot-password.php');
}
if (cu_rate_count('reset', $email, 60) >= 3) {
    cu_flash('error', 'Too many requests. Please try again in an hour.');
    cu_redirect('/forgot-password.php');
}
cu_rate_hit('reset', $email);

$st = db()->prepare("SELECT id FROM customers WHERE email = ? AND customer_status = 'active' LIMIT 1");
$st->execute([$email]);
$row = $st->fetch();
if ($row) {
    [$selector, $token] = cu_token_create((int)$row['id'], 'reset', CUSTOMER_RESET_MINUTES);
    $link = cu_absolute(cu_url('/reset-password.php?s=' . $selector . '&t=' . $token));
    $dev = cu_deliver_link($email, 'Reset your Bella Vista Oasis passcode', 'Use the link below to set a new Sanctuary Passcode. It expires in ' . CUSTOMER_RESET_MINUTES . ' minutes.', $link);
    if ($dev) $_SESSION['cu_dev_link'] = $dev;
}
cu_clear_old();
cu_flash('success', 'If that email belongs to a guest profile, a reset link is on its way.');
cu_redirect('/forgot-password.php');
