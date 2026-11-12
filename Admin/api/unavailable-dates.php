<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

api_guard();

function bvo_range_dates(string $start, string $end): array
{
    $begin = new DateTimeImmutable($start);
    $finish = new DateTimeImmutable($end);
    $dates = [];
    $cursor = $begin;
    while ($cursor <= $finish) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

function bvo_room_has_slot(PDO $pdo, int $roomId, string $date, int $durationMinutes, int $gapMinutes): bool
{
    $dayStart = strtotime($date . ' 13:00:00');
    $dayEnd = strtotime($date . ' 21:00:00');
    if ($dayStart === false || $dayEnd === false) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT start_time, end_time FROM appointments
         WHERE appointment_date = ?
           AND room_id = ?
           AND appointment_status NOT IN (\'cancelled\', \'no_show\')
         ORDER BY start_time ASC'
    );
    $stmt->execute([$date, $roomId]);
    $bookings = $stmt->fetchAll();

    $slot = $dayStart;
    while ($slot + ($durationMinutes * 60) <= $dayEnd) {
        $start = date('H:i:s', $slot);
        $end = date('H:i:s', $slot + ($durationMinutes * 60));
        $newStart = strtotime($date . ' ' . $start);
        $newEnd = strtotime($date . ' ' . $end);
        if ($newStart === false || $newEnd === false) {
            $slot += 1800;
            continue;
        }

        $isFree = true;
        foreach ($bookings as $booking) {
            $existingStart = strtotime($date . ' ' . $booking['start_time']);
            $existingEnd = strtotime($date . ' ' . $booking['end_time']);
            if ($existingStart === false || $existingEnd === false) {
                continue;
            }
            $sameWindow = ($newStart < ($existingEnd + $gapMinutes * 60)) && ($existingStart < ($newEnd + $gapMinutes * 60));
            if ($sameWindow) {
                $isFree = false;
                break;
            }
        }

        if ($isFree) {
            return true;
        }
        $slot += 1800;
    }

    return false;
}

function bvo_unavailable_reason(PDO $pdo, string $date): ?string
{
    $today = date('Y-m-d');
    if ($date < $today) {
        return 'past';
    }

    $dayNum = (int)date('N', strtotime($date));
    $stmt = $pdo->prepare('SELECT is_open, opening_time, closing_time FROM spa_operating_hours WHERE day_of_week = ?');
    $stmt->execute([$dayNum]);
    $hours = $stmt->fetch();
    if (!$hours || !(int)$hours['is_open']) {
        return 'closed day';
    }

    $blockedStmt = $pdo->prepare('SELECT reason FROM blocked_dates WHERE blocked_date = ? LIMIT 1');
    $blockedStmt->execute([$date]);
    $blocked = $blockedStmt->fetch();
    if ($blocked) {
        return 'blocked date: ' . trim((string)($blocked['reason'] ?: 'Staff closure'));
    }

    $roomsStmt = $pdo->query("SELECT id, room_status FROM rooms ORDER BY id ASC");
    $rooms = $roomsStmt->fetchAll();
    if (!$rooms) {
        return 'all rooms under maintenance';
    }

    $maintenanceCount = 0;
    foreach ($rooms as $room) {
        if ((string)$room['room_status'] === 'maintenance') {
            $maintenanceCount++;
        }
    }
    if ($maintenanceCount === count($rooms)) {
        return 'all rooms under maintenance';
    }

    $durationStmt = $pdo->query('SELECT MIN(duration) AS min_duration FROM services');
    $duration = (int)($durationStmt->fetchColumn() ?: 60);
    $gap = 30;
    $found = false;
    foreach ($rooms as $room) {
        if ((string)$room['room_status'] === 'maintenance') {
            continue;
        }
        if (bvo_room_has_slot($pdo, (int)$room['id'], $date, $duration, $gap)) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        return 'fully booked';
    }

    return null;
}

$pdo = db();
$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-d')));
$endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d', strtotime('+90 days'))));
if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d');
}
if (!$endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d', strtotime('+90 days'));
}

$dates = [];
foreach (bvo_range_dates($startDate, $endDate) as $date) {
    $reason = bvo_unavailable_reason($pdo, $date);
    if ($reason !== null) {
        $dates[] = ['date' => $date, 'reason' => $reason];
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'dates' => $dates], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
