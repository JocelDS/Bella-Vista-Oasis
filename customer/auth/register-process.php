<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/register.php');

$name = trim(preg_replace('/\s+/', ' ', (string)($_POST['full_name'] ?? '')));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$phone = cu_normalize_phone((string)($_POST['phone'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');
cu_set_old(['full_name' => $name, 'email' => $email, 'phone' => $phone]);

function reg_fail(string $message, array $errors = []): never
{
    cu_set_errors($errors);
    cu_flash('error', $message);
    cu_redirect('/register.php');
}

if (!csrf_verify()) reg_fail('Your session expired. Please try again.');

$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 150) $errors['full_name'] = 'Enter your full name (2 to 150 characters).';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) $errors['email'] = 'Enter a valid email address.';
if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) $errors['phone'] = 'Enter a valid phone number.';
if (!password_is_strong($password)) $errors['password'] = 'Use at least 8 characters with upper case, lower case and a number.';
if ($password !== $confirm) $errors['confirm_password'] = 'Passcodes do not match.';
if ($errors) reg_fail('Please correct the highlighted fields.', $errors);

if (cu_rate_count('register', $email, 60) >= 5 || cu_rate_count('register', '*', 60) >= 20) {
    reg_fail('Too many registration attempts. Please try again later.');
}
cu_rate_hit('register', $email);
cu_rate_hit('register', '*');

$pdo = db();
$st = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
$st->execute([$email]);
if ($st->fetch()) {
    reg_fail('An account with this email already exists. Sign in, or use "Forgot Sanctuary Password" if you have not set a passcode yet.', ['email' => 'This email is already registered.']);
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO customers (full_name, email, phone, customer_status, registered_at, updated_at, password_hash) VALUES (?, ?, ?, 'active', ?, ?, ?)")
        ->execute([$name, $email, $phone, now(), now(), password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE customers SET membership_code = ? WHERE id = ?')->execute([sprintf('BV-%06d', $id), $id]);
    $pdo->prepare("INSERT INTO loyalty_transactions (transaction_code, customer_id, transaction_type, points, reference_type, reference_id, description, status) VALUES (?, ?, 'bonus', ?, 'welcome', ?, 'Welcome bonus for new guest profile', 'completed')")
        ->execute(['LT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), $id, CUSTOMER_WELCOME_POINTS, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    reg_fail('We could not create your profile right now. Please try again.');
}

$st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$st->execute([$id]);
cu_clear_old();
cu_start_session($st->fetch());
cu_flash('success', 'Welcome to Bella Vista Oasis. ' . CUSTOMER_WELCOME_POINTS . ' welcome points have been added to your ledger.');
cu_redirect('/dashboard.php');
