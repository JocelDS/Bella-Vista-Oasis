<?php
declare(strict_types=1);

require_once __DIR__ . '/calendar-data.php';

const TA_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const TA_PHOTO_MAX = 2097152;
const TA_EMP_TYPES = ['full_time' => 'Full-Time', 'part_time' => 'Part-Time', 'contractual' => 'Contractual'];
const TA_EMP_STATUS = ['active' => 'Active', 'inactive' => 'Inactive', 'on_leave' => 'On Leave'];
const TA_SKILLS = [
    'aromatherapy' => ['aromatherapy', 'swedish'],
    'deep tissue'  => ['deep tissue', 'sports'],
    'hot stone'    => ['hot stone', 'reflexology'],
    'facial'       => ['facial', 'skin'],
    'prenatal'     => ['prenatal', 'shiatsu'],
    'couples'      => ['aromatherapy', 'swedish', 'deep tissue', 'sports', 'hot stone', 'reflexology', 'shiatsu', 'prenatal'],
];

function ta_ready(PDO $pdo): bool
{
    foreach (['therapist_shifts', 'therapist_leaves', 'therapist_capacity', 'therapist_assignment_logs'] as $t) {
        try {
            $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        } catch (Throwable $e) {
            return false;
        }
    }
    return true;
}

function ta_has_profile(PDO $pdo): bool
{
    static $cache = null;
    if ($cache === null) {
        try {
            $pdo->query('SELECT first_name, email, profile_photo, employment_status FROM therapists LIMIT 1');
            $cache = true;
        } catch (Throwable $e) {
            $cache = false;
        }
    }
    return $cache;
}

function ta_photo_dir(): string
{
    return __DIR__ . '/../assets/uploads/therapists';
}

function ta_split_name(array $t): array
{
    $first = trim((string)($t['first_name'] ?? ''));
    $last  = trim((string)($t['last_name'] ?? ''));
    $mid   = trim((string)($t['middle_name'] ?? ''));
    if ($first === '' && $last === '') {
        $parts = preg_split('/\s+/', trim((string)$t['full_name'])) ?: [];
        if (count($parts) > 1) {
            $last  = (string)array_pop($parts);
        }
        $first = implode(' ', $parts);
    }
    return [$first, $mid, $last];
}

function ta_fail(string $m, array $extra = []): array
{
    return ['ok' => false, 'error' => $m] + $extra;
}

function ta_compat(string $spec, string $service): bool
{
    $svc  = strtolower($service);
    $spec = strtolower($spec);
    foreach (TA_SKILLS as $key => $skills) {
        if (str_contains($svc, $key)) {
            foreach ($skills as $s) {
                if (str_contains($spec, $s)) {
                    return true;
                }
            }
            return false;
        }
    }
    return true;
}

function ta_base(PDO $pdo): array
{
    $th = [];
    foreach ($pdo->query('SELECT * FROM therapists ORDER BY id ASC')->fetchAll() as $r) {
        $th[(int)$r['id']] = $r;
    }
    $leaves = [];
    foreach ($pdo->query('SELECT id, therapist_id, start_date, end_date, reason FROM therapist_leaves ORDER BY start_date ASC, id ASC')->fetchAll() as $r) {
        $leaves[(int)$r['therapist_id']][] = $r;
    }
    $shifts = [];
    foreach ($pdo->query('SELECT therapist_id, day_of_week, start_time, end_time, is_available FROM therapist_shifts')->fetchAll() as $r) {
        $shifts[(int)$r['therapist_id']][(int)$r['day_of_week']] = [
            'on'    => (int)$r['is_available'] === 1,
            'start' => appt_minutes((string)$r['start_time']),
            'end'   => appt_minutes((string)$r['end_time']),
        ];
    }
    $cap = [];
    foreach ($pdo->query('SELECT therapist_id, maximum_sessions FROM therapist_capacity')->fetchAll() as $r) {
        $cap[(int)$r['therapist_id']] = (int)$r['maximum_sessions'];
    }
    return ['th' => $th, 'leaves' => $leaves, 'shifts' => $shifts, 'cap' => $cap, 'today' => date('Y-m-d')];
}

function ta_emp(array $base, int $tid): string
{
    return (string)($base['th'][$tid]['employment_status'] ?? 'active');
}

function ta_leave(array $base, int $tid, string $date): ?string
{
    if (ta_emp($base, $tid) === 'inactive' && $date >= $base['today']) {
        return 'Inactive';
    }
    $rows = $base['leaves'][$tid] ?? [];
    foreach ($rows as $l) {
        if ($l['start_date'] <= $date && ($l['end_date'] === null || $l['end_date'] >= $date)) {
            return (string)($l['reason'] ?? '') !== '' ? (string)$l['reason'] : 'On leave';
        }
    }
    if (!$rows && isset($base['th'][$tid]) && $base['th'][$tid]['availability_status'] === 'on_leave' && $date >= $base['today']) {
        return 'On leave';
    }
    return null;
}

function ta_shift(array $base, int $tid, string $date): ?array
{
    if (empty($base['shifts'][$tid])) {
        return null;
    }
    $dow = (int)date('N', strtotime($date));
    return $base['shifts'][$tid][$dow] ?? ['on' => false, 'start' => 0, 'end' => 0];
}

function ta_range_label(int $s, int $e): string
{
    return cal_time_label($s) . ' – ' . cal_time_label($e);
}

