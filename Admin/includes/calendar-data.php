<?php
declare(strict_types=1);

require_once __DIR__ . '/appointments-data.php';

const CAL_OPEN_HOUR  = 9;
const CAL_CLOSE_HOUR = 21;
const CAL_VIEWS      = ['multi', 'day', 'week'];

function cal_params(array $src): array
{
    $view = (string)($src['view'] ?? 'multi');
    if (!in_array($view, CAL_VIEWS, true)) {
        $view = 'multi';
    }
    $status = (string)($src['status'] ?? '');
    if ($status !== '' && !isset(dash_status_map()[$status])) {
        $status = '';
    }
    return [
        'view'      => $view,
        'date'      => appt_parse_date((string)($src['date'] ?? '')) ?? date('Y-m-d'),
        'room'      => max(0, (int)($src['room'] ?? 0)),
        'therapist' => max(0, (int)($src['therapist'] ?? 0)),
        'service'   => max(0, (int)($src['service'] ?? 0)),
        'status'    => $status,
    ];
}

function cal_ceil15(int $m): int
{
    return (int)(ceil($m / 15) * 15);
}

function cal_now_minutes(): int
{
    return (int)date('G') * 60 + (int)date('i');
}

function cal_range(string $view, string $date): array
{
    if ($view !== 'week') {
        return [$date, $date];
    }
    $d    = new DateTimeImmutable($date);
    $from = $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
    return [$from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d')];
}

function cal_label(string $view, string $date): string
{
    if ($view === 'week') {
        [$from, $to] = cal_range($view, $date);
        return date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    }
    return date('l, F j, Y', strtotime($date));
}

function cal_state(string $status, string $date, int $start, int $end, string $today, int $nowMin): string
{
    if ($status === 'confirmed' && $date === $today && $nowMin >= $start && $nowMin < $end) {
        return 'session';
    }
    return $status;
}

function cal_time_label(int $minutes): string
{
    return date('g:i A', mktime(intdiv($minutes, 60), $minutes % 60, 0, 1, 1, 2000));
}

function cal_free_windows(array $busy, int $open, int $close, int $gap, int $minLen): array
{
    usort($busy, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $out    = [];
    $cursor = cal_ceil15($open);
    foreach ($busy as [$s, $e]) {
        $limit = $s - $gap;
        if ($limit - $cursor >= $minLen) {
            $out[] = [$cursor, $limit];
        }
        $cursor = max($cursor, cal_ceil15($e + $gap));
    }
    if ($close - $cursor >= $minLen) {
        $out[] = [$cursor, $close];
    }
    return $out;
}

function cal_conflict_message(array $rows, int $roomId, int $therapistId, int $newS, int $newE, int $gap): ?string
{
    foreach ($rows as $c) {
        $s = appt_minutes((string)$c['start_time']);
        $e = appt_minutes((string)$c['end_time']);
        if ((int)$c['room_id'] === $roomId && $newS < $e + $gap && $s < $newE + $gap) {
            return 'That room is booked ' . cal_time_label($s) . ' – ' . cal_time_label($e) . ' and needs a ' . $gap . '-minute cleaning gap between bookings.';
        }
        if ((int)$c['therapist_id'] === $therapistId && $newS < $e && $s < $newE) {
            return 'That therapist is already booked ' . cal_time_label($s) . ' – ' . cal_time_label($e) . '.';
        }
    }
    return null;
}

function cal_day_rows(PDO $pdo, string $date, int $roomId, int $therapistId, int $excludeId, bool $lock): array
{
    $stmt = $pdo->prepare(
        "SELECT room_id, therapist_id, start_time, end_time FROM appointments
         WHERE appointment_date = ? AND id <> ?
           AND appointment_status NOT IN ('cancelled', 'no_show')
           AND (room_id = ? OR therapist_id = ?)" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$date, $excludeId, $roomId, $therapistId]);
    return $stmt->fetchAll();
}

function cal_day_active(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT room_id, therapist_id, start_time, end_time FROM appointments
         WHERE appointment_date = ? AND appointment_status NOT IN ('cancelled', 'no_show')
         ORDER BY start_time ASC"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

function cal_min_service(PDO $pdo): int
{
    $v = (int)$pdo->query('SELECT MIN(duration) FROM services')->fetchColumn();
    return $v > 0 ? $v : 60;
}

function cal_memberships(PDO $pdo, array $customerIds, string $today): array
{
    if (!$customerIds) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($customerIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT customer_id, membership_type FROM memberships
         WHERE membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= ?) AND customer_id IN ($in)
         ORDER BY id ASC"
    );
    $stmt->execute(array_merge([$today], $customerIds));
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['customer_id']] = (string)$r['membership_type'];
    }
    return $map;
}

function cal_load_items(PDO $pdo, array $p, string $from, string $to, string $today, int $nowMin): array
{
    $where  = ['a.appointment_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    foreach ([['room', 'a.room_id'], ['therapist', 'a.therapist_id'], ['service', 'a.service_id']] as [$key, $col]) {
        if ($p[$key] > 0) {
            $where[]  = $col . ' = ?';
            $params[] = $p[$key];
        }
    }
    if ($p['status'] !== '') {
        $where[]  = 'a.appointment_status = ?';
        $params[] = $p['status'];
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.customer_id, a.service_id, a.room_id, a.therapist_id, a.appointment_date, a.start_time, a.end_time,
                a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS customer_name, s.service_name, s.duration, t.full_name AS therapist_name
         FROM appointments a
         LEFT JOIN customers c  ON c.id = a.customer_id
         LEFT JOIN services s   ON s.id = a.service_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $ids = [];
    foreach ($rows as $r) {
        $ids[(int)$r['customer_id']] = true;
    }
    $members = cal_memberships($pdo, array_keys($ids), $today);

    $smap = dash_status_map();
    $pmap = dash_payment_map();
    $out  = [];
    foreach ($rows as $r) {
        $s  = appt_minutes((string)$r['start_time']);
        $e  = appt_minutes((string)$r['end_time']);
        $st = (string)$r['appointment_status'];
        $ps = (string)$r['payment_status'];
        $out[] = [
            'id'             => (int)$r['id'],
            'code'           => appt_code((int)$r['id']),
            'customer_id'    => (int)$r['customer_id'],
            'customer'       => (string)($r['customer_name'] ?? 'Unknown guest'),
            'service'        => (string)($r['service_name'] ?? '—'),
            'duration'       => (int)$r['duration'],
            'therapist_id'   => (int)$r['therapist_id'],
            'therapist'      => (string)($r['therapist_name'] ?? '—'),
            'room_id'        => (int)$r['room_id'],
            'date'           => (string)$r['appointment_date'],
            'start'          => $s,
            'end'            => $e,
            'status'         => $st,
            'status_label'   => $smap[$st] ?? ucwords(str_replace('_', ' ', $st)),
            'state'          => cal_state($st, (string)$r['appointment_date'], $s, $e, $today, $nowMin),
            'payment_status' => $ps,
            'payment'        => $pmap[$ps] ?? ucwords(str_replace('_', ' ', $ps)),
            'amount'         => peso($r['total_amount']),
            'membership'     => $members[(int)$r['customer_id']] ?? '',
        ];
    }
    return $out;
}

function cal_roster(PDO $pdo, string $date, string $today, int $nowMin, array $active): array
{
    $byT = [];
    foreach ($active as $a) {
        $byT[(int)$a['therapist_id']][] = [appt_minutes((string)$a['start_time']), appt_minutes((string)$a['end_time'])];
    }
    $out = [];
    $rows = $pdo->query('SELECT id, full_name, specialization, availability_status FROM therapists ORDER BY id ASC')->fetchAll();
    foreach ($rows as $t) {
        $slots = $byT[(int)$t['id']] ?? [];
        $next  = null;
        $busy  = false;
        foreach ($slots as [$s, $e]) {
            if ($date === $today && $nowMin >= $s && $nowMin < $e) {
                $busy = true;
            }
            if ($next === null && ($date !== $today || $s >= $nowMin)) {
                $next = $s;
            }
        }
        $out[] = [
            'id'             => (int)$t['id'],
            'name'           => (string)$t['full_name'],
            'specialization' => (string)$t['specialization'],
            'status'         => (string)$t['availability_status'],
            'count'          => count($slots),
            'next'           => $next,
            'in_session'     => $busy,
        ];
    }
    return $out;
}

function cal_fetch(PDO $pdo, array $p): array
{
    $today  = date('Y-m-d');
    $nowMin = cal_now_minutes();
    [$from, $to] = cal_range($p['view'], $p['date']);

    $roomSql    = 'SELECT id, room_name, room_type, room_status, description FROM rooms';
    $roomParams = [];
    if ($p['room'] > 0) {
        $roomSql   .= ' WHERE id = ?';
        $roomParams[] = $p['room'];
    }
    $stmt = $pdo->prepare($roomSql . ' ORDER BY id ASC');
    $stmt->execute($roomParams);
    $roomRows = $stmt->fetchAll();

    $items = cal_load_items($pdo, $p, $from, $to, $today, $nowMin);

    $open  = CAL_OPEN_HOUR * 60;
    $close = CAL_CLOSE_HOUR * 60;
    $axisStart = $open;
    $axisEnd   = $close;
    foreach ($items as $it) {
        if ($it['date'] === $p['date']) {
            $axisStart = min($axisStart, intdiv($it['start'], 60) * 60);
            $axisEnd   = max($axisEnd, (int)ceil($it['end'] / 60) * 60);
        }
    }
    $axisEnd = min($axisEnd, 1440);

    $active = cal_day_active($pdo, $p['date']);
    $busy   = [];
    foreach ($active as $a) {
        $busy[(int)$a['room_id']][] = [appt_minutes((string)$a['start_time']), appt_minutes((string)$a['end_time'])];
    }

    $minLen    = cal_min_service($pdo);
    $canOffer  = $p['date'] >= $today;
    $winStart  = $p['date'] === $today ? max($axisStart, cal_ceil15($nowMin)) : $axisStart;
    $winEnd    = $axisEnd;

    $counts = [];
    foreach ($items as $it) {
        $counts[$it['room_id']] = ($counts[$it['room_id']] ?? 0) + (in_array($it['status'], ['cancelled', 'no_show'], true) ? 0 : 1);
    }

    $rooms     = [];
    $openings  = [];
    foreach ($roomRows as $r) {
        $rid     = (int)$r['id'];
        $windows = [];
        if ($canOffer && $r['room_status'] === 'available') {
            foreach (cal_free_windows($busy[$rid] ?? [], $winStart, $winEnd, ROOM_GAP_MINUTES, $minLen) as [$ws, $we]) {
                $w = ['start' => $ws, 'end' => $we, 'minutes' => $we - $ws, 'is_now' => $p['date'] === $today && $ws <= cal_ceil15($nowMin)];
                $windows[]  = $w;
                $openings[] = $w + ['room_id' => $rid, 'room' => (string)$r['room_name'], 'type' => (string)$r['room_type'], 'status' => (string)$r['room_status']];
            }
        }
        $rooms[] = [
            'id'          => $rid,
            'name'        => (string)$r['room_name'],
            'type'        => (string)$r['room_type'],
            'status'      => (string)$r['room_status'],
            'description' => (string)$r['description'],
            'count'       => $counts[$rid] ?? 0,
            'windows'     => $windows,
        ];
    }
    usort($openings, static fn(array $a, array $b): int => [$a['start'], $a['room_id']] <=> [$b['start'], $b['room_id']]);

    $days = [];
    if ($p['view'] === 'week') {
        $d = new DateTimeImmutable($from);
        for ($i = 0; $i < 7; $i++) {
            $x = $d->modify('+' . $i . ' days');
            $days[] = [
                'date'     => $x->format('Y-m-d'),
                'weekday'  => $x->format('D'),
                'day'      => $x->format('j'),
                'month'    => $x->format('M'),
                'is_today' => $x->format('Y-m-d') === $today,
            ];
        }
    }

    $summary = ['total' => count($items)];
    foreach ($items as $it) {
        $summary[$it['state']] = ($summary[$it['state']] ?? 0) + 1;
    }

    return [
        'view'        => $p['view'],
        'date'        => $p['date'],
        'today'       => $today,
        'from'        => $from,
        'to'          => $to,
        'label'       => cal_label($p['view'], $p['date']),
        'day_label'   => date('l, F j, Y', strtotime($p['date'])),
        'now_min'     => $p['date'] === $today ? $nowMin : null,
        'axis'        => ['start' => $axisStart, 'end' => $axisEnd],
        'gap'         => ROOM_GAP_MINUTES,
        'min_slot'    => $minLen,
        'rooms'       => $rooms,
        'appointments' => $items,
        'days'        => $days,
        'openings'    => array_slice($openings, 0, 8),
        'roster'      => cal_roster($pdo, $p['date'], $today, $nowMin, $active),
        'summary'     => $summary,
    ];
}

function cal_primary(array $d, string $today): array
{
    if ($d['status'] === 'pending') {
        return ['action' => 'confirm', 'label' => 'Confirm Appointment', 'disabled' => false, 'hint' => ''];
    }
    if ($d['status'] === 'confirmed') {
        $later = (string)$d['date'] > $today;
        return [
            'action'   => 'complete',
            'label'    => 'Mark Session Completed',
            'disabled' => $later,
            'hint'     => $later ? 'Available on the day of the session.' : '',
        ];
    }
    return ['action' => '', 'label' => 'No Further Updates', 'disabled' => true, 'hint' => 'This appointment is already ' . strtolower((string)$d['status_label']) . '.'];
}

function cal_detail(PDO $pdo, int $id): ?array
{
    $d = appt_detail($pdo, $id);
    if (!$d) {
        return null;
    }
    $today  = date('Y-m-d');
    $nowMin = cal_now_minutes();

    $stmt = $pdo->prepare('SELECT specialization FROM therapists WHERE id = ?');
    $stmt->execute([$d['therapist_id']]);
    $d['therapist_spec'] = (string)($stmt->fetchColumn() ?: '');

    $stmt = $pdo->prepare('SELECT room_type FROM rooms WHERE id = ?');
    $stmt->execute([$d['room_id']]);
    $d['room_type'] = (string)($stmt->fetchColumn() ?: '');

    $stmt = $pdo->prepare(
        "SELECT membership_type, expiry_date FROM memberships
         WHERE customer_id = ? AND membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= ?)
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$d['customer_id'], $today]);
    $m = $stmt->fetch();
    $d['membership']       = $m ? (string)$m['membership_type'] : '';
    $d['membership_until'] = $m && $m['expiry_date'] ? appt_date_label((string)$m['expiry_date']) : '';

    $d['state']          = cal_state((string)$d['status'], (string)$d['date'], appt_minutes((string)$d['start']), appt_minutes((string)$d['end']), $today, $nowMin);
    $d['primary']        = cal_primary($d, $today);
    $d['can_reschedule'] = in_array($d['status'], ['pending', 'confirmed'], true);
    return $d;
}

