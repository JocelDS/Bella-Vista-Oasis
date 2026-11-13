<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/booking-rules.php';

api_guard();

function booking_fail(string $message, int $code = 422): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booking_fail('Method not allowed.', 405);
}
if (!csrf_verify()) {
    booking_fail('Your session expired. Refresh the page and try again.', 419);
}

$customerId  = (int)($_POST['customer_id'] ?? 0);
$serviceId   = (int)($_POST['service_id'] ?? 0);
$therapistId = (int)($_POST['therapist_id'] ?? 0);
$roomId      = (int)($_POST['room_id'] ?? 0);
$date        = trim((string)($_POST['appointment_date'] ?? ''));
$time        = trim((string)($_POST['start_time'] ?? ''));
$guestsRaw   = trim((string)($_POST['guest_count'] ?? ''));

if ($customerId < 1 || $serviceId < 1 || $roomId < 1) {
    booking_fail('Select a guest, treatment and suite.');
}
if ($guestsRaw !== '' && !ctype_digit($guestsRaw)) {
    booking_fail('Enter a valid number of guests.');
}

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    booking_fail('Enter a valid appointment date.');
}
if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) {
    booking_fail('Enter a valid start time.');
}
$time = $tm[1] . ':' . $tm[2] . ':00';

$health = bk_health($_POST);
if (!$health['ok']) {
    booking_fail((string)$health['error']);
}

$pdo = db();
$newId = 0;
$error = null;
$total = 0.0;

try {
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    if (!$stmt->fetch()) {
        booking_fail('The selected guest no longer exists.');
    }

    $pdo->beginTransaction();

    $v = bk_validate($pdo, [
        'service_id'   => $serviceId,
        'room_id'      => $roomId,
        'therapist_id' => $therapistId,
        'date'         => $date,
        'start'        => $time,
        'guests'       => $guestsRaw === '' ? null : (int)$guestsRaw,
    ], 0, true, true);

    if (!$v['ok']) {
        $error = (string)$v['error'];
        $pdo->rollBack();
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO appointments
             (customer_id, service_id, room_id, therapist_id, appointment_date, start_time, end_time, appointment_status, payment_status, total_amount, guest_count, extra_person_fee, health_conditions, health_injuries, is_pregnant)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $customerId, $serviceId, $roomId, $v['therapist_id'] > 0 ? $v['therapist_id'] : null,
            $date, $v['start'], $v['end'], $v['total'], $v['guests'], $v['extra'],
            $health['conditions'], $health['injuries'], $health['pregnant'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        $total = (float)$v['total'];
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[BVO quick-booking] ' . get_class($e) . ': ' . $e->getMessage());
    booking_fail('Unable to save the booking right now.', 500);
}

if ($error !== null) {
    booking_fail($error);
}

echo json_encode([
    'ok'      => true,
    'id'      => $newId,
    'message' => 'Booking created (total ' . peso($total) . ') and marked as pending confirmation.',
]);