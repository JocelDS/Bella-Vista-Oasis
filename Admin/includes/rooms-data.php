<?php
declare(strict_types=1);

require_once __DIR__ . '/calendar-data.php';

const RM_STATUSES = ['available', 'cleaning', 'maintenance'];
const RM_MAINT    = ['scheduled' => 'Scheduled', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
const RM_TEMP_MIN = 10.0;
const RM_TEMP_MAX = 45.0;

function rm_ready(PDO $pdo): bool
{
    foreach (['room_details', 'room_climate_logs', 'room_maintenance_logs', 'climate_settings'] as $t) {
        try {
            $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        } catch (Throwable $e) {
            return false;
        }
    }
    return true;
}

function rm_settings(PDO $pdo): array
{
    $d = ['min_temp' => 22.0, 'max_temp' => 26.0, 'min_hum' => 40.0, 'max_hum' => 60.0, 'updated' => ''];
    try {
        $r = $pdo->query('SELECT minimum_temperature, maximum_temperature, minimum_humidity, maximum_humidity, updated_at FROM climate_settings WHERE id = 1')->fetch();
    } catch (Throwable $e) {
        return $d;
    }
    if (!$r) {
        return $d;
    }
    return [
        'min_temp' => (float)$r['minimum_temperature'],
        'max_temp' => (float)$r['maximum_temperature'],
        'min_hum'  => (float)$r['minimum_humidity'],
        'max_hum'  => (float)$r['maximum_humidity'],
        'updated'  => $r['updated_at'] ? date('M j, Y g:i A', strtotime((string)$r['updated_at'])) : '',
    ];
}

function rm_band(float $v, float $min, float $max, array $labels): array
{
    if ($v < $min) {
        return ['key' => 'low', 'label' => $labels[0]];
    }
    if ($v > $max) {
        return ['key' => 'high', 'label' => $labels[2]];
    }
    return ['key' => 'ok', 'label' => $labels[1]];
}

function rm_num(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

function rm_dt(?string $v): string
{
    $ts = $v ? strtotime($v) : false;
    return $ts === false ? '' : date('M j, Y g:i A', $ts);
}

function rm_climate_row(array $r, array $s): array
{
    $t = (float)$r['temperature'];
    $h = (float)$r['humidity'];
    $tb = rm_band($t, $s['min_temp'], $s['max_temp'], ['Too Cool', 'Optimal', 'Too Warm']);
    $hb = rm_band($h, $s['min_hum'], $s['max_hum'], ['Too Dry', 'Optimal', 'Too Humid']);
    return [
        'id'             => (int)($r['id'] ?? 0),
        'temperature'    => $t,
        'humidity'       => $h,
        'temp_label'     => rm_num($t) . '°C',
        'hum_label'      => rm_num($h) . '%',
        'notes'          => (string)($r['condition_notes'] ?? ''),
        'recorded_at'    => (string)$r['recorded_at'],
        'recorded_label' => rm_dt((string)$r['recorded_at']),
        'by'             => (string)($r['admin_name'] ?? ''),
        'temp'           => $tb,
        'hum'            => $hb,
        'ok'             => $tb['key'] === 'ok' && $hb['key'] === 'ok',
    ];
}

function rm_maint_row(array $m): array
{
    $st = (string)$m['maintenance_status'];
    return [
        'id'           => (int)$m['id'],
        'room_id'      => (int)$m['room_id'],
        'room_name'    => (string)($m['room_name'] ?? ''),
        'title'        => (string)$m['maintenance_title'],
        'description'  => (string)($m['description'] ?? ''),
        'status'       => $st,
        'status_label' => RM_MAINT[$st] ?? ucfirst($st),
        'date'         => (string)$m['scheduled_date'],
        'date_label'   => appt_date_label((string)$m['scheduled_date']),
        'completed'    => rm_dt($m['completed_at'] ?? null),
        'by'           => (string)($m['admin_name'] ?? ''),
    ];
}

function rm_load(PDO $pdo): array
{
    $s     = rm_settings($pdo);
    $today = date('Y-m-d');
    $now   = cal_now_minutes();
    $gap   = spa_cleaning_gap($pdo);

    $rooms = $pdo->query(
        'SELECT r.id, r.room_name, r.room_type, r.room_status, r.description, d.amenities, d.maximum_capacity
         FROM rooms r LEFT JOIN room_details d ON d.room_id = r.id ORDER BY r.id ASC'
    )->fetchAll();

    $climate = [];
    foreach ($pdo->query(
        'SELECT l.id, l.room_id, l.temperature, l.humidity, l.condition_notes, l.recorded_at, a.full_name AS admin_name
         FROM room_climate_logs l LEFT JOIN administrators a ON a.id = l.recorded_by
         WHERE l.id = (SELECT x.id FROM room_climate_logs x WHERE x.room_id = l.room_id ORDER BY x.recorded_at DESC, x.id DESC LIMIT 1)'
    )->fetchAll() as $r) {
        $climate[(int)$r['room_id']] = rm_climate_row($r, $s);
    }

    $maint = [];
    foreach ($pdo->query(
        'SELECT m.*, a.full_name AS admin_name FROM room_maintenance_logs m LEFT JOIN administrators a ON a.id = m.created_by
         WHERE m.id = (SELECT x.id FROM room_maintenance_logs x WHERE x.room_id = m.room_id ORDER BY x.scheduled_date DESC, x.id DESC LIMIT 1)'
    )->fetchAll() as $m) {
        $maint[(int)$m['room_id']] = rm_maint_row($m);
    }

    $open = [];
    foreach ($pdo->query("SELECT room_id, COUNT(*) AS n FROM room_maintenance_logs WHERE maintenance_status <> 'completed' GROUP BY room_id")->fetchAll() as $r) {
        $open[(int)$r['room_id']] = (int)$r['n'];
    }

    $stmt = $pdo->prepare(
        "SELECT a.room_id, a.start_time, a.end_time, c.full_name AS customer, s.service_name
         FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         WHERE a.appointment_date = ? AND a.appointment_status IN ('pending', 'confirmed', 'completed')
         ORDER BY a.start_time ASC"
    );
    $stmt->execute([$today]);
    $byRoom = [];
    foreach ($stmt->fetchAll() as $r) {
        $byRoom[(int)$r['room_id']][] = $r;
    }

    $out = [];
    $sumT = $sumH = 0.0;
    $nClimate = 0;
    $counts = ['available' => 0, 'cleaning' => 0, 'maintenance' => 0];

    foreach ($rooms as $r) {
        $id     = (int)$r['id'];
        $status = (string)$r['room_status'];
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $cur = $next = null;
        $todayList = $byRoom[$id] ?? [];
        foreach ($todayList as $a) {
            $st = appt_minutes((string)$a['start_time']);
            $en = appt_minutes((string)$a['end_time']);
            if ($st <= $now && $now < $en + $gap && $cur === null) {
                $cur = ['customer' => (string)$a['customer'], 'service' => (string)$a['service_name'], 'end' => $en, 'end_label' => cal_time_label($en), 'free' => cal_time_label($en + $gap), 'in_session' => $now < $en];
            } elseif ($st > $now && $next === null) {
                $next = ['customer' => (string)$a['customer'], 'service' => (string)$a['service_name'], 'start' => $st, 'start_label' => cal_time_label($st)];
            }
        }

        if ($status === 'maintenance') {
            $avail = 'Unavailable · under maintenance';
        } elseif ($status === 'cleaning') {
            $avail = 'Unavailable · cleaning in progress';
        } elseif ($cur) {
            $avail = 'Free from ' . $cur['free'] . ($cur['in_session'] ? '' : ' (cleaning gap)');
        } elseif ($next) {
            $avail = 'Free now until ' . cal_time_label(max($now, $next['start'] - $gap));
        } else {
            $avail = 'Free for the rest of the day';
        }

        $cl = $climate[$id] ?? null;
        if ($cl) {
            $sumT += $cl['temperature'];
            $sumH += $cl['humidity'];
            $nClimate++;
        }

        $reasons = [];
        if ($status !== 'available') {
            $reasons[] = $status === 'cleaning' ? 'Cleaning required' : 'Under maintenance';
        }
        if ($cl && !$cl['ok']) {
            $reasons[] = 'Climate out of range';
        }
        if (($open[$id] ?? 0) > 0 && $status !== 'maintenance') {
            $reasons[] = 'Open maintenance task';
        }

        $amen = array_values(array_filter(array_map('trim', explode(',', (string)($r['amenities'] ?? '')))));

        $out[] = [
            'id'               => $id,
            'name'             => (string)$r['room_name'],
            'type'             => (string)($r['room_type'] ?? ''),
            'status'           => $status,
            'description'      => (string)($r['description'] ?? ''),
            'amenities'        => (string)($r['amenities'] ?? ''),
            'amenity_list'     => $amen,
            'capacity'         => $r['maximum_capacity'] !== null ? (int)$r['maximum_capacity'] : null,
            'climate'          => $cl,
            'maintenance'      => $maint[$id] ?? null,
            'open_maintenance' => $open[$id] ?? 0,
            'current'          => $cur,
            'next'             => $next,
            'availability'     => $avail,
            'today_count'      => count($todayList),
            'attention'        => (bool)$reasons,
            'reasons'          => $reasons,
        ];
    }

    return [
        'rooms'    => $out,
        'settings' => $s,
        'summary'  => [
            'total'       => count($out),
            'available'   => $counts['available'],
            'cleaning'    => $counts['cleaning'],
            'maintenance' => $counts['maintenance'],
            'avg_temp'    => $nClimate ? round($sumT / $nClimate, 1) : null,
            'avg_hum'     => $nClimate ? round($sumH / $nClimate, 1) : null,
            'avg_temp_label' => $nClimate ? rm_num($sumT / $nClimate) . '°C' : null,
            'avg_hum_label'  => $nClimate ? rm_num($sumH / $nClimate) . '%' : null,
            'readings'    => $nClimate,
            'attention'   => count(array_filter($out, static fn(array $x): bool => $x['attention'])),
        ],
        'gap'      => $gap,
    ];
}

function rm_logs(PDO $pdo, int $roomId = 0): array
{
    $sql = 'SELECT m.*, r.room_name, a.full_name AS admin_name FROM room_maintenance_logs m
            LEFT JOIN rooms r ON r.id = m.room_id LEFT JOIN administrators a ON a.id = m.created_by';
    $params = [];
    if ($roomId > 0) {
        $sql .= ' WHERE m.room_id = ?';
        $params[] = $roomId;
    }
    $sql .= ' ORDER BY m.scheduled_date DESC, m.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('rm_maint_row', $stmt->fetchAll());
}

function rm_climate_history(PDO $pdo, int $roomId, array $s, int $limit = 12): array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.temperature, l.humidity, l.condition_notes, l.recorded_at, a.full_name AS admin_name
         FROM room_climate_logs l LEFT JOIN administrators a ON a.id = l.recorded_by
         WHERE l.room_id = ? ORDER BY l.recorded_at DESC, l.id DESC LIMIT ' . (int)$limit
    );
    $stmt->execute([$roomId]);
    return array_map(static fn(array $r): array => rm_climate_row($r, $s), $stmt->fetchAll());
}

