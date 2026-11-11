<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointments-data.php';

api_guard();

function avail_out(array $res, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($res);
    exit;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        avail_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }

    $type   = (string)($_POST['type'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');

    $rules = [
        'room'      => ['table' => 'rooms',      'column' => 'room_status',         'allowed' => ['available', 'cleaning', 'maintenance']],
        'therapist' => ['table' => 'therapists', 'column' => 'availability_status', 'allowed' => ['available', 'on_leave']],
    ];

    if (!isset($rules[$type]) || $id < 1 || !in_array($status, $rules[$type]['allowed'], true)) {
        avail_out(['ok' => false, 'error' => 'Invalid availability update.'], 422);
    }

    try {
        $rule = $rules[$type];
        $stmt = $pdo->prepare('UPDATE ' . $rule['table'] . ' SET ' . $rule['column'] . ' = ? WHERE id = ?');
        $stmt->execute([$status, $id]);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $rule['table'] . ' WHERE id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() === 0) {
            avail_out(['ok' => false, 'error' => 'That record no longer exists.'], 404);
        }
    } catch (Throwable $e) {
        avail_out(['ok' => false, 'error' => 'Unable to update availability right now.'], 500);
    }

    avail_out(['ok' => true, 'message' => ucfirst($type) . ' availability updated.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    avail_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$date = appt_parse_date((string)($_GET['date'] ?? '')) ?? date('Y-m-d');

try {
    $rooms      = $pdo->query('SELECT id, room_name, room_type, room_status FROM rooms ORDER BY id ASC')->fetchAll();
    $therapists = $pdo->query('SELECT id, full_name, specialization, availability_status FROM therapists ORDER BY id ASC')->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT a.room_id, a.therapist_id, a.start_time, a.end_time, c.full_name AS customer_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date = ? AND a.appointment_status NOT IN ('cancelled', 'no_show')
         ORDER BY a.start_time ASC"
    );
    $stmt->execute([$date]);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {
    avail_out(['ok' => false, 'error' => 'Unable to load availability right now.'], 500);
}

$slotsFor = static function (string $field, int $id) use ($bookings): array {
    $out = [];
    foreach ($bookings as $b) {
        if ((int)$b[$field] === $id) {
            $out[] = [
                'time'     => appt_time_label((string)$b['start_time']) . ' – ' . appt_time_label((string)$b['end_time']),
                'customer' => (string)($b['customer_name'] ?? ''),
            ];
        }
    }
    return $out;
};

$roomOut = [];
foreach ($rooms as $r) {
    $roomOut[] = [
        'id'     => (int)$r['id'],
        'name'   => (string)$r['room_name'],
        'type'   => (string)$r['room_type'],
        'status' => (string)$r['room_status'],
        'slots'  => $slotsFor('room_id', (int)$r['id']),
    ];
}

$therapistOut = [];
foreach ($therapists as $t) {
    $therapistOut[] = [
        'id'     => (int)$t['id'],
        'name'   => (string)$t['full_name'],
        'type'   => (string)$t['specialization'],
        'status' => (string)$t['availability_status'],
        'slots'  => $slotsFor('therapist_id', (int)$t['id']),
    ];
}

avail_out([
    'ok'         => true,
    'date'       => $date,
    'date_label' => date('l, F j, Y', strtotime($date)),
    'rooms'      => $roomOut,
    'therapists' => $therapistOut,
]);