function ta_eligibility(PDO $pdo, array $base, int $tid, string $date, int $start, int $end, int $excludeId, string $serviceName, bool $lock = false): array
{
    if (!isset($base['th'][$tid])) {
        return ['eligible' => false, 'reason' => 'Therapist not found.', 'compatible' => false];
    }
    $compat = ta_compat((string)$base['th'][$tid]['specialization'], $serviceName);
    if (ta_emp($base, $tid) === 'inactive') {
        return ['eligible' => false, 'reason' => 'Inactive · not accepting new assignments', 'compatible' => $compat];
    }
    $leave  = ta_leave($base, $tid, $date);
    if ($leave !== null) {
        return ['eligible' => false, 'reason' => 'On leave on ' . appt_date_label($date) . ($leave !== 'On leave' ? ' (' . $leave . ')' : ''), 'compatible' => $compat];
    }
    $shift = ta_shift($base, $tid, $date);
    if ($shift !== null) {
        if (!$shift['on']) {
            return ['eligible' => false, 'reason' => 'Not scheduled to work on ' . date('l', strtotime($date)), 'compatible' => $compat];
        }
        if ($start < $shift['start'] || $end > $shift['end']) {
            return ['eligible' => false, 'reason' => 'Outside shift (' . ta_range_label($shift['start'], $shift['end']) . ')', 'compatible' => $compat];
        }
    }
    $stmt = $pdo->prepare(
        "SELECT start_time, end_time FROM appointments
         WHERE therapist_id = ? AND appointment_date = ? AND id <> ? AND appointment_status IN ('pending', 'confirmed', 'completed')" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$tid, $date, $excludeId]);
    foreach ($stmt->fetchAll() as $c) {
        $s = appt_minutes((string)$c['start_time']);
        $e = appt_minutes((string)$c['end_time']);
        if ($start < $e && $s < $end) {
            return ['eligible' => false, 'reason' => 'Already booked ' . ta_range_label($s, $e), 'compatible' => $compat];
        }
    }
    return ['eligible' => true, 'reason' => '', 'compatible' => $compat];
}

function ta_appt_row(PDO $pdo, int $id, bool $lock = false): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.service_id, a.room_id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.total_amount,
                c.full_name AS customer, s.service_name, r.room_name, t.full_name AS therapist_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         LEFT JOIN rooms r ON r.id = a.room_id LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function ta_appt_card(array $a): array
{
    $tid = $a['therapist_id'] !== null ? (int)$a['therapist_id'] : 0;
    return [
        'id'           => (int)$a['id'],
        'code'         => appt_code((int)$a['id']),
        'customer'     => (string)($a['customer'] ?? '—'),
        'service'      => (string)($a['service_name'] ?? '—'),
        'service_id'   => (int)$a['service_id'],
        'room'         => (string)($a['room_name'] ?? '—'),
        'date'         => (string)$a['appointment_date'],
        'date_label'   => appt_date_label((string)$a['appointment_date']),
        'start'        => appt_minutes((string)$a['start_time']),
        'end'          => appt_minutes((string)$a['end_time']),
        'start_label'  => cal_time_label(appt_minutes((string)$a['start_time'])),
        'end_label'    => cal_time_label(appt_minutes((string)$a['end_time'])),
        'status'       => (string)$a['appointment_status'],
        'status_label' => ucfirst(str_replace('_', ' ', (string)$a['appointment_status'])),
        'amount'       => peso($a['total_amount']),
        'therapist_id' => $tid,
        'therapist'    => $tid ? (string)($a['therapist_name'] ?? '') : '',
    ];
}

function ta_recommend(PDO $pdo, int $apptId): ?array
{
    $a = ta_appt_row($pdo, $apptId);
    if (!$a) {
        return null;
    }
    $base = ta_base($pdo);
    $date = (string)$a['appointment_date'];
    $s    = appt_minutes((string)$a['start_time']);
    $e    = appt_minutes((string)$a['end_time']);
    $list = [];
    foreach ($base['th'] as $id => $t) {
        $el = ta_eligibility($pdo, $base, $id, $date, $s, $e, $apptId, (string)$a['service_name']);
        $list[] = [
            'id'             => $id,
            'name'           => (string)$t['full_name'],
            'photo'          => (string)($t['profile_photo'] ?? ''),
            'specialization' => (string)$t['specialization'],
            'eligible'       => $el['eligible'],
            'compatible'     => $el['compatible'],
            'reason'         => $el['reason'],
            'current'        => $id === (int)($a['therapist_id'] ?? 0),
        ];
    }
    usort($list, static function (array $x, array $y): int {
        $rx = ($x['eligible'] ? 0 : 2) + ($x['compatible'] ? 0 : 1);
        $ry = ($y['eligible'] ? 0 : 2) + ($y['compatible'] ? 0 : 1);
        return $rx <=> $ry ?: $x['id'] <=> $y['id'];
    });
    return ['appointment' => ta_appt_card($a), 'therapists' => $list];
}

function ta_assign(PDO $pdo, int $apptId, int $tid, int $adminId, bool $override, string $notes): array
{
    $notes = mb_substr(trim($notes), 0, 300);
    try {
        $pdo->beginTransaction();
        $a = ta_appt_row($pdo, $apptId, true);
        if (!$a) {
            $pdo->rollBack();
            return ta_fail('That appointment no longer exists.');
        }
        if (!in_array((string)$a['appointment_status'], ['pending', 'confirmed'], true)) {
            $pdo->rollBack();
            return ta_fail('Only pending or confirmed appointments can be assigned.');
        }
        if ((string)$a['appointment_date'] < date('Y-m-d')) {
            $pdo->rollBack();
            return ta_fail('Past appointments cannot be reassigned.');
        }
        $prev = $a['therapist_id'] !== null ? (int)$a['therapist_id'] : 0;

        if ($tid === 0) {
            if ($prev === 0) {
                $pdo->rollBack();
                return ta_fail('This appointment is already unassigned.');
            }
            $pdo->prepare('UPDATE appointments SET therapist_id = NULL WHERE id = ?')->execute([$apptId]);
            $msg = 'Therapist removed. The booking is back in the unassigned queue.';
        } else {
            if ($tid === $prev) {
                $pdo->rollBack();
                return ta_fail('That therapist is already assigned to this appointment.');
            }
            $base = ta_base($pdo);
            $el   = ta_eligibility($pdo, $base, $tid, (string)$a['appointment_date'], appt_minutes((string)$a['start_time']), appt_minutes((string)$a['end_time']), $apptId, (string)$a['service_name'], true);
            if (!$el['eligible']) {
                $pdo->rollBack();
                return ta_fail($el['reason'] !== '' ? $el['reason'] : 'That therapist is not available.');
            }
            if (!$el['compatible'] && !$override) {
                $pdo->rollBack();
                return ta_fail($base['th'][$tid]['full_name'] . ' (' . $base['th'][$tid]['specialization'] . ') is not a specialization match for ' . $a['service_name'] . '.', ['needs_override' => true]);
            }
            $pdo->prepare('UPDATE appointments SET therapist_id = ? WHERE id = ?')->execute([$tid, $apptId]);
            $msg = $base['th'][$tid]['full_name'] . ' is now assigned to ' . appt_code($apptId) . '.' . (!$el['compatible'] ? ' (Specialization override recorded.)' : '');
            if (!$el['compatible']) {
                $notes = trim('Override: not a specialization match. ' . $notes);
            }
        }
        $pdo->prepare('INSERT INTO therapist_assignment_logs (appointment_id, previous_therapist_id, new_therapist_id, assigned_by, notes) VALUES (?, ?, ?, ?, ?)')
            ->execute([$apptId, $prev ?: null, $tid ?: null, $adminId ?: null, $notes !== '' ? $notes : null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO therapists] ' . $e->getMessage());
        return ta_fail('Unable to save the assignment right now.');
    }
    return ['ok' => true, 'message' => $msg];
}

function ta_next_label(string $date, int $start, string $today): string
{
    if ($date === $today) {
        return 'Today ' . cal_time_label($start);
    }
    if ($date === date('Y-m-d', strtotime($today . ' +1 day'))) {
        return 'Tomorrow ' . cal_time_label($start);
    }
    return date('M j', strtotime($date)) . ' · ' . cal_time_label($start);
}

function ta_free(array $base, int $tid, string $date, array $busy, int $nowMin): ?array
{
    if (ta_leave($base, $tid, $date) !== null) {
        return [];
    }
    $sh = ta_shift($base, $tid, $date);
    if ($sh === null) {
        return null;
    }
    if (!$sh['on']) {
        return [];
    }
    $from = $date === $base['today'] ? max($sh['start'], $nowMin) : $sh['start'];
    $out  = [];
    foreach (cal_free_windows($busy, $from, $sh['end'], 0, 30) as [$a, $b]) {
        $out[] = ta_range_label($a, $b);
    }
    return $out;
}

function ta_conflicts(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $by[(int)$r['therapist_id']][(string)$r['appointment_date']][] = $r;
    }
    $out = [];
    foreach ($by as $tid => $days) {
        foreach ($days as $date => $list) {
            usort($list, static fn(array $a, array $b): int => strcmp((string)$a['start_time'], (string)$b['start_time']));
            $maxEnd = -1;
            $prev   = null;
            foreach ($list as $r) {
                $s = appt_minutes((string)$r['start_time']);
                $e = appt_minutes((string)$r['end_time']);
                if ($prev !== null && $s < $maxEnd) {
                    $out[] = ['therapist_id' => $tid, 'date' => $date, 'a' => (int)$prev['id'], 'b' => (int)$r['id']];
                }
                if ($e > $maxEnd) {
                    $maxEnd = $e;
                    $prev   = $r;
                }
            }
        }
    }
    return $out;
}

function ta_load(PDO $pdo): array
{
    $base  = ta_base($pdo);
    $today = $base['today'];
    $now   = cal_now_minutes();

    $rows = $pdo->query(
        "SELECT a.id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, c.full_name AS customer, s.service_name
         FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         WHERE a.therapist_id IS NOT NULL AND a.appointment_status IN ('pending', 'confirmed', 'completed')
         ORDER BY a.appointment_date ASC, a.start_time ASC"
    )->fetchAll();

    $by = [];
    foreach ($rows as $r) {
        $by[(int)$r['therapist_id']][] = $r;
    }

    $serviceNames = array_column($pdo->query('SELECT service_name FROM services ORDER BY id ASC')->fetchAll(), 'service_name');

    $out = [];
    $availableCount = 0;
    $assignedSessions = 0;
    $todayTotal = 0;
    $capTotal = 0;
    $leaveNow = [];
    $inactiveCount = 0;

    foreach ($base['th'] as $tid => $t) {
        $list = $by[$tid] ?? [];
        $w = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'today' => 0, 'current' => 0, 'upcoming' => 0, 'total' => count($list)];
        $todayList = [];
        $busyToday = [];
        $next = null;
        foreach ($list as $r) {
            $st = (string)$r['appointment_status'];
            $d  = (string)$r['appointment_date'];
            $s  = appt_minutes((string)$r['start_time']);
            $e  = appt_minutes((string)$r['end_time']);
            $w[$st]++;
            if ($d === $today) {
                $w['today']++;
                $busyToday[] = [$s, $e];
                if ($st !== 'completed') {
                    $w['current']++;
                }
                $todayList[] = ['id' => (int)$r['id'], 'time' => ta_range_label($s, $e), 'customer' => (string)$r['customer'], 'service' => (string)$r['service_name'], 'status' => ucfirst($st)];
            }
            if ($st !== 'completed' && ($d > $today || ($d === $today && $s >= $now))) {
                $w['upcoming']++;
                if ($next === null) {
                    $next = ['label' => ta_next_label($d, $s, $today), 'customer' => (string)$r['customer'], 'service' => (string)$r['service_name']];
                }
            }
            if ($st !== 'completed' && $d >= $today) {
                $assignedSessions++;
            }
        }
        $leave = ta_leave($base, $tid, $today);
        $emp   = ta_emp($base, $tid);
        $state = $emp === 'inactive' ? 'inactive' : ($leave !== null ? 'on_leave' : 'available');
        if ($state === 'available') {
            $availableCount++;
        } elseif ($state === 'inactive') {
            $inactiveCount++;
        } else {
            $leaveNow[] = $tid;
        }
        $cap = $base['cap'][$tid] ?? null;
        if ($state === 'available' && $cap) {
            $capTotal += $cap;
        }
        $todayTotal += $w['today'];
        $free = ta_free($base, $tid, $today, $busyToday, $now);
        $nm = ta_split_name($t);
        $sh = [];
        foreach (TA_DAYS as $n => $label) {
            $x = $base['shifts'][$tid][$n] ?? null;
            $sh[] = ['day' => $n, 'label' => $label, 'on' => $x ? $x['on'] : false, 'start' => $x ? sprintf('%02d:%02d', intdiv($x['start'], 60), $x['start'] % 60) : '', 'end' => $x ? sprintf('%02d:%02d', intdiv($x['end'], 60), $x['end'] % 60) : ''];
        }
        $out[] = [
            'id'             => $tid,
            'name'           => (string)$t['full_name'],
            'specialization' => (string)$t['specialization'],
            'status'         => $state,
            'status_raw'     => (string)$t['availability_status'],
            'first_name'     => $nm[0],
            'middle_name'    => $nm[1],
            'last_name'      => $nm[2],
            'email'          => (string)($t['email'] ?? ''),
            'phone'          => (string)($t['phone'] ?? ''),
            'address'        => (string)($t['address'] ?? ''),
            'photo'          => (string)($t['profile_photo'] ?? ''),
            'years'          => isset($t['years_experience']) && $t['years_experience'] !== null ? (int)$t['years_experience'] : null,
            'certifications' => (string)($t['certifications'] ?? ''),
            'hired'          => (string)($t['employment_date'] ?? ''),
            'hired_label'    => !empty($t['employment_date']) ? appt_date_label((string)$t['employment_date']) : '',
            'emp_type'       => (string)($t['employment_type'] ?? ''),
            'emp_type_label' => TA_EMP_TYPES[(string)($t['employment_type'] ?? '')] ?? '',
            'emp_status'     => $emp,
            'availability_pref' => $t['availability_status'] === 'available' ? 'available' : 'unavailable',
            'leave_reason'   => $leave !== null && $leave !== 'On leave' && $leave !== 'Inactive' ? $leave : '',
            'workload'       => $w,
            'capacity'       => $cap,
            'cap_pct'        => $cap ? min(100, (int)round($w['today'] / $cap * 100)) : null,
            'over_capacity'  => $cap ? $w['today'] > $cap : false,
            'today_list'     => $todayList,
            'next'           => $next,
            'free_today'     => $free,
            'shifts_defined' => !empty($base['shifts'][$tid]),
            'shifts'         => $sh,
            'services'       => array_values(array_filter($serviceNames, static fn(string $n): bool => ta_compat((string)$t['specialization'], $n))),
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT a.id, a.service_id, a.room_id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.total_amount,
                c.full_name AS customer, s.service_name, r.room_name, t.full_name AS therapist_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         LEFT JOIN rooms r ON r.id = a.room_id LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.appointment_status IN ('pending', 'confirmed') AND a.appointment_date >= ?
         ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 300"
    );
    $stmt->execute([$today]);
    $future = $stmt->fetchAll();

    $queue = [];
    $assignable = [];
    $eligibleN = 0;
    $coveredN = 0;
    foreach ($future as $a) {
        $card = ta_appt_card($a);
        $assignable[] = $card;
        $eligibleN++;
        $reason = '';
        if ($card['therapist_id'] === 0) {
            $reason = 'unassigned';
        } elseif (ta_leave($base, $card['therapist_id'], $card['date']) !== null) {
            $reason = 'on_leave';
        } else {
            $coveredN++;
        }
        if ($reason !== '') {
            $queue[] = $card + ['reason' => $reason];
        }
    }

    $alerts = [];
    $un = count(array_filter($queue, static fn(array $q): bool => $q['reason'] === 'unassigned'));
    $rl = count($queue) - $un;
    if ($un > 0) {
        $alerts[] = ['type' => 'unassigned', 'level' => 'warn', 'title' => $un . ($un === 1 ? ' unassigned appointment' : ' unassigned appointments'), 'detail' => 'These bookings need a therapist.', 'action' => 'queue', 'label' => 'Open Queue'];
    }
    if ($rl > 0) {
        $alerts[] = ['type' => 'reassign', 'level' => 'warn', 'title' => $rl . ($rl === 1 ? ' booking' : ' bookings') . ' assigned to a therapist on leave', 'detail' => 'Reassign these to an available therapist.', 'action' => 'queue', 'label' => 'Reassign'];
    }
    foreach ($leaveNow as $tid) {
        $n = 0;
        foreach ($by[$tid] ?? [] as $r) {
            if ($r['appointment_status'] !== 'completed' && $r['appointment_date'] >= $today && ta_leave($base, $tid, (string)$r['appointment_date']) !== null) {
                $n++;
            }
        }
        $alerts[] = ['type' => 'leave', 'level' => 'info', 'title' => $base['th'][$tid]['full_name'] . ' is on leave', 'detail' => $n ? $n . ' session' . ($n === 1 ? '' : 's') . ' still assigned during the leave.' : 'No sessions are assigned during the leave.', 'action' => 'availability', 'id' => $tid, 'label' => 'Manage'];
    }
    foreach (array_slice(ta_conflicts(array_filter($future, static fn(array $r): bool => $r['therapist_id'] !== null)), 0, 5) as $c) {
        $alerts[] = ['type' => 'conflict', 'level' => 'danger', 'title' => 'Schedule conflict: ' . $base['th'][$c['therapist_id']]['full_name'], 'detail' => appt_code($c['a']) . ' overlaps ' . appt_code($c['b']) . ' on ' . appt_date_label($c['date']) . '.', 'action' => 'assign', 'id' => $c['b'], 'label' => 'Resolve'];
    }
    $perDay = [];
    foreach ($future as $a) {
        if ($a['therapist_id'] !== null) {
            $k = (int)$a['therapist_id'] . '|' . $a['appointment_date'];
            $perDay[$k] = ($perDay[$k] ?? 0) + 1;
        }
    }
    foreach ($perDay as $k => $n) {
        [$tid, $d] = explode('|', $k);
        $cap = $base['cap'][(int)$tid] ?? null;
        if ($cap && $n > $cap) {
            $alerts[] = ['type' => 'overload', 'level' => 'warn', 'title' => $base['th'][(int)$tid]['full_name'] . ' is over capacity', 'detail' => $n . ' pending/confirmed sessions on ' . appt_date_label($d) . ' (limit ' . $cap . ').', 'action' => 'schedule', 'id' => (int)$tid, 'label' => 'View'];
        }
    }
    $soon = date('Y-m-d', strtotime($today . ' +2 days'));
    $pend = count(array_filter($future, static fn(array $a): bool => $a['appointment_status'] === 'pending' && $a['appointment_date'] <= $soon));
    if ($pend > 0) {
        $alerts[] = ['type' => 'pending', 'level' => 'info', 'title' => $pend . ($pend === 1 ? ' pending session' : ' pending sessions') . ' within 48 hours', 'detail' => 'Not yet confirmed.', 'action' => 'appointments', 'label' => 'Review'];
    }

    $coverage = $eligibleN ? (int)round($coveredN / $eligibleN * 100) : null;

    return [
        'today'      => $today,
        'now_min'    => $now,
        'therapists' => $out,
        'queue'      => $queue,
        'assignable' => $assignable,
        'alerts'     => $alerts,
        'services'   => $serviceNames,
        'summary'    => [
            'total'        => count($out),
            'available'    => $availableCount,
            'on_leave'     => count($out) - $availableCount - $inactiveCount,
            'inactive'     => $inactiveCount,
            'assigned'     => $assignedSessions,
            'eligible'     => $eligibleN,
            'covered'      => $coveredN,
            'coverage'     => $coverage,
            'workload'     => $todayTotal,
            'capacity'     => $capTotal,
            'utilization'  => $capTotal ? (int)round($todayTotal / $capTotal * 100) : null,
            'unassigned'   => $un,
        ],
    ];
}

function ta_week(PDO $pdo, string $anyDate, int $tid = 0): array
{
    $base  = ta_base($pdo);
    $today = $base['today'];
    $now   = cal_now_minutes();
    [$from, $to] = cal_range('week', $anyDate);

    $stmt = $pdo->prepare(
        "SELECT a.id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, c.full_name AS customer, s.service_name
         FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         WHERE a.therapist_id IS NOT NULL AND a.appointment_date BETWEEN ? AND ? AND a.appointment_status IN ('pending', 'confirmed', 'completed')
         ORDER BY a.appointment_date ASC, a.start_time ASC"
    );
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
    $conf = [];
    foreach (ta_conflicts($rows) as $c) {
        $conf[$c['a']] = true;
        $conf[$c['b']] = true;
    }

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
        $days[] = ['date' => $d, 'weekday' => date('D', strtotime($d)), 'day' => date('j', strtotime($d)), 'month' => date('M', strtotime($d)), 'is_today' => $d === $today];
    }

    $by = [];
    foreach ($rows as $r) {
        $by[(int)$r['therapist_id']][(string)$r['appointment_date']][] = $r;
    }

    $out = [];
    foreach ($base['th'] as $id => $t) {
        if ($tid > 0 && $id !== $tid) {
            continue;
        }
        $inactive = ta_emp($base, $id) === 'inactive';
        if ($inactive && empty($by[$id])) {
            continue;
        }
        $cells = [];
        foreach ($days as $d) {
            $date  = $d['date'];
            $list  = $by[$id][$date] ?? [];
            $leave = ta_leave($base, $id, $date);
            $sh    = ta_shift($base, $id, $date);
            $busy  = [];
            $sess  = [];
            foreach ($list as $r) {
                $s = appt_minutes((string)$r['start_time']);
                $e = appt_minutes((string)$r['end_time']);
                $busy[] = [$s, $e];
                $sess[] = ['id' => (int)$r['id'], 'time' => ta_range_label($s, $e), 'start_label' => cal_time_label($s), 'customer' => (string)$r['customer'], 'service' => (string)$r['service_name'], 'status' => (string)$r['appointment_status'], 'conflict' => isset($conf[(int)$r['id']])];
            }
            $state = 'none';
            if ($inactive) {
                $state = 'inactive';
            } elseif ($leave !== null) {
                $state = 'leave';
            } elseif ($sh !== null && !$sh['on']) {
                $state = 'off';
            } elseif ($sess) {
                $state = 'busy';
            } elseif ($sh !== null) {
                $state = 'open';
            }
            $free = $date >= $today ? ta_free($base, $id, $date, $busy, $now) : [];
            $cells[] = [
                'date'     => $date,
                'state'    => $state,
                'leave'    => $leave !== null && $leave !== 'On leave' ? $leave : '',
                'shift'    => $sh !== null && $sh['on'] ? ta_range_label($sh['start'], $sh['end']) : '',
                'sessions' => $sess,
                'free'     => $free ?? [],
                'conflict' => (bool)array_filter($sess, static fn(array $x): bool => $x['conflict']),
            ];
        }
        $out[] = ['id' => $id, 'name' => (string)$t['full_name'], 'photo' => (string)($t['profile_photo'] ?? ''), 'inactive' => $inactive, 'specialization' => (string)$t['specialization'], 'shifts_defined' => !empty($base['shifts'][$id]), 'cells' => $cells];
    }

    return [
        'from'    => $from,
        'to'      => $to,
        'label'   => date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)),
        'is_current' => $from <= $today && $today <= $to,
        'days'    => $days,
        'rows'    => $out,
    ];
}