function rm_schedule(PDO $pdo, int $roomId): array
{
    $today = date('Y-m-d');
    $stmt  = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, c.full_name AS customer, s.service_name, t.full_name AS therapist
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.room_id = ? AND a.appointment_date >= ?
         ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 40"
    );
    $stmt->execute([$roomId, $today]);
    $items = [];
    $busy  = [];
    foreach ($stmt->fetchAll() as $a) {
        $st = (string)$a['appointment_status'];
        $s  = appt_minutes((string)$a['start_time']);
        $e  = appt_minutes((string)$a['end_time']);
        if ($a['appointment_date'] === $today && !in_array($st, ['cancelled', 'no_show'], true)) {
            $busy[] = [$s, $e];
        }
        $items[] = [
            'id'        => (int)$a['id'],
            'code'      => appt_code((int)$a['id']),
            'date'      => appt_date_label((string)$a['appointment_date']),
            'time'      => cal_time_label($s) . ' – ' . cal_time_label($e),
            'customer'  => (string)$a['customer'],
            'service'   => (string)$a['service_name'],
            'therapist' => (string)$a['therapist'],
            'status'    => ucfirst(str_replace('_', ' ', $st)),
        ];
    }
    $wins  = [];
    $hours = cal_hours($pdo, $today);
    foreach (cal_free_windows($busy, $hours[0], $hours[1], spa_cleaning_gap($pdo), 30) as [$a, $b]) {
        $wins[] = cal_time_label($a) . ' – ' . cal_time_label($b);
    }
    return ['items' => $items, 'free_today' => $wins];
}

