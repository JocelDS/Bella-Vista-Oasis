<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

const SUPPORT_CATEGORIES = ['General Inquiry', 'Reservation Assistance', 'Payment Concern', 'Membership Concern', 'QR Check-in Issue', 'Other'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/help-support.php');
if (!cu_logged_in()) {
    $_SESSION['cu_return'] = '/help-support.php';
    cu_flash('error', 'Please sign in to send an inquiry.');
    cu_redirect('/login.php');
}

$category = trim((string)($_POST['category'] ?? ''));
$apptRef = trim((string)($_POST['appointment_ref'] ?? ''));
$subject = trim(preg_replace('/\s+/', ' ', (string)($_POST['subject'] ?? '')));
$message = trim((string)($_POST['message'] ?? ''));
$token = (string)($_POST['request_token'] ?? '');
cu_set_old(['category' => $category, 'appointment_ref' => $apptRef, 'subject' => $subject, 'message' => $message]);

function sp_fail(string $msg, array $errors = []): never
{
    cu_set_errors($errors);
    cu_flash('error', $msg);
    cu_redirect('/help-support.php#inquiry');
}

if (!csrf_verify()) sp_fail('Your session expired. Please try again.');
if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) sp_fail('This form is no longer valid. Please try again.');

$errors = [];
if (!in_array($category, SUPPORT_CATEGORIES, true)) $errors['category'] = 'Choose an inquiry category.';
if (mb_strlen($subject) < 3 || mb_strlen($subject) > 150) $errors['subject'] = 'Enter a subject (3 to 150 characters).';
if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) $errors['message'] = 'Enter a message (10 to 2000 characters).';
$cid = (int)$_SESSION['customer_id'];
$apptId = null;
if ($apptRef !== '') {
    if (!preg_match('/^(?:BVO-)?0*(\d{1,9})$/i', $apptRef, $m)) {
        $errors['appointment_ref'] = 'Use a reference like BVO-0001, or leave this blank.';
    } else {
        $st = db()->prepare('SELECT id FROM appointments WHERE id = ? AND customer_id = ?');
        $st->execute([(int)$m[1], $cid]);
        $apptId = $st->fetchColumn() ?: null;
        if (!$apptId) $errors['appointment_ref'] = 'We could not find that reservation on your account.';
    }
}
if ($errors) sp_fail('Please correct the highlighted fields.', $errors);

if (cu_rate_count('support', (string)$cid, 60) >= 5) sp_fail('Too many inquiries in a short time. Please try again later.');

$pdo = db();
try {
    $dupe = $pdo->prepare('SELECT reference_number FROM support_inquiries WHERE request_token = ?');
    $dupe->execute([$token]);
    if ($ref = $dupe->fetchColumn()) {
        cu_clear_old();
        cu_flash('success', 'This inquiry was already received. Your reference is ' . $ref . '.');
        cu_redirect('/help-support.php#inquiry');
    }
    $ref = 'SUP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare("INSERT INTO support_inquiries (customer_id, reference_number, request_token, category, appointment_id, subject, message, inquiry_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')")
        ->execute([$cid, $ref, $token, $category, $apptId, $subject, $message]);
} catch (Throwable $e) {
    sp_fail('We could not save your inquiry right now. Please email or call the concierge instead.');
}
cu_rate_hit('support', (string)$cid);
cu_clear_old();
cu_flash('success', 'Thank you. Your inquiry has been received. Your reference number is ' . $ref . '. The concierge team will follow up using your account email.');
cu_redirect('/help-support.php#inquiry');