<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-data.php';

const BK_FAMILY_CODE = 'FL';

function bk_min(string $t): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return 0;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

function bk_hms(int $minutes): string
{
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

function bk_label(int $minutes): string
{
    return date('g:i A', strtotime(bk_hms($minutes)));
}

function bk_room_code(string $roomName): string
{
    $pos = strpos($roomName, '-');
    return strtoupper($pos === false ? $roomName : substr($roomName, 0, $pos));
}

function bk_config(PDO $pdo): array
{
    $defaults = ['extra_person_fee' => '750', 'family_base_pax' => '4', 'family_max_pax' => '6'];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM spa_configuration WHERE setting_key IN ('extra_person_fee', 'family_base_pax', 'family_max_pax')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $rows = [];
    }
    $cfg = $rows + $defaults;
    return [
        'extra_fee' => max(0.0, (float)$cfg['extra_person_fee']),
        'base_pax'  => max(1, (int)$cfg['family_base_pax']),
        'max_pax'   => max(1, (int)$cfg['family_max_pax']),
    ];
}

function bk_day_hours(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare('SELECT opening_time, closing_time, is_open FROM spa_operating_hours WHERE day_of_week = ?');
    $stmt->execute([(int)date('N', strtotime($date))]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['open' => 780, 'close' => 1260, 'is_open' => true];
    }
    return ['open' => bk_min((string)$row['opening_time']), 'close' => bk_min((string)$row['closing_time']), 'is_open' => (bool)(int)$row['is_open']];
}

function bk_grid(int $openMin, int $closeMin, int $duration, int $gap): array
{
    $starts = [];
    if ($duration < 1) {
        return $starts;
    }
    $step = $duration + max(0, $gap);
    for ($s = $openMin; $s + $duration <= $closeMin; $s += $step) {
        $starts[] = $s;
    }
    return $starts;
}

