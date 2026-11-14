<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/login.php');

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$remember = isset($_POST['remember']);
cu_set_old(['identifier' => $identifier]);

function login_fail(string $message, array $errors = []): never
{
    cu_set_errors($errors);
    cu_flash('error', $message);
    cu_redirect('/login.php');
}

if (!csrf_verify()) login_fail('Your session expired. Please try again.');

$errors = [];
if ($identifier === '') $errors['identifier'] = 'Enter your email or Membership ID.';
if ($password === '') $errors['password'] = 'Enter your passcode.';
if ($errors) login_fail('Please complete both fields.', $errors);

$isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
$code = strtoupper(ltrim($identifier, '#'));
if (!$isEmail && !preg_match('/^BV-[A-Z0-9-]{3,16}$/', $code)) {
    login_fail('Enter a valid email address or Membership ID.', ['identifier' => 'Use your email or an ID like BV-000001.']);
}

$subject = $isEmail ? strtolower($identifier) : $code;
if (cu_rate_count('login', $subject, CUSTOMER_LOGIN_WINDOW_MIN) >= CUSTOMER_LOGIN_MAX) {
    login_fail('Too many sign-in attempts. Please wait ' . CUSTOMER_LOGIN_WINDOW_MIN . ' minutes and try again.');
}

$pdo = db();
if ($isEmail) {
    $st = $pdo->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
    $st->execute([strtolower($identifier)]);
} else {
    $st = $pdo->prepare('SELECT * FROM customers WHERE membership_code = ? LIMIT 1');
    $st->execute([$code]);
}
$customer = $st->fetch();

$dummy = '$2y$12$yeVpqsXeHDUx.LB9AunXquvOWK6NZkg0uaHVqsJFNMb0UZEScFQta';
$hash = ($customer && !empty($customer['password_hash'])) ? $customer['password_hash'] : $dummy;
$ok = password_verify($password, $hash) && $customer && !empty($customer['password_hash']) && $customer['customer_status'] === 'active';

if (!$ok) {
    cu_rate_hit('login', $subject);
    login_fail("We couldn't match those details. If you are a returning guest without a passcode yet, use \"Forgot Sanctuary Password\" to create one.");
}

cu_rate_clear('login', $subject);
if (password_needs_rehash($customer['password_hash'], PASSWORD_DEFAULT)) {
    $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $customer['id']]);
}
cu_clear_old();
cu_start_session($customer);
if ($remember) cu_set_remember_cookie((int)$customer['id']); else cu_clear_remember_cookie();
cu_redirect('/dashboard.php');