function cal_prepare_move(PDO $pdo, int $id, array $in): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];

    $stmt = $pdo->prepare('SELECT id, room_id, therapist_id, appointment_date, start_time, end_time, appointment_status FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $cur = $stmt->fetch();
    if (!$cur) {
        return $fail('That appointment no longer exists.');
    }
    if (!in_array($cur['appointment_status'], ['pending', 'confirmed'], true)) {
        return $fail('Only pending or confirmed appointments can be rescheduled.');
    }

    $roomId      = (int)($in['room_id'] ?? 0);
    $therapistId = (int)($in['therapist_id'] ?? 0);
    if ($roomId < 1 || $therapistId < 1) {
        return $fail('Select a room and a therapist.');
    }
    $date = appt_parse_date((string)($in['appointment_date'] ?? ''));
    if ($date === null) {
        return $fail('Enter a valid date.');
    }
    $start = appt_parse_time((string)($in['start_time'] ?? ''));
    if ($start === null) {
        return $fail('Enter a valid start time.');
    }

    $newS     = appt_minutes($start);
    $duration = appt_minutes((string)$cur['end_time']) - appt_minutes((string)$cur['start_time']);
    $newE     = $newS + $duration;
    if ($duration < 1 || $newE >= 1440) {
        return $fail('The session must finish before midnight.');
    }
    $sameSlot = (string)$cur['appointment_date'] === $date && substr((string)$cur['start_time'], 0, 5) === substr($start, 0, 5);
    if (!$sameSlot) {
        if ($date < date('Y-m-d')) {
            return $fail('The new date cannot be in the past.');
        }
        if ($date === date('Y-m-d') && $newS <= cal_now_minutes()) {
            return $fail('The new start time must be later than the current time.');
        }
    }

    $stmt = $pdo->prepare('SELECT room_status FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return $fail('The selected room no longer exists.');
    }
    if ($room['room_status'] !== 'available') {
        return $fail('That room is ' . $room['room_status'] . ' and cannot take bookings.');
    }

    $stmt = $pdo->prepare('SELECT availability_status FROM therapists WHERE id = ?');
    $stmt->execute([$therapistId]);
    $th = $stmt->fetch();
    if (!$th) {
        return $fail('The selected therapist no longer exists.');
    }
    if ($th['availability_status'] === 'on_leave') {
        return $fail('That therapist is on leave.');
    }

    return [
        'ok'          => true,
        'current'     => $cur,
        'date'        => $date,
        'start'       => $start,
        'end'         => sprintf('%02d:%02d:00', intdiv($newE, 60), $newE % 60),
        'start_min'   => $newS,
        'end_min'     => $newE,
        'room_id'     => $roomId,
        'therapist_id' => $therapistId,
    ];
}

