<?php
declare(strict_types=1);

require_once __DIR__ . '/booking-rules.php';

const AV_MAX_DAYS = 366;

function av_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function av_dates(string $start, int $days): array
{
    $out = [];
    $cursor = new DateTimeImmutable($start);
    for ($i = 0; $i < $days; $i++) {
        $out[] = $cursor->modify('+' . $i . ' day')->format('Y-m-d');
    }
    return $out;
}

function av_load(PDO $pdo, string $start, string $end, ?int $serviceId = null, int $excludeId = 0): array
{
    $services = [];
    $byCode = [];
    foreach ($pdo->query('SELECT id, service_name, room_code, duration FROM services ORDER BY id')->fetchAll() as $s) {
        $s['id'] = (int)$s['id'];
        $s['duration'] = (int)$s['duration'];
        $s['room_code'] = strtoupper((string)$s['room_code']);
        $services[$s['id']] = $s;
        $byCode[$s['room_code']] = $s;
    }

    $service = null;
    if ($serviceId !== null && $serviceId > 0) {
        if (!isset($services[$serviceId])) {
            throw new InvalidArgumentException('The selected service does not exist.');
        }
        $service = $services[$serviceId];
    }

    $rooms = [];
    foreach ($pdo->query('SELECT id, room_name, room_status FROM rooms ORDER BY id')->fetchAll() as $r) {
        $rooms[] = [
            'id'     => (int)$r['id'],
            'name'   => (string)$r['room_name'],
            'status' => (string)$r['room_status'],
            'code'   => bk_room_code((string)$r['room_name']),
        ];
    }

    $hours = [];
    foreach ($pdo->query('SELECT day_of_week, opening_time, closing_time, is_open FROM spa_operating_hours')->fetchAll() as $h) {
        $hours[(int)$h['day_of_week']] = [
            'open'    => bk_min((string)$h['opening_time']),
            'close'   => bk_min((string)$h['closing_time']),
            'is_open' => (bool)(int)$h['is_open'],
        ];
    }

    $blocked = [];
    $stmt = $pdo->prepare('SELECT blocked_date, reason FROM blocked_dates WHERE blocked_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $b) {
        $blocked[(string)$b['blocked_date']] = trim((string)($b['reason'] ?? ''));
    }

    $maint = [];
    $stmt = $pdo->prepare("SELECT room_id, scheduled_date FROM room_maintenance_logs WHERE scheduled_date BETWEEN ? AND ? AND maintenance_status <> 'completed'");
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $m) {
        $maint[(string)$m['scheduled_date']][(int)$m['room_id']] = true;
    }

    $booked = [];
    $stmt = $pdo->prepare(
        "SELECT appointment_date, room_id, start_time, end_time FROM appointments
         WHERE appointment_date BETWEEN ? AND ? AND id <> ?
           AND appointment_status NOT IN ('cancelled', 'no_show')"
    );
    $stmt->execute([$start, $end, $excludeId]);
    foreach ($stmt->fetchAll() as $a) {
        $booked[(string)$a['appointment_date']][(int)$a['room_id']][] = [bk_min((string)$a['start_time']), bk_min((string)$a['end_time'])];
    }

    return [
        'start'    => $start,
        'end'      => $end,
        'today'    => date('Y-m-d'),
        'now_min'  => (int)date('G') * 60 + (int)date('i'),
        'gap'      => spa_cleaning_gap($pdo),
        'services' => $services,
        'by_code'  => $byCode,
        'service'  => $service,
        'rooms'    => $rooms,
        'hours'    => $hours,
        'blocked'  => $blocked,
        'maint'    => $maint,
        'booked'   => $booked,
    ];
}

function av_day_hours(array $ctx, string $date): array
{
    return $ctx['hours'][(int)date('N', strtotime($date))] ?? ['open' => 780, 'close' => 1260, 'is_open' => true];
}

function av_room_service(array $ctx, array $room): ?array
{
    if ($ctx['service'] !== null) {
        return $room['code'] === $ctx['service']['room_code'] ? $ctx['service'] : null;
    }
    return $ctx['by_code'][$room['code']] ?? null;
}

function av_scope_rooms(array $ctx): array
{
    $out = [];
    foreach ($ctx['rooms'] as $room) {
        if (av_room_service($ctx, $room) !== null) {
            $out[] = $room;
        }
    }
    return $out;
}