function bk_validate(PDO $pdo, array $in, int $excludeId, bool $lock, bool $checkSchedule): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];

    $serviceId   = (int)($in['service_id'] ?? 0);
    $roomId      = (int)($in['room_id'] ?? 0);
    $therapistId = max(0, (int)($in['therapist_id'] ?? 0));
    $date        = (string)($in['date'] ?? '');
    $start       = (string)($in['start'] ?? '');
    $guestsIn    = $in['guests'] ?? null;

    $stmt = $pdo->prepare('SELECT id, service_name, room_code, duration, price, base_capacity, max_capacity FROM services WHERE id = ?');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    if (!$service) {
        return $fail('The selected service no longer exists.');
    }

    $stmt = $pdo->prepare('SELECT id, room_name, room_status FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return $fail('The selected room no longer exists.');
    }
    if ((string)$room['room_status'] === 'maintenance') {
        return $fail($room['room_name'] . ' is under maintenance and cannot take bookings.');
    }

    $code = strtoupper((string)$service['room_code']);
    if (bk_room_code((string)$room['room_name']) !== $code) {
        return $fail($room['room_name'] . ' is not a room for ' . $service['service_name'] . '. Choose a ' . $code . ' room.');
    }

    $cfg  = bk_config($pdo);
    $maxG = (int)$service['max_capacity'];
    if ($code === BK_FAMILY_CODE) {
        $maxG = min($maxG, $cfg['max_pax']);
    }
    $baseG = $code === BK_FAMILY_CODE ? $cfg['base_pax'] : (int)$service['base_capacity'];
    $guests = ($guestsIn === null || $guestsIn === '') ? $baseG : (int)$guestsIn;
    if ($guests < 1) {
        return $fail('At least 1 guest is required.');
    }
    if ($guests > $maxG) {
        return $fail($service['service_name'] . ' allows a maximum of ' . $maxG . ' guest' . ($maxG === 1 ? '' : 's') . '.');
    }

    $duration = (int)$service['duration'];
    $startMin = bk_min($start);
    $endMin   = $startMin + $duration;
    if ($endMin >= 1440) {
        return $fail('The session must finish before midnight.');
    }
    $end = bk_hms($endMin);
    $gap = spa_cleaning_gap($pdo);

    if ($checkSchedule) {
        if ($date < date('Y-m-d')) {
            return $fail('The appointment date cannot be in the past.');
        }

        $stmt = $pdo->prepare('SELECT reason FROM blocked_dates WHERE blocked_date = ? LIMIT 1');
        $stmt->execute([$date]);
        $blocked = $stmt->fetch();
        if ($blocked) {
            $why = trim((string)($blocked['reason'] ?? ''));
            return $fail('The spa is closed on ' . date('M j, Y', strtotime($date)) . ($why !== '' ? ' (' . $why . ').' : '.'));
        }

        $hoursErr = spa_check_hours($pdo, $date, bk_hms($startMin), $end);
        if ($hoursErr !== null) {
            return $fail($hoursErr);
        }

        $day = bk_day_hours($pdo, $date);
        $grid = bk_grid($day['open'], $day['close'], $duration, $gap);
        if (!in_array($startMin, $grid, true)) {
            $labels = array_map('bk_label', $grid);
            return $fail('Start time must match the booking schedule for ' . $service['service_name'] . ': ' . ($labels ? implode(', ', $labels) : 'no sessions fit the operating hours') . '.');
        }

        if ($date === date('Y-m-d') && $startMin <= (int)date('G') * 60 + (int)date('i')) {
            return $fail('The start time must be later than the current time.');
        }
    }

    if ($therapistId > 0) {
        $stmt = $pdo->prepare('SELECT availability_status FROM therapists WHERE id = ?');
        $stmt->execute([$therapistId]);
        $therapist = $stmt->fetch();
        if (!$therapist) {
            return $fail('The selected therapist no longer exists.');
        }
        if ($therapist['availability_status'] === 'on_leave') {
            return $fail('The selected therapist is on leave.');
        }
    }

    $sql = "SELECT room_id, therapist_id, start_time, end_time FROM appointments
            WHERE appointment_date = ? AND id <> ?
              AND appointment_status NOT IN ('cancelled', 'no_show')
              AND (room_id = ?" . ($therapistId > 0 ? ' OR therapist_id = ?' : '') . ')'
        . ($lock ? ' FOR UPDATE' : '');
    $params = [$date, $excludeId, $roomId];
    if ($therapistId > 0) {
        $params[] = $therapistId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $c) {
        $s = bk_min((string)$c['start_time']);
        $e = bk_min((string)$c['end_time']);
        if ((int)$c['room_id'] === $roomId && $startMin < $e + $gap && $s < $endMin + $gap) {
            return $fail('That room is booked ' . bk_label($s) . ' – ' . bk_label($e) . ($gap > 0 ? ' and needs a ' . $gap . '-minute cleaning gap between bookings.' : '.'));
        }
        if ($therapistId > 0 && (int)$c['therapist_id'] === $therapistId && $startMin < $e && $s < $endMin) {
            return $fail('That therapist is already booked ' . bk_label($s) . ' – ' . bk_label($e) . '.');
        }
    }

    $base  = round((float)$service['price'], 2);
    $extra = 0.0;
    if ($code === BK_FAMILY_CODE && $guests > $cfg['base_pax']) {
        $extra = round(($guests - $cfg['base_pax']) * $cfg['extra_fee'], 2);
    }

    return [
        'ok'           => true,
        'service'      => $service,
        'room_id'      => $roomId,
        'therapist_id' => $therapistId,
        'date'         => $date,
        'start'        => bk_hms($startMin),
        'end'          => $end,
        'start_min'    => $startMin,
        'end_min'      => $endMin,
        'guests'       => $guests,
        'base'         => $base,
        'extra'        => $extra,
        'total'        => round($base + $extra, 2),
    ];
}