function cal_check(PDO $pdo, int $id, array $in): array
{
    $m = cal_prepare_move($pdo, $id, $in);
    if (!$m['ok']) {
        return ['ok' => true, 'available' => false, 'message' => $m['error']];
    }
    $rows = cal_day_rows($pdo, $m['date'], $m['room_id'], $m['therapist_id'], $id, false);
    $msg  = cal_conflict_message($rows, $m['room_id'], $m['therapist_id'], $m['start_min'], $m['end_min'], ROOM_GAP_MINUTES);
    return [
        'ok'        => true,
        'available' => $msg === null,
        'message'   => $msg ?? 'Room and therapist are free. Session runs ' . cal_time_label($m['start_min']) . ' – ' . cal_time_label($m['end_min']) . '.',
    ];
}

function cal_reschedule(PDO $pdo, int $id, array $in): array
{
    $m = cal_prepare_move($pdo, $id, $in);
    if (!$m['ok']) {
        return $m;
    }
    $cur = $m['current'];
    if ((string)$cur['appointment_date'] === $m['date']
        && substr((string)$cur['start_time'], 0, 5) === substr($m['start'], 0, 5)
        && (int)$cur['room_id'] === $m['room_id']
        && (int)$cur['therapist_id'] === $m['therapist_id']) {
        return ['ok' => false, 'error' => 'Nothing changed. Pick a different date, time, room or therapist.'];
    }

    try {
        $pdo->beginTransaction();
        $rows = cal_day_rows($pdo, $m['date'], $m['room_id'], $m['therapist_id'], $id, true);
        $msg  = cal_conflict_message($rows, $m['room_id'], $m['therapist_id'], $m['start_min'], $m['end_min'], ROOM_GAP_MINUTES);
        if ($msg !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $msg];
        }
        $pdo->prepare(
            'UPDATE appointments SET room_id = ?, therapist_id = ?, appointment_date = ?, start_time = ?, end_time = ? WHERE id = ?'
        )->execute([$m['room_id'], $m['therapist_id'], $m['date'], $m['start'], $m['end'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Unable to reschedule the appointment right now.'];
    }

    return [
        'ok'      => true,
        'id'      => $id,
        'message' => 'Appointment ' . appt_code($id) . ' moved to ' . appt_date_label($m['date']) . ', ' . cal_time_label($m['start_min']) . ' – ' . cal_time_label($m['end_min']) . '.',
    ];
}

function cal_create(PDO $pdo, array $in): array
{
    $roomId = (int)($in['room_id'] ?? 0);
    $stmt   = $pdo->prepare('SELECT room_status FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return ['ok' => false, 'error' => 'Select a room.'];
    }
    if ($room['room_status'] !== 'available') {
        return ['ok' => false, 'error' => 'That room is ' . $room['room_status'] . ' and cannot take bookings.'];
    }
    return appt_save($pdo, $in, null, ROOM_GAP_MINUTES);
}

function cal_folio(PDO $pdo, int $id): ?array
{
    $d = cal_detail($pdo, $id);
    if (!$d) {
        return null;
    }
    $cid = (int)$d['customer_id'];

    $stmt = $pdo->prepare(
        'SELECT a.id, a.appointment_date, a.start_time, a.appointment_status, a.total_amount,
                s.service_name, t.full_name AS therapist_name, r.room_name
         FROM appointments a
         LEFT JOIN services s   ON s.id = a.service_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         LEFT JOIN rooms r      ON r.id = a.room_id
         WHERE a.customer_id = ?
         ORDER BY a.appointment_date DESC, a.start_time DESC, a.id DESC'
    );
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll();

    $stats   = ['total' => count($rows), 'completed' => 0, 'upcoming' => 0, 'cancelled' => 0, 'no_show' => 0];
    $therCnt = [];
    $svcCnt  = [];
    $recent  = [];
    $first   = '';
    $last    = '';
    $today   = date('Y-m-d');

    foreach ($rows as $r) {
        $st = (string)$r['appointment_status'];
        $dt = (string)$r['appointment_date'];
        if ($st === 'completed') {
            $stats['completed']++;
            $therCnt[(string)$r['therapist_name']] = ($therCnt[(string)$r['therapist_name']] ?? 0) + 1;
            $svcCnt[(string)$r['service_name']]    = ($svcCnt[(string)$r['service_name']] ?? 0) + 1;
            if ($last === '') {
                $last = $dt;
            }
            $first = $dt;
        } elseif ($st === 'cancelled') {
            $stats['cancelled']++;
        } elseif ($st === 'no_show') {
            $stats['no_show']++;
        } elseif ($dt >= $today) {
            $stats['upcoming']++;
        }
        if ((int)$r['id'] !== $id && count($recent) < 5) {
            $recent[] = [
                'id'      => (int)$r['id'],
                'code'    => appt_code((int)$r['id']),
                'date'    => appt_date_label($dt),
                'time'    => date('g:i A', strtotime((string)$r['start_time'])),
                'service' => (string)($r['service_name'] ?? '—'),
                'therapist' => (string)($r['therapist_name'] ?? '—'),
                'room'    => (string)($r['room_name'] ?? '—'),
                'status'  => ucfirst(str_replace('_', ' ', $st)),
                'amount'  => peso($r['total_amount']),
            ];
        }
    }

    arsort($therCnt);
    arsort($svcCnt);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
         JOIN appointments a ON a.id = p.appointment_id
         WHERE a.customer_id = ? AND p.payment_status = 'paid'"
    );
    $stmt->execute([$cid]);
    $spent = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT membership_type, membership_status, expiry_date FROM memberships WHERE customer_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$cid]);
    $m = $stmt->fetch();

    return [
        'appointment' => $d,
        'stats'       => $stats,
        'first_visit' => $first !== '' ? appt_date_label($first) : '',
        'last_visit'  => $last !== '' ? appt_date_label($last) : '',
        'top_therapist' => $therCnt ? (string)array_key_first($therCnt) : '',
        'top_service'   => $svcCnt ? (string)array_key_first($svcCnt) : '',
        'spent'       => peso($spent),
        'membership'  => $m ? [
            'type'   => (string)$m['membership_type'],
            'status' => ucfirst((string)$m['membership_status']),
            'until'  => $m['expiry_date'] ? appt_date_label((string)$m['expiry_date']) : '',
        ] : null,
        'recent'      => $recent,
    ];
}