function av_room_usable(array $ctx, string $date, array $room): bool
{
    return $room['status'] !== 'maintenance' && empty($ctx['maint'][$date][$room['id']]);
}

function av_free_slots(array $ctx, string $date, array $room, array $service, bool $futureOnly = true): array
{
    $day = av_day_hours($ctx, $date);
    if (!$day['is_open']) {
        return [];
    }
    $duration = (int)$service['duration'];
    $gap = (int)$ctx['gap'];
    $busy = $ctx['booked'][$date][$room['id']] ?? [];
    $cutoff = ($futureOnly && $date === $ctx['today']) ? (int)$ctx['now_min'] : -1;
    $free = [];
    foreach (bk_grid($day['open'], $day['close'], $duration, $gap) as $s) {
        if ($s <= $cutoff) {
            continue;
        }
        $e = $s + $duration;
        $clash = false;
        foreach ($busy as [$bs, $be]) {
            if ($s < $be + $gap && $bs < $e + $gap) {
                $clash = true;
                break;
            }
        }
        if (!$clash) {
            $free[] = $s;
        }
    }
    return $free;
}

function av_room_slots(array $ctx, string $date, bool $futureOnly = true): array
{
    $out = [];
    foreach (av_scope_rooms($ctx) as $room) {
        $service = av_room_service($ctx, $room);
        $usable = av_room_usable($ctx, $date, $room);
        $out[] = [
            'room'    => $room,
            'service' => $service,
            'usable'  => $usable,
            'slots'   => $usable ? av_free_slots($ctx, $date, $room, $service, $futureOnly) : [],
        ];
    }
    return $out;
}

function av_date_status(array $ctx, string $date): ?array
{
    if ($date < $ctx['today']) {
        return ['code' => 'past', 'message' => 'date has passed'];
    }

    $day = av_day_hours($ctx, $date);
    if (!$day['is_open']) {
        return ['code' => 'closed', 'message' => 'the spa is closed on ' . date('l', strtotime($date)) . 's'];
    }

    if (isset($ctx['blocked'][$date])) {
        $why = $ctx['blocked'][$date];
        return ['code' => 'blocked', 'message' => $why !== '' ? 'the spa is closed (' . $why . ')' : 'the spa is closed on this date'];
    }

    $scope = av_scope_rooms($ctx);
    if (!$scope) {
        return ['code' => 'fully_booked', 'message' => 'no rooms available'];
    }

    $usable = array_values(array_filter($scope, static fn(array $r): bool => av_room_usable($ctx, $date, $r)));
    if (!$usable) {
        return [
            'code'    => 'maintenance',
            'message' => $ctx['service'] !== null ? 'all ' . $ctx['service']['room_code'] . ' rooms are under maintenance' : 'all rooms are under maintenance',
        ];
    }

    foreach ($usable as $room) {
        if (av_free_slots($ctx, $date, $room, av_room_service($ctx, $room), true)) {
            return null;
        }
    }

    if ($date === $ctx['today']) {
        foreach ($usable as $room) {
            if (av_free_slots($ctx, $date, $room, av_room_service($ctx, $room), false)) {
                return ['code' => 'fully_booked', 'message' => 'no sessions left today'];
            }
        }
    }

    return ['code' => 'fully_booked', 'message' => 'fully booked'];
}

function av_unavailable(PDO $pdo, string $start, int $days, ?int $serviceId = null): array
{
    $days = max(1, min(AV_MAX_DAYS, $days));
    $dates = av_dates($start, $days);
    $end = $dates[count($dates) - 1];
    $ctx = av_load($pdo, $start, $end, $serviceId);

    $out = [];
    foreach ($dates as $date) {
        $status = av_date_status($ctx, $date);
        if ($status !== null) {
            $out[] = ['date' => $date, 'code' => $status['code'], 'message' => $status['message']];
        }
    }

    return [
        'today'      => $ctx['today'],
        'start'      => $start,
        'end'        => $end,
        'service_id' => $ctx['service']['id'] ?? null,
        'dates'      => $out,
    ];
}

