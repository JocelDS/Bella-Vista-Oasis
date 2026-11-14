<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-auth-check.php';
require_once __DIR__ . '/../includes/booking-bridge.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/book-appointment.php');

$serviceId = (int)($_POST['service_id'] ?? 0);
$back = '/book-appointment.php' . ($serviceId > 0 ? '?service_id=' . $serviceId : '');

function booking_fail(string $message, string $back): never
{
    cu_set_old([
        'service_id'        => (string)($_POST['service_id'] ?? ''),
        'date'              => (string)($_POST['date'] ?? ''),
        'start'             => (string)($_POST['start'] ?? ''),
        'guests'            => (string)($_POST['guests'] ?? ''),
        'room_id'           => (string)($_POST['room_id'] ?? ''),
        'therapist_id'      => (string)($_POST['therapist_id'] ?? ''),
        'health_conditions' => (string)($_POST['health_conditions'] ?? ''),
        'health_injuries'   => (string)($_POST['health_injuries'] ?? ''),
        'is_pregnant'       => (string)($_POST['is_pregnant'] ?? ''),
    ]);
    cu_flash('error', $message);
    cu_redirect($back);
}

if (!csrf_verify()) booking_fail('Your session expired. Please try again.', $back);

/* One-time token: a double click or a refresh cannot create the same booking twice. */
$sent = (string)($_POST['request_token'] ?? '');
$held = (string)($_SESSION['bk_token'] ?? '');
unset($_SESSION['bk_token']);
if ($held === '' || !hash_equals($held, $sent)) {
    cu_flash('error', 'This booking form was already submitted. Check My Sanctuary before booking again.');
    cu_redirect('/dashboard.php');
}

if ($serviceId < 1) booking_fail('Choose a treatment first.', '/book-appointment.php');

$date = trim((string)($_POST['date'] ?? ''));
$start = trim((string)($_POST['start'] ?? ''));
$guestsRaw = trim((string)($_POST['guests'] ?? ''));

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) booking_fail('Choose a valid date.', $back);
if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $start, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) booking_fail('Choose a time slot.', $back);
$start = $tm[1] . ':' . $tm[2] . ':00';
if ($guestsRaw !== '' && !ctype_digit($guestsRaw)) booking_fail('Enter a valid number of guests.', $back);

$therapistRaw = trim((string)($_POST['therapist_id'] ?? ''));
$roomRaw = trim((string)($_POST['room_id'] ?? ''));
if (($therapistRaw !== '' && !ctype_digit($therapistRaw)) || ($roomRaw !== '' && !ctype_digit($roomRaw))) booking_fail('Choose a valid chamber and practitioner.', $back);

$health = bk_health($_POST);
if (!$health['ok']) booking_fail((string)$health['error'], $back);

/* All five declarations are mandatory and are never pre-accepted. */
foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $c) {
    if (($_POST[$c] ?? '') !== '1') booking_fail('Please accept every declaration in Step 4 to confirm your appointment.', $back);
}

$pdo = db();
cu_booking_ready($pdo);

$result = cu_pick_room_and_book($pdo, (int)$currentCustomer['id'], [
    'service_id' => $serviceId,
    'date' => $date,
    'start' => $start,
    'guests' => $guestsRaw === '' ? null : (int)$guestsRaw,
], $health, [
    'therapist_id' => $therapistRaw === '' ? 0 : (int)$therapistRaw,
    'room_id' => $roomRaw === '' ? 0 : (int)$roomRaw,
    'waiver' => true,
]);

if (!$result['ok']) booking_fail((string)$result['error'], $back);

cu_clear_old();
cu_redirect('/booking-confirmation.php?id=' . (int)$result['id']);