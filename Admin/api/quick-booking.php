<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

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

if ($customerId < 1 || $serviceId < 1 || $therapistId < 1 || $roomId < 1) {
    booking_fail('Select a guest, treatment, therapist and suite.');
}

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    booking_fail('Enter a valid appointment date.');
}
if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) {
    booking_fail('Enter a valid start time.');
}
$time = $tm[1] . ':' . $tm[2] . ':00';

$today = date('Y-m-d');
if ($date < $today) {
    booking_fail('The appointment date cannot be in the past.');
}
if ($date === $today && strtotime($date . ' ' . $time) <= time()) {
    booking_fail('The start time must be later than the current time.');
}

$pdo = db();

try {
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    if (!$stmt->fetch()) {
        booking_fail('The selected guest no longer exists.');
    }

    $stmt = $pdo->prepare('SELECT id, duration, price FROM services WHERE id = ?');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    if (!$service) {
        booking_fail('The selected treatment no longer exists.');
    }

    $stmt = $pdo->prepare('SELECT availability_status FROM therapists WHERE id = ?');
    $stmt->execute([$therapistId]);
    $therapist = $stmt->fetch();
    if (!$therapist) {
        booking_fail('The selected therapist no longer exists.');
    }
    if ($therapist['availability_status'] === 'on_leave') {
        booking_fail('The selected therapist is on leave.');
    }

    $stmt = $pdo->prepare('SELECT room_status FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        booking_fail('The selected suite no longer exists.');
    }
    if ($room['room_status'] === 'maintenance') {
        booking_fail('The selected suite is under maintenance.');
    }
} catch (PDOException $e) {
    booking_fail('Unable to validate the booking right now.', 500);
}

$startDt = new DateTime($date . ' ' . $time);
$endDt   = (clone $startDt)->modify('+' . (int)$service['duration'] . ' minutes');
if ($endDt->format('Y-m-d') !== $date) {
    booking_fail('The session must finish before midnight.');
}
$endTime = $endDt->format('H:i:s');

$error = null;
$newId = 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT room_id, therapist_id FROM appointments
         WHERE appointment_date = ?
           AND appointment_status NOT IN ('cancelled', 'no_show')
           AND (room_id = ? OR therapist_id = ?)
           AND start_time < ? AND end_time > ?
         FOR UPDATE"
    );
    $stmt->execute([$date, $roomId, $therapistId, $endTime, $time]);

    foreach ($stmt->fetchAll() as $c) {
        if ((int)$c['room_id'] === $roomId) {
            $error = 'The selected suite is already booked during that time.';
            break;
        }
        if ((int)$c['therapist_id'] === $therapistId) {
            $error = 'The selected therapist is already booked during that time.';
            break;
        }
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO appointments
             (customer_id, service_id, room_id, therapist_id, appointment_date, start_time, end_time, appointment_status, payment_status, total_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?)"
        );
        $stmt->execute([$customerId, $serviceId, $roomId, $therapistId, $date, $time, $endTime, $service['price']]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    booking_fail('Unable to save the booking right now.', 500);
}

if ($error !== null) {
    booking_fail($error);
}

echo json_encode([
    'ok'      => true,
    'id'      => $newId,
    'message' => 'Booking created and marked as pending confirmation.',
]);