function rm_detail(PDO $pdo, int $id): ?array
{
    $data = rm_load($pdo);
    $room = null;
    foreach ($data['rooms'] as $r) {
        if ($r['id'] === $id) {
            $room = $r;
            break;
        }
    }
    if (!$room) {
        return null;
    }
    return [
        'room'        => $room,
        'settings'    => $data['settings'],
        'climate'     => rm_climate_history($pdo, $id, $data['settings']),
        'schedule'    => rm_schedule($pdo, $id),
        'maintenance' => rm_logs($pdo, $id),
    ];
}

function rm_fail(string $m): array
{
    return ['ok' => false, 'error' => $m];
}

function rm_room_exists(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, room_name, room_status FROM rooms WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function rm_room_input(PDO $pdo, array $in, ?int $id): array
{
    $name = trim((string)($in['room_name'] ?? ''));
    $type = trim((string)($in['room_type'] ?? ''));
    $desc = trim((string)($in['description'] ?? ''));
    $stat = (string)($in['room_status'] ?? 'available');
    $amen = trim((string)($in['amenities'] ?? ''));
    $cap  = trim((string)($in['maximum_capacity'] ?? ''));

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        return rm_fail('Room name must be 2 to 150 characters.');
    }
    if ($type === '' || mb_strlen($type) > 100) {
        return rm_fail('Room type is required (max 100 characters).');
    }
    if (mb_strlen($desc) > 1000) {
        return rm_fail('Description is too long (max 1000 characters).');
    }
    if (!in_array($stat, RM_STATUSES, true)) {
        return rm_fail('Choose a valid room status.');
    }
    if (mb_strlen($amen) > 500) {
        return rm_fail('Amenities are too long (max 500 characters).');
    }
    $capacity = null;
    if ($cap !== '') {
        if (!ctype_digit($cap) || (int)$cap < 1 || (int)$cap > 20) {
            return rm_fail('Maximum capacity must be a whole number from 1 to 20.');
        }
        $capacity = (int)$cap;
    }
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE LOWER(room_name) = LOWER(?) AND id <> ?');
    $stmt->execute([$name, $id ?? 0]);
    if ($stmt->fetch()) {
        return rm_fail('A suite with that name already exists.');
    }
    return ['ok' => true, 'v' => ['name' => $name, 'type' => $type, 'desc' => $desc, 'status' => $stat, 'amen' => $amen, 'cap' => $capacity]];
}