function av_slot_report(array $ctx, string $date, array $room, array $service): array
{
    $day = av_day_hours($ctx, $date);
    if (!$day['is_open']) {
        return [];
    }
    $duration = (int)$service['duration'];
    $gap = (int)$ctx['gap'];
    $busy = $ctx['booked'][$date][$room['id']] ?? [];
    $cutoff = $date === $ctx['today'] ? (int)$ctx['now_min'] : -1;
    $out = [];
    foreach (bk_grid($day['open'], $day['close'], $duration, $gap) as $s) {
        $e = $s + $duration;
        $state = 'free';
        if ($date < $ctx['today'] || $s <= $cutoff) {
            $state = 'past';
        } else {
            foreach ($busy as [$bs, $be]) {
                if ($s < $be + $gap && $bs < $e + $gap) {
                    if ($s < $be && $bs < $e) {
                        $state = 'booked';
                        break;
                    }
                    $state = 'gap';
                }
            }
        }
        $out[] = ['start' => $s, 'end' => $e, 'state' => $state];
    }
    return $out;
}

function av_slot_row(int $start, int $end, string $state, bool $own): array
{
    $reasons = ['free' => '', 'past' => 'Already passed', 'booked' => 'Booked', 'gap' => 'Cleaning gap'];
    return [
        'start'     => substr(bk_hms($start), 0, 5),
        'end'       => substr(bk_hms($end), 0, 5),
        'label'     => bk_label($start) . ' – ' . bk_label($end),
        'available' => $own || $state === 'free',
        'reason'    => $own ? '' : ($reasons[$state] ?? ''),
        'own'       => $own,
    ];
}

function av_slots(PDO $pdo, string $date, int $serviceId, int $roomId = 0, int $excludeId = 0): array
{
    if ($serviceId < 1) {
        throw new InvalidArgumentException('Select a service first.');
    }
    $ctx = av_load($pdo, $date, $date, $serviceId, $excludeId);
    $service = $ctx['service'];
    if ($service === null) {
        throw new InvalidArgumentException('Select a service first.');
    }

    $scope = av_scope_rooms($ctx);
    $mismatch = false;
    $rooms = $scope;
    if ($roomId > 0) {
        $rooms = array_values(array_filter($scope, static fn(array $r): bool => $r['id'] === $roomId));
        if (!$rooms) {
            $mismatch = true;
            $rooms = $scope;
        }
    }

    $own = null;
    if ($excludeId > 0) {
        $stmt = $pdo->prepare(
            "SELECT room_id, service_id, appointment_date, start_time, end_time FROM appointments
             WHERE id = ? AND appointment_status IN ('pending', 'confirmed')"
        );
        $stmt->execute([$excludeId]);
        $row = $stmt->fetch();
        if ($row && (string)$row['appointment_date'] === $date && (int)$row['service_id'] === $serviceId) {
            $own = [
                'room_id' => (int)$row['room_id'],
                'start'   => bk_min((string)$row['start_time']),
                'end'     => bk_min((string)$row['end_time']),
            ];
        }
    }

    $status = av_date_status($ctx, $date);
    $dateOpen = $status === null || !in_array($status['code'], ['past', 'closed', 'blocked'], true);

    $outRooms = [];
    foreach ($rooms as $room) {
        $usable = av_room_usable($ctx, $date, $room);
        $rows = [];
        if ($dateOpen && $usable) {
            foreach (av_slot_report($ctx, $date, $room, $service) as $s) {
                $rows[$s['start']] = av_slot_row($s['start'], $s['end'], $s['state'], false);
            }
        }
        if ($own !== null && $own['room_id'] === $room['id']) {
            $rows[$own['start']] = av_slot_row($own['start'], $own['end'], 'free', true);
            ksort($rows);
        }
        $outRooms[] = [
            'id'     => $room['id'],
            'name'   => $room['name'],
            'status' => $room['status'],
            'usable' => $usable,
            'slots'  => array_values($rows),
        ];
    }

    return [
        'date'          => $date,
        'service'       => [
            'id'        => $service['id'],
            'name'      => $service['service_name'],
            'duration'  => $service['duration'],
            'room_code' => $service['room_code'],
        ],
        'gap'           => $ctx['gap'],
        'date_status'   => $status,
        'room_mismatch' => $mismatch,
        'service_rooms' => array_map(static fn(array $r): int => $r['id'], $scope),
        'rooms'         => $outRooms,
    ];
}