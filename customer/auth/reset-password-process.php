<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/login.php');

$selector = (string)($_POST['s'] ?? '');
$token = (string)($_POST['t'] ?? '');
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');
$back = '/reset-password.php?s=' . urlencode($selector) . '&t=' . urlencode($token);

if (!csrf_verify()) {
    cu_flash('error', 'Your session expired. Please try again.');
    cu_redirect($back);
}
$row = cu_token_find('reset', $selector, $token);
if (!$row) {
    cu_flash('error', 'This reset link is invalid or has expired. Please request a new one.');
    cu_redirect('/forgot-password.php');
}
$errors = [];
if (!password_is_strong($password)) $errors['password'] = 'Use at least 8 characters with upper case, lower case and a number.';
if ($password !== $confirm) $errors['confirm_password'] = 'Passcodes do not match.';
if ($errors) {
    cu_set_errors($errors);
    cu_flash('error', 'Please correct the highlighted fields.');
    cu_redirect($back);
}

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('UPDATE customers SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), now(), $row['customer_id']]);
cu_token_burn((int)$row['id']);
$em = $pdo->prepare('SELECT email, membership_code FROM customers WHERE id = ?');
$em->execute([$row['customer_id']]);
$acct = $em->fetch();
$pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = ? AND purpose IN ('remember', 'reset', 'magic')")->execute([$row['customer_id']]);
$pdo->commit();
if ($acct) {
    if ($acct['email']) cu_rate_clear('login', strtolower((string)$acct['email']));
    if ($acct['membership_code']) cu_rate_clear('login', strtoupper((string)$acct['membership_code']));
}

cu_clear_old();
cu_flash('success', 'Your passcode has been updated. Please sign in.');
cu_redirect('/login.php');