function rm_save_details(PDO $pdo, int $roomId, string $amen, ?int $cap): void
{
    $stmt = $pdo->prepare('SELECT id FROM room_details WHERE room_id = ?');
    $stmt->execute([$roomId]);
    if ($stmt->fetch()) {
        $pdo->prepare('UPDATE room_details SET amenities = ?, maximum_capacity = ? WHERE room_id = ?')->execute([$amen !== '' ? $amen : null, $cap, $roomId]);
    } else {
        $pdo->prepare('INSERT INTO room_details (room_id, amenities, maximum_capacity) VALUES (?, ?, ?)')->execute([$roomId, $amen !== '' ? $amen : null, $cap]);
    }
}

function rm_open_in_progress(PDO $pdo, int $roomId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_maintenance_logs WHERE room_id = ? AND maintenance_status = 'in_progress'");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
}

function rm_create_room(PDO $pdo, array $in): array
{
    $c = rm_room_input($pdo, $in, null);
    if (empty($c['ok'])) {
        return $c;
    }
    $v = $c['v'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO rooms (room_name, room_type, room_status, description) VALUES (?, ?, ?, ?)')
            ->execute([$v['name'], $v['type'], $v['status'], $v['desc'] !== '' ? $v['desc'] : null]);
        $id = (int)$pdo->lastInsertId();
        rm_save_details($pdo, $id, $v['amen'], $v['cap']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO rooms] ' . $e->getMessage());
        return rm_fail('Unable to save the suite right now.');
    }
    return ['ok' => true, 'id' => $id, 'message' => $v['name'] . ' was added.'];
}