function ta_detail(PDO $pdo, int $id): ?array
{
    $data = ta_load($pdo);
    $found = null;
    foreach ($data['therapists'] as $t) {
        if ($t['id'] === $id) {
            $found = $t;
            break;
        }
    }
    if (!$found) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT a.id, a.service_id, a.room_id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.total_amount,
                c.full_name AS customer, s.service_name, r.room_name, t.full_name AS therapist_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id
         LEFT JOIN rooms r ON r.id = a.room_id LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.therapist_id = ? AND a.appointment_status IN ('pending', 'confirmed') AND a.appointment_date >= ?
         ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 20"
    );
    $stmt->execute([$id, $data['today']]);
    $upcoming = array_map('ta_appt_card', $stmt->fetchAll());

    $stmt = $pdo->prepare('SELECT start_date, end_date, reason FROM therapist_leaves WHERE therapist_id = ? ORDER BY start_date DESC, id DESC LIMIT 6');
    $stmt->execute([$id]);
    $leaves = [];
    foreach ($stmt->fetchAll() as $l) {
        $leaves[] = [
            'from'   => appt_date_label((string)$l['start_date']),
            'to'     => $l['end_date'] ? appt_date_label((string)$l['end_date']) : 'Until further notice',
            'reason' => (string)($l['reason'] ?? ''),
        ];
    }
    return ['therapist' => $found, 'upcoming' => $upcoming, 'leaves' => $leaves, 'today' => $data['today']];
}