function rm_update_room(PDO $pdo, int $id, array $in): array
{
    $room = rm_room_exists($pdo, $id);
    if (!$room) {
        return rm_fail('That suite no longer exists.');
    }
    $c = rm_room_input($pdo, $in, $id);
    if (empty($c['ok'])) {
        return $c;
    }
    $v = $c['v'];
    if ($v['status'] === 'available' && $room['room_status'] !== 'available' && rm_open_in_progress($pdo, $id) > 0) {
        return rm_fail('This suite has maintenance in progress. Complete the maintenance record first.');
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE rooms SET room_name = ?, room_type = ?, room_status = ?, description = ? WHERE id = ?')
            ->execute([$v['name'], $v['type'], $v['status'], $v['desc'] !== '' ? $v['desc'] : null, $id]);
        rm_save_details($pdo, $id, $v['amen'], $v['cap']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO rooms] ' . $e->getMessage());
        return rm_fail('Unable to save the suite right now.');
    }
    return ['ok' => true, 'id' => $id, 'message' => $v['name'] . ' was updated.'];
}

function rm_set_status(PDO $pdo, int $id, string $status): array
{
    $room = rm_room_exists($pdo, $id);
    if (!$room) {
        return rm_fail('That suite no longer exists.');
    }
    if (!in_array($status, RM_STATUSES, true)) {
        return rm_fail('Choose a valid room status.');
    }
    if ($status === 'available' && $room['room_status'] !== 'available' && rm_open_in_progress($pdo, $id) > 0) {
        return rm_fail('This suite has maintenance in progress. Complete the maintenance record before marking it available.');
    }
    $pdo->prepare('UPDATE rooms SET room_status = ? WHERE id = ?')->execute([$status, $id]);
    return ['ok' => true, 'message' => $room['room_name'] . ' is now ' . $status . '.'];
}

function rm_save_climate(PDO $pdo, array $in, int $adminId): array
{
    $roomId = (int)($in['room_id'] ?? 0);
    if ($roomId < 1 || !rm_room_exists($pdo, $roomId)) {
        return rm_fail('Select a valid suite.');
    }
    $t = trim((string)($in['temperature'] ?? ''));
    $h = trim((string)($in['humidity'] ?? ''));
    $d = trim((string)($in['observation_date'] ?? ''));
    $tm = trim((string)($in['observation_time'] ?? ''));
    $n = trim((string)($in['condition_notes'] ?? ''));
    if ($t === '' || $h === '' || $d === '' || $tm === '') {
        return rm_fail('Temperature, humidity, date and time are required.');
    }
    if (!is_numeric($t) || (float)$t < RM_TEMP_MIN || (float)$t > RM_TEMP_MAX) {
        return rm_fail('Temperature must be between ' . (int)RM_TEMP_MIN . '°C and ' . (int)RM_TEMP_MAX . '°C.');
    }
    if (!is_numeric($h) || (float)$h < 0 || (float)$h > 100) {
        return rm_fail('Humidity must be between 0% and 100%.');
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i', $d . ' ' . substr($tm, 0, 5));
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        return rm_fail('Enter a valid observation date and time.');
    }
    if ($dt->format('Y-m-d H:i:s') > date('Y-m-d H:i:s', time() + 60)) {
        return rm_fail('The observation time cannot be in the future.');
    }
    if (mb_strlen($n) > 500) {
        return rm_fail('Condition notes are too long (max 500 characters).');
    }
    $pdo->prepare('INSERT INTO room_climate_logs (room_id, temperature, humidity, condition_notes, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$roomId, round((float)$t, 1), round((float)$h, 1), $n !== '' ? $n : null, $adminId, $dt->format('Y-m-d H:i:s')]);
    return ['ok' => true, 'message' => 'Climate reading recorded.'];
}

function rm_save_settings(PDO $pdo, array $in): array
{
    $vals = [];
    foreach (['min_temp', 'max_temp', 'min_hum', 'max_hum'] as $k) {
        $x = trim((string)($in[$k] ?? ''));
        if ($x === '' || !is_numeric($x)) {
            return rm_fail('All four range values are required and must be numbers.');
        }
        $vals[$k] = round((float)$x, 1);
    }
    if ($vals['min_temp'] < RM_TEMP_MIN || $vals['max_temp'] > RM_TEMP_MAX || $vals['min_temp'] >= $vals['max_temp']) {
        return rm_fail('Temperature range must be within ' . (int)RM_TEMP_MIN . '–' . (int)RM_TEMP_MAX . '°C and the minimum must be lower than the maximum.');
    }
    if ($vals['min_hum'] < 0 || $vals['max_hum'] > 100 || $vals['min_hum'] >= $vals['max_hum']) {
        return rm_fail('Humidity range must be within 0–100% and the minimum must be lower than the maximum.');
    }
    $stmt = $pdo->prepare('SELECT id FROM climate_settings WHERE id = 1');
    $stmt->execute();
    if ($stmt->fetch()) {
        $pdo->prepare('UPDATE climate_settings SET minimum_temperature = ?, maximum_temperature = ?, minimum_humidity = ?, maximum_humidity = ?, updated_at = ? WHERE id = 1')
            ->execute([$vals['min_temp'], $vals['max_temp'], $vals['min_hum'], $vals['max_hum'], date('Y-m-d H:i:s')]);
    } else {
        $pdo->prepare('INSERT INTO climate_settings (id, minimum_temperature, maximum_temperature, minimum_humidity, maximum_humidity) VALUES (1, ?, ?, ?, ?)')
            ->execute([$vals['min_temp'], $vals['max_temp'], $vals['min_hum'], $vals['max_hum']]);
    }
    return ['ok' => true, 'message' => 'Recommended ranges updated.'];
}

function rm_save_maintenance(PDO $pdo, array $in, int $adminId, ?int $id): array
{
    $roomId = (int)($in['room_id'] ?? 0);
    $room   = $roomId > 0 ? rm_room_exists($pdo, $roomId) : null;
    if (!$room) {
        return rm_fail('Select a valid suite.');
    }
    $title = trim((string)($in['maintenance_title'] ?? ''));
    $desc  = trim((string)($in['description'] ?? ''));
    $stat  = (string)($in['maintenance_status'] ?? '');
    $date  = trim((string)($in['scheduled_date'] ?? ''));
    if ($title === '' || mb_strlen($title) < 3 || mb_strlen($title) > 150) {
        return rm_fail('The issue title must be 3 to 150 characters.');
    }
    if (mb_strlen($desc) > 2000) {
        return rm_fail('The description is too long (max 2000 characters).');
    }
    if (!isset(RM_MAINT[$stat])) {
        return rm_fail('Choose a valid maintenance status.');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return rm_fail('Enter a valid maintenance date.');
    }

    $prev = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, maintenance_status, completed_at FROM room_maintenance_logs WHERE id = ?');
        $stmt->execute([$id]);
        $prev = $stmt->fetch();
        if (!$prev) {
            return rm_fail('That maintenance record no longer exists.');
        }
    }

    $completedAt = null;
    if ($stat === 'completed') {
        $completedAt = ($prev && $prev['completed_at']) ? (string)$prev['completed_at'] : date('Y-m-d H:i:s');
    }

    try {
        $pdo->beginTransaction();
        if ($id === null) {
            $pdo->prepare('INSERT INTO room_maintenance_logs (room_id, maintenance_title, description, maintenance_status, scheduled_date, completed_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$roomId, $title, $desc !== '' ? $desc : null, $stat, $date, $completedAt, $adminId]);
        } else {
            $pdo->prepare('UPDATE room_maintenance_logs SET room_id = ?, maintenance_title = ?, description = ?, maintenance_status = ?, scheduled_date = ?, completed_at = ? WHERE id = ?')
                ->execute([$roomId, $title, $desc !== '' ? $desc : null, $stat, $date, $completedAt, $id]);
        }
        $roomNow = (string)$room['room_status'];
        if ($stat === 'in_progress' && $roomNow !== 'maintenance') {
            $pdo->prepare("UPDATE rooms SET room_status = 'maintenance' WHERE id = ?")->execute([$roomId]);
            $roomNow = 'maintenance';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO rooms] ' . $e->getMessage());
        return rm_fail('Unable to save the maintenance record right now.');
    }

    $msg = $id === null ? 'Maintenance record added.' : 'Maintenance record updated.';
    if ($stat === 'in_progress') {
        $msg .= ' The suite is now marked under maintenance and cannot be booked.';
    }
    $verify = $stat === 'completed' && $roomNow !== 'available';
    if ($verify) {
        $msg .= ' After verifying the suite, mark it available.';
    }
    return ['ok' => true, 'message' => $msg, 'verify_room' => $verify ? $roomId : 0];
}