function ta_save_availability(PDO $pdo, array $in, int $adminId): array
{
    $tid    = (int)($in['therapist_id'] ?? 0);
    $status = (string)($in['availability_status'] ?? '');
    $eff    = appt_parse_date((string)($in['effective_date'] ?? '')) ?? date('Y-m-d');
    $endRaw = trim((string)($in['end_date'] ?? ''));
    $notes  = mb_substr(trim((string)($in['notes'] ?? '')), 0, 300);
    $today  = date('Y-m-d');

    $stmt = $pdo->prepare('SELECT id, full_name, availability_status FROM therapists WHERE id = ?');
    $stmt->execute([$tid]);
    $t = $stmt->fetch();
    if (!$t) {
        return ta_fail('Select a valid therapist.');
    }
    if (!in_array($status, ['available', 'on_leave'], true)) {
        return ta_fail('Choose a valid availability status.');
    }
    $end = null;
    if ($endRaw !== '') {
        $end = appt_parse_date($endRaw);
        if ($end === null || $end < $eff) {
            return ta_fail('The leave end date must be on or after the effective date.');
        }
    }

    try {
        $pdo->beginTransaction();
        if ($status === 'on_leave') {
            $pdo->prepare('INSERT INTO therapist_leaves (therapist_id, start_date, end_date, reason, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$tid, $eff, $end, $notes !== '' ? $notes : null, $adminId ?: null]);
            if ($eff <= $today && ($end === null || $end >= $today)) {
                $pdo->prepare("UPDATE therapists SET availability_status = 'on_leave' WHERE id = ?")->execute([$tid]);
            }
        } else {
            $stmt = $pdo->prepare('SELECT id, start_date FROM therapist_leaves WHERE therapist_id = ? AND (end_date IS NULL OR end_date >= ?)');
            $stmt->execute([$tid, $eff]);
            foreach ($stmt->fetchAll() as $l) {
                if ($l['start_date'] >= $eff) {
                    $pdo->prepare('DELETE FROM therapist_leaves WHERE id = ?')->execute([$l['id']]);
                } else {
                    $pdo->prepare('UPDATE therapist_leaves SET end_date = ? WHERE id = ?')->execute([date('Y-m-d', strtotime($eff . ' -1 day')), $l['id']]);
                }
            }
            if ($eff <= $today) {
                $pdo->prepare("UPDATE therapists SET availability_status = 'available' WHERE id = ?")->execute([$tid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO therapists] ' . $e->getMessage());
        return ta_fail('Unable to update availability right now.');
    }

    $msg = $t['full_name'] . ($status === 'on_leave' ? ' is marked on leave from ' . appt_date_label($eff) . '.' : ' is marked available from ' . appt_date_label($eff) . '.');
    if ($status === 'on_leave') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE therapist_id = ? AND appointment_status IN ('pending', 'confirmed') AND appointment_date >= ? AND (? IS NULL OR appointment_date <= ?)");
        $q->execute([$tid, $eff, $end, $end]);
        $n = (int)$q->fetchColumn();
        if ($n > 0) {
            $msg .= ' ' . $n . ' assigned session' . ($n === 1 ? '' : 's') . ' fall in this period and now appear in the queue for reassignment.';
        }
    }
    return ['ok' => true, 'message' => $msg];
}

function ta_save_shifts(PDO $pdo, array $in): array
{
    $tid = (int)($in['therapist_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, full_name FROM therapists WHERE id = ?');
    $stmt->execute([$tid]);
    $t = $stmt->fetch();
    if (!$t) {
        return ta_fail('Select a valid therapist.');
    }
    $capRaw = trim((string)($in['maximum_sessions'] ?? ''));
    $cap = null;
    if ($capRaw !== '') {
        if (!ctype_digit($capRaw) || (int)$capRaw < 1 || (int)$capRaw > 20) {
            return ta_fail('Daily capacity must be a whole number from 1 to 20.');
        }
        $cap = (int)$capRaw;
    }
    $clear = !empty($in['clear_shifts']);
    $rows  = [];
    if (!$clear) {
        foreach (TA_DAYS as $n => $label) {
            $on = !empty($in['shift_on'][$n]);
            $ps = appt_parse_time(trim((string)($in['shift_start'][$n] ?? '')));
            $pe = appt_parse_time(trim((string)($in['shift_end'][$n] ?? '')));
            if ($on) {
                if ($ps === null || $pe === null) {
                    return ta_fail('Enter a start and end time for ' . $label . '.');
                }
                if (appt_minutes($ps) >= appt_minutes($pe)) {
                    return ta_fail($label . ': the shift must end after it starts.');
                }
            }
            $rows[$n] = [$ps ?? '09:00:00', ($pe !== null && $ps !== null && appt_minutes($ps) < appt_minutes($pe)) ? $pe : '17:00:00', $on ? 1 : 0];
        }
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM therapist_shifts WHERE therapist_id = ?')->execute([$tid]);
        foreach ($rows as $n => $r) {
            $pdo->prepare('INSERT INTO therapist_shifts (therapist_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?)')
                ->execute([$tid, $n, $r[0], $r[1], $r[2]]);
        }
        $pdo->prepare('DELETE FROM therapist_capacity WHERE therapist_id = ?')->execute([$tid]);
        if ($cap !== null) {
            $pdo->prepare('INSERT INTO therapist_capacity (therapist_id, maximum_sessions) VALUES (?, ?)')->execute([$tid, $cap]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO therapists] ' . $e->getMessage());
        return ta_fail('Unable to save the shifts right now.');
    }
    return ['ok' => true, 'message' => 'Weekly shifts and capacity saved for ' . $t['full_name'] . '.'];
}

function ta_store_photo(array $f, ?callable $isUploaded = null, ?callable $mover = null): array
{
    $isUploaded = $isUploaded ?? 'is_uploaded_file';
    $mover      = $mover ?? 'move_uploaded_file';
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ta_fail('The photo is too large (max 2 MB).');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ta_fail('The photo could not be uploaded. Please try again.');
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !$isUploaded($tmp)) {
        return ta_fail('Invalid photo upload.');
    }
    if ((int)($f['size'] ?? 0) > TA_PHOTO_MAX || (int)filesize($tmp) > TA_PHOTO_MAX) {
        return ta_fail('The photo is too large (max 2 MB).');
    }
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ta_fail('Photo must be a JPG, JPEG, PNG or WEBP image.');
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $tmp);
        finfo_close($fi);
    }
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime]) || @getimagesize($tmp) === false) {
        return ta_fail('Photo must be a valid JPG, PNG or WEBP image.');
    }
    $dir = ta_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ta_fail('The photo folder could not be created.');
    }
    $name = 'th_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!$mover($tmp, $dir . '/' . $name)) {
        return ta_fail('The photo could not be saved.');
    }
    return ['ok' => true, 'file' => $name];
}

function ta_delete_photo(?string $name): void
{
    if ($name !== null && preg_match('/^th_[a-f0-9]{16}\.(jpg|png|webp)$/', $name)) {
        $p = ta_photo_dir() . '/' . $name;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

function ta_save_therapist(PDO $pdo, array $in, array $files, ?int $id, ?callable $isUploaded = null, ?callable $mover = null): array
{
    if (!ta_has_profile($pdo)) {
        return ta_fail('Import database/therapist-registration-extensions.sql first.');
    }
    $old = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM therapists WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            return ta_fail('That therapist no longer exists.');
        }
    }

    $name = '/^[\p{L}][\p{L}\s.\'\-]*$/u';
    $first = trim((string)($in['first_name'] ?? ''));
    $mid   = trim((string)($in['middle_name'] ?? ''));
    $last  = trim((string)($in['last_name'] ?? ''));
    if ($first === '' || mb_strlen($first) > 60 || !preg_match($name, $first)) {
        return ta_fail('Enter a valid first name.');
    }
    if ($mid !== '' && (mb_strlen($mid) > 60 || !preg_match($name, $mid))) {
        return ta_fail('Enter a valid middle name or leave it blank.');
    }
    if ($last === '' || mb_strlen($last) > 60 || !preg_match($name, $last)) {
        return ta_fail('Enter a valid last name.');
    }
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ta_fail('Enter a valid email address.');
    }
    $phone = trim((string)($in['phone'] ?? ''));
    if (!preg_match('/^[0-9+()\s\-]{7,20}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 7) {
        return ta_fail('Enter a valid contact number.');
    }
    $addr = trim((string)($in['address'] ?? ''));
    if (mb_strlen($addr) > 300) {
        return ta_fail('The address is too long (max 300 characters).');
    }
    $spec = trim((string)($in['specialization'] ?? ''));
    if ($spec === '' || mb_strlen($spec) < 3 || mb_strlen($spec) > 150) {
        return ta_fail('Specialization is required (3 to 150 characters).');
    }
    $yrs = trim((string)($in['years_experience'] ?? ''));
    if ($yrs === '' || !ctype_digit($yrs) || (int)$yrs > 60) {
        return ta_fail('Years of experience must be a whole number from 0 to 60.');
    }
    $cert = trim((string)($in['certifications'] ?? ''));
    if (mb_strlen($cert) > 500) {
        return ta_fail('Certifications are too long (max 500 characters).');
    }
    $hired = appt_parse_date((string)($in['employment_date'] ?? ''));
    if ($hired === null || $hired < date('Y-m-d', strtotime('-60 years')) || $hired > date('Y-m-d', strtotime('+1 year'))) {
        return ta_fail('Enter a valid employment date.');
    }
    $type = (string)($in['employment_type'] ?? '');
    if (!isset(TA_EMP_TYPES[$type])) {
        return ta_fail('Choose an employment type.');
    }
    $emp = (string)($in['employment_status'] ?? '');
    if (!isset(TA_EMP_STATUS[$emp])) {
        return ta_fail('Choose an employment status.');
    }
    $avail = (string)($in['availability'] ?? '');
    if (!in_array($avail, ['available', 'unavailable'], true)) {
        return ta_fail('Choose the initial availability.');
    }
    $availability = ($emp === 'active' && $avail === 'available') ? 'available' : 'on_leave';

    $stmt = $pdo->prepare('SELECT id FROM therapists WHERE LOWER(email) = ? AND id <> ?');
    $stmt->execute([$email, $id ?? 0]);
    if ($stmt->fetch()) {
        return ta_fail('A therapist with that email address is already registered.');
    }

    $newPhoto = null;
    $file = $files['profile_photo'] ?? null;
    if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $p = ta_store_photo($file, $isUploaded, $mover);
        if (empty($p['ok'])) {
            return $p;
        }
        $newPhoto = $p['file'];
    }

    $full = trim($first . ' ' . ($mid !== '' ? $mid . ' ' : '') . $last);
    $photoCol = $old ? ($old['profile_photo'] ?? null) : null;
    $removeOld = false;
    if ($newPhoto !== null) {
        $photoCol  = $newPhoto;
        $removeOld = $old !== null && !empty($old['profile_photo']);
    } elseif ($old && !empty($in['remove_photo'])) {
        $photoCol  = null;
        $removeOld = !empty($old['profile_photo']);
    }

    try {
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO therapists (full_name, specialization, availability_status, first_name, middle_name, last_name, email, phone, address, profile_photo, years_experience, certifications, employment_date, employment_type, employment_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$full, $spec, $availability, $first, $mid !== '' ? $mid : null, $last, $email, $phone, $addr !== '' ? $addr : null, $photoCol, (int)$yrs, $cert !== '' ? $cert : null, $hired, $type, $emp]);
            $newId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE therapists SET full_name = ?, specialization = ?, availability_status = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, address = ?, profile_photo = ?, years_experience = ?, certifications = ?, employment_date = ?, employment_type = ?, employment_status = ? WHERE id = ?'
            )->execute([$full, $spec, $availability, $first, $mid !== '' ? $mid : null, $last, $email, $phone, $addr !== '' ? $addr : null, $photoCol, (int)$yrs, $cert !== '' ? $cert : null, $hired, $type, $emp, $id]);
            $newId = $id;
        }
    } catch (Throwable $e) {
        ta_delete_photo($newPhoto);
        error_log('[BVO therapists] ' . $e->getMessage());
        if (str_contains($e->getMessage(), 'uq_therapists_email') || str_contains(strtolower($e->getMessage()), 'duplicate') || str_contains(strtolower($e->getMessage()), 'unique')) {
            return ta_fail('A therapist with that email address is already registered.');
        }
        return ta_fail('Unable to save the therapist right now.');
    }
    if ($removeOld) {
        ta_delete_photo((string)$old['profile_photo']);
    }

    $msg = $id === null ? $full . ' was registered as a new therapist.' : $full . ' was updated.';
    if ($id !== null && $availability === 'on_leave') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE therapist_id = ? AND appointment_status IN ('pending', 'confirmed') AND appointment_date >= ?");
        $q->execute([$newId, date('Y-m-d')]);
        $n = (int)$q->fetchColumn();
        if ($n > 0) {
            $msg .= ' ' . $n . ' upcoming session' . ($n === 1 ? '' : 's') . ' now need' . ($n === 1 ? 's' : '') . ' reassignment.';
        }
    }
    return ['ok' => true, 'id' => $newId, 'message' => $msg];
}