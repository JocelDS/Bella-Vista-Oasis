<?php
declare(strict_types=1);

const ANALYTICS_ACTIVE_STATUSES = "'pending', 'confirmed', 'completed'";

function analytics_revenue_condition(): string
{
    return "(p.payment_status = 'paid' OR (p.payment_status = 'deposit' AND (a.appointment_status IN (" . ANALYTICS_ACTIVE_STATUSES . ") OR a.cancellation_forfeited = 1)))";
}

function analytics_status_label(string $status): string
{
    $clean = trim($status);
    if ($clean === '') {
        return 'Unknown';
    }

    return ucfirst(str_replace('_', ' ', strtolower($clean)));
}

function analytics_status_colors(): array
{
    return [
        'completed' => '#173D30',
        'confirmed' => '#C4B58D',
        'pending'   => '#A3AA91',
        'cancelled' => '#B58A78',
        'no_show'   => '#85877F',
    ];
}

function analytics_daily_report(PDO $pdo, string $day): array
{
    $stmt = $pdo->prepare('SELECT appointment_status, COUNT(*) AS c FROM appointments WHERE appointment_date = ? GROUP BY appointment_status');
    $stmt->execute([$day]);
    $counts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $counts[(string)$r['appointment_status']] = (int)$r['c'];
    }
    $total  = array_sum($counts);
    $active = $counts['pending'] + $counts['confirmed'] + $counts['completed'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN appointments a ON a.id = p.appointment_id WHERE p.payment_status = 'paid' AND a.appointment_date = ?");
    $stmt->execute([$day]);
    $paid = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN appointments a ON a.id = p.appointment_id
         WHERE p.payment_status = 'deposit' AND a.appointment_date = ?
           AND (a.appointment_status IN (" . ANALYTICS_ACTIVE_STATUSES . ") OR a.cancellation_forfeited = 1)"
    );
    $stmt->execute([$day]);
    $down = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.start_time, a.end_time, a.appointment_status, t.id AS tid, t.full_name AS therapist, t.specialization,
                s.service_name, r.room_name, c.full_name AS customer
         FROM appointments a
         JOIN therapists t ON t.id = a.therapist_id
         LEFT JOIN services s ON s.id = a.service_id
         LEFT JOIN rooms r ON r.id = a.room_id
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date = ? AND a.appointment_status IN (" . ANALYTICS_ACTIVE_STATUSES . ")
         ORDER BY t.full_name ASC, a.start_time ASC"
    );
    $stmt->execute([$day]);
    $therapists = [];
    foreach ($stmt->fetchAll() as $r) {
        $tid = (int)$r['tid'];
        if (!isset($therapists[$tid])) {
            $therapists[$tid] = ['name' => (string)$r['therapist'], 'specialization' => (string)($r['specialization'] ?? ''), 'sessions' => []];
        }
        $therapists[$tid]['sessions'][] = [
            'time'     => date('g:i A', strtotime((string)$r['start_time'])) . ' – ' . date('g:i A', strtotime((string)$r['end_time'])),
            'service'  => (string)($r['service_name'] ?? '—'),
            'room'     => (string)($r['room_name'] ?? '—'),
            'customer' => (string)($r['customer'] ?? '—'),
            'status'   => analytics_status_label((string)$r['appointment_status']),
        ];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND therapist_id IS NULL AND appointment_status IN ('pending', 'confirmed')");
    $stmt->execute([$day]);

    return [
        'day'        => $day,
        'label'      => date('l, F j, Y', strtotime($day)),
        'total'      => $total,
        'active'     => $active,
        'counts'     => $counts,
        'paid'       => $paid,
        'down'       => $down,
        'revenue'    => $paid + $down,
        'therapists' => array_values($therapists),
        'unassigned' => (int)$stmt->fetchColumn(),
    ];
}

function analytics_normalize_period(?string $period): string
{
    $allowed = ['today', 'week', '7d', '30d', 'month', 'quarter', 'custom'];
    $value = trim((string)($period ?? 'today'));

    return in_array($value, $allowed, true) ? $value : 'today';
}

function analytics_normalize_date(?string $value): ?string
{
    $raw = trim((string)($value ?? ''));

    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if ($date === false || $date->format('Y-m-d') !== $raw) {
        return null;
    }

    return $raw;
}

function analytics_period(string $period, ?string $start = null, ?string $end = null): array
{
    $tz = new DateTimeZone('Asia/Manila');
    $today = new DateTimeImmutable('today', $tz);
    $period = analytics_normalize_period($period);
    $startValue = analytics_normalize_date($start);
    $endValue = analytics_normalize_date($end);

    if ($period === 'custom' && $startValue !== null && $endValue !== null) {
        $startDate = new DateTimeImmutable($startValue, $tz);
        $endDate = new DateTimeImmutable($endValue, $tz);
        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $maxEnd = $startDate->modify('+365 days');
        if ($endDate > $maxEnd) {
            $endDate = $maxEnd;
        }
    } else {
        if ($period === 'custom') {
            $period = '30d';
        }
        switch ($period) {
            case 'today':
                $startDate = $today;
                $endDate = $today;
                break;
            case '7d':
                $startDate = $today->modify('-6 days');
                $endDate = $today;
                break;
            case '30d':
                $startDate = $today->modify('-29 days');
                $endDate = $today;
                break;
            case 'week':
                $startDate = $today->modify('monday this week');
                $endDate = $today;
                break;
            case 'month':
                $startDate = new DateTimeImmutable('first day of this month', $tz);
                $endDate = $today;
                break;
            case 'quarter':
                $quarterMonth = intdiv((int)$today->format('n') - 1, 3) * 3 + 1;
                $startDate = $today->setDate((int)$today->format('Y'), $quarterMonth, 1);
                $endDate = $today;
                break;
            default:
                $startDate = $today->modify('-29 days');
                $endDate = $today;
                break;
        }
    }

    $spanDays = max(1, (int)$startDate->diff($endDate)->days + 1);
    $prevEnd = $startDate->modify('-1 day');
    $prevStart = $prevEnd->modify('-' . ($spanDays - 1) . ' days');

    return [
        'period'    => $period,
        'start'     => $startDate->format('Y-m-d'),
        'end'       => $endDate->format('Y-m-d'),
        'label'     => analytics_period_label($period, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')),
        'prevStart' => $prevStart->format('Y-m-d'),
        'prevEnd'   => $prevEnd->format('Y-m-d'),
    ];
}

function analytics_period_label(string $period, string $start, string $end): string
{
    $map = [
        'today'  => 'Today',
        'week'   => 'This Week',
        'quarter' => 'Q' . (intdiv((int)date('n', strtotime($start)) - 1, 3) + 1) . ' ' . date('Y', strtotime($start)),
        '7d'     => 'Last 7 Days',
        '30d'    => 'Last 30 Days',
        'month'  => 'This Month',
        'custom' => 'Custom Range',
    ];

    if ($period === 'custom') {
        return date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
    }

    return $map[$period] ?? 'Custom Range';
}

function analytics_revenue_totals(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN appointments a ON a.id = p.appointment_id WHERE ' . analytics_revenue_condition() . ' AND a.appointment_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: ['n' => 0, 'total' => 0];
    $count = (int)$row['n'];
    $total = (float)$row['total'];

    return ['total' => $total, 'count' => $count, 'average' => $count > 0 ? $total / $count : 0.0];
}

function analytics_room_utilization(PDO $pdo, string $start, string $end): float
{
    $roomCount = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE COALESCE(room_status, 'available') <> 'maintenance'")->fetchColumn();
    if ($roomCount === 0) {
        return 0.0;
    }

    $minutesByDay = [];
    foreach ($pdo->query('SELECT day_of_week, opening_time, closing_time, is_open FROM spa_operating_hours')->fetchAll() as $row) {
        if ((int)$row['is_open'] !== 1) {
            continue;
        }
        $open = strtotime('1970-01-01 ' . (string)$row['opening_time']);
        $close = strtotime('1970-01-01 ' . (string)$row['closing_time']);
        if ($open !== false && $close !== false && $close > $open) {
            $minutesByDay[(int)$row['day_of_week']] = (int)(($close - $open) / 60);
        }
    }

    $tz = new DateTimeZone('Asia/Manila');
    $cursor = new DateTimeImmutable($start, $tz);
    $last = new DateTimeImmutable($end, $tz);
    $availableMinutes = 0;
    while ($cursor <= $last) {
        $availableMinutes += ($minutesByDay[(int)$cursor->format('N')] ?? 0) * $roomCount;
        $cursor = $cursor->modify('+1 day');
    }
    if ($availableMinutes <= 0) {
        return 0.0;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60), 0) FROM appointments
         WHERE room_id IS NOT NULL AND appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') AND appointment_date BETWEEN ? AND ?'
    );
    $stmt->execute([$start, $end]);
    $bookedMinutes = (float)$stmt->fetchColumn();

    return min(100.0, ($bookedMinutes / $availableMinutes) * 100);
}

function analytics_summary(PDO $pdo, string $start, string $end, string $prevStart, string $prevEnd): array
{
    $current = analytics_revenue_totals($pdo, $start, $end);
    $previous = analytics_revenue_totals($pdo, $prevStart, $prevEnd);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(appointment_status IN ('cancelled', 'no_show')), 0) AS lost FROM appointments WHERE appointment_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $countRow = $stmt->fetch() ?: ['total' => 0, 'lost' => 0];
    $appointments = (int)$countRow['total'];
    $lost = (int)$countRow['lost'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ?');
    $stmt->execute([$prevStart, $prevEnd]);
    $previousAppointments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())");
    $stmt->execute();
    $activeMemberships = (int)$stmt->fetchColumn();

    return [
        'revenue'                => $current['total'],
        'previousRevenue'        => $previous['total'],
        'appointments'           => $appointments,
        'previousAppointments'   => $previousAppointments,
        'avgTransaction'         => $current['average'],
        'previousAvgTransaction' => $previous['average'],
        'transactions'           => $current['count'],
        'lostBookings'           => $lost,
        'lostRate'               => $appointments > 0 ? ($lost / $appointments) * 100 : 0.0,
        'roomUtilization'        => analytics_room_utilization($pdo, $start, $end),
        'activeMemberships'      => $activeMemberships,
    ];
}

function analytics_distribution(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT appointment_status AS status, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY appointment_status ORDER BY total DESC');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $palette = analytics_status_colors();
    $fallback = '#173D30';
    $labels = [];
    $keys = [];
    $values = [];
    $colors = [];
    $total = 0;
    foreach ($rows as $row) {
        $key = strtolower(trim((string)($row['status'] ?? '')));
        $count = (int)($row['total'] ?? 0);
        $total += $count;
        $keys[] = $key;
        $labels[] = analytics_status_label($key);
        $values[] = $count;
        $colors[] = $palette[$key] ?? $fallback;
    }

    return [
        'labels' => $labels,
        'keys'   => $keys,
        'values' => $values,
        'colors' => $colors,
        'total'  => $total,
    ];
}

function analytics_revenue_trend(PDO $pdo, string $start, string $end): array
{
    $tz = new DateTimeZone('Asia/Manila');
    $startDate = new DateTimeImmutable($start, $tz);
    $endDate = new DateTimeImmutable($end, $tz);
    $days = (int)$startDate->diff($endDate)->days + 1;

    $labels = [];
    $values = [];
    $indexMap = [];
    for ($i = 0; $i < $days; $i++) {
        $current = $startDate->modify('+' . $i . ' days');
        $labels[] = $current->format('M j');
        $values[] = 0.0;
        $indexMap[$current->format('Y-m-d')] = $i;
    }

    $stmt = $pdo->prepare('SELECT a.appointment_date AS day, COALESCE(SUM(p.amount), 0) AS revenue FROM appointments a JOIN payments p ON p.appointment_id = a.id WHERE ' . analytics_revenue_condition() . ' AND a.appointment_date BETWEEN ? AND ? GROUP BY a.appointment_date ORDER BY a.appointment_date ASC');
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $day = (string)($row['day'] ?? '');
        if (isset($indexMap[$day])) {
            $values[$indexMap[$day]] = (float)($row['revenue'] ?? 0);
        }
    }

    return ['labels' => $labels, 'values' => $values];
}

function analytics_service_breakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        'SELECT s.service_name,
                COUNT(DISTINCT CASE WHEN a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') THEN a.id END) AS bookings,
                COALESCE(SUM(p.amount), 0) AS revenue
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         LEFT JOIN payments p ON p.appointment_id = a.id AND ' . analytics_revenue_condition() . '
         WHERE a.appointment_date BETWEEN ? AND ?
         GROUP BY s.id, s.service_name
         HAVING bookings > 0 OR revenue > 0
         ORDER BY revenue DESC, bookings DESC, s.service_name ASC
         LIMIT 5'
    );
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

function analytics_room_breakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        'SELECT r.room_name, r.room_status,
                COUNT(DISTINCT CASE WHEN a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') THEN a.id END) AS bookings,
                COALESCE(SUM(p.amount), 0) AS revenue
         FROM rooms r
         LEFT JOIN appointments a ON a.room_id = r.id AND a.appointment_date BETWEEN ? AND ?
         LEFT JOIN payments p ON p.appointment_id = a.id AND ' . analytics_revenue_condition() . '
         GROUP BY r.id, r.room_name, r.room_status
         ORDER BY bookings DESC, revenue DESC, r.room_name ASC
         LIMIT 5'
    );
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

function analytics_change_pct(float $current, float $previous): float
{
    if ($previous == 0.0) {
        return $current == 0.0 ? 0.0 : 100.0;
    }

    return (($current - $previous) / $previous) * 100;
}

function analytics_therapist_breakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        'SELECT t.full_name, t.specialization,
                COUNT(DISTINCT CASE WHEN a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') THEN a.id END) AS sessions,
                COUNT(DISTINCT CASE WHEN a.appointment_status = \'completed\' THEN a.id END) AS completed,
                COALESCE(SUM(p.amount), 0) AS revenue
         FROM therapists t
         JOIN appointments a ON a.therapist_id = t.id AND a.appointment_date BETWEEN ? AND ?
         LEFT JOIN payments p ON p.appointment_id = a.id AND ' . analytics_revenue_condition() . '
         GROUP BY t.id, t.full_name, t.specialization
         HAVING sessions > 0
         ORDER BY revenue DESC, sessions DESC, t.full_name ASC
         LIMIT 8'
    );
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

function analytics_payment_methods(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(p.payment_method), ''), 'Front Desk') AS method, COUNT(*) AS transactions, COALESCE(SUM(p.amount), 0) AS revenue
         FROM payments p JOIN appointments a ON a.id = p.appointment_id
         WHERE " . analytics_revenue_condition() . ' AND a.appointment_date BETWEEN ? AND ?
         GROUP BY method ORDER BY revenue DESC, transactions DESC'
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)$row['revenue'];
    }
    foreach ($rows as $i => $row) {
        $rows[$i]['share'] = $total > 0 ? ((float)$row['revenue'] / $total) * 100 : 0.0;
    }

    return $rows;
}

function analytics_weekday_load(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT WEEKDAY(appointment_date) AS wd, COUNT(*) AS c FROM appointments WHERE appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') AND appointment_date BETWEEN ? AND ? GROUP BY WEEKDAY(appointment_date)');
    $stmt->execute([$start, $end]);
    $values = array_fill(0, 7, 0);
    foreach ($stmt->fetchAll() as $row) {
        $values[(int)$row['wd']] = (int)$row['c'];
    }

    return ['labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'values' => $values];
}

function analytics_guest_insights(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE DATE(registered_at) BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $newGuests = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT a.customer_id) AS active_guests,
                COUNT(DISTINCT CASE WHEN EXISTS (
                    SELECT 1 FROM appointments b WHERE b.customer_id = a.customer_id AND b.appointment_date < ? AND b.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ')
                ) THEN a.customer_id END) AS returning_guests
         FROM appointments a
         WHERE a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') AND a.appointment_date BETWEEN ? AND ?'
    );
    $stmt->execute([$start, $start, $end]);
    $row = $stmt->fetch() ?: ['active_guests' => 0, 'returning_guests' => 0];
    $active = (int)$row['active_guests'];
    $returning = (int)$row['returning_guests'];

    $stmt = $pdo->prepare(
        'SELECT c.full_name,
                COUNT(DISTINCT CASE WHEN a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') THEN a.id END) AS bookings,
                COALESCE(SUM(p.amount), 0) AS spent
         FROM customers c
         JOIN appointments a ON a.customer_id = c.id AND a.appointment_date BETWEEN ? AND ?
         LEFT JOIN payments p ON p.appointment_id = a.id AND ' . analytics_revenue_condition() . '
         GROUP BY c.id, c.full_name
         HAVING spent > 0
         ORDER BY spent DESC, bookings DESC, c.full_name ASC
         LIMIT 5'
    );
    $stmt->execute([$start, $end]);

    return [
        'newGuests'      => $newGuests,
        'activeGuests'   => $active,
        'returning'      => $returning,
        'firstTime'      => max(0, $active - $returning),
        'returningRate'  => $active > 0 ? ($returning / $active) * 100 : 0.0,
        'top'            => $stmt->fetchAll(),
    ];
}

function analytics_loyalty_insights(PDO $pdo, string $start, string $end): array
{
    $tiers = $pdo->query("SELECT COALESCE(NULLIF(TRIM(membership_type), ''), 'Unassigned') AS tier, COUNT(*) AS members FROM memberships WHERE membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= CURDATE()) GROUP BY tier ORDER BY members DESC, tier ASC")->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('earned', 'bonus') AND points > 0 THEN points ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN transaction_type = 'redeemed' THEN ABS(points) ELSE 0 END), 0) AS redeemed
         FROM loyalty_transactions WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: ['earned' => 0, 'redeemed' => 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE membership_status = 'active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();

    return [
        'tiers'    => $tiers,
        'earned'   => (int)$row['earned'],
        'redeemed' => (int)$row['redeemed'],
        'expiring' => (int)$stmt->fetchColumn(),
    ];
}

function analytics_checkin_insights(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS expected,
                COALESCE(SUM(x.is_in), 0) AS checked_in,
                COALESCE(SUM(x.method = 'qr'), 0) AS by_qr,
                COALESCE(SUM(x.method = 'manual'), 0) AS by_manual
         FROM appointments a
         LEFT JOIN (
             SELECT appointment_id, 1 AS is_in, MIN(checkin_method) AS method
             FROM appointment_checkins WHERE checkin_status = 'checked_in' GROUP BY appointment_id
         ) x ON x.appointment_id = a.id
         WHERE a.appointment_status IN ('confirmed', 'completed') AND a.appointment_date BETWEEN ? AND ?"
    );
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: ['expected' => 0, 'checked_in' => 0, 'by_qr' => 0, 'by_manual' => 0];
    $expected = (int)$row['expected'];
    $in = (int)$row['checked_in'];

    return [
        'expected' => $expected,
        'checkedIn' => $in,
        'byQr' => (int)$row['by_qr'],
        'byManual' => (int)$row['by_manual'],
        'rate' => $expected > 0 ? min(100.0, ($in / $expected) * 100) : 0.0,
    ];
}

function analytics_completed_stats(PDO $pdo, string $start, string $end, string $prevStart, string $prevEnd): array
{
    $sql = "SELECT COALESCE(SUM(appointment_status = 'completed'), 0) AS completed, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $end]);
    $cur = $stmt->fetch() ?: ['completed' => 0, 'total' => 0];

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prevStart, $prevEnd]);
    $prev = $stmt->fetch() ?: ['completed' => 0, 'total' => 0];

    $tz = new DateTimeZone('Asia/Manila');
    $days = max(1, (int)(new DateTimeImmutable($start, $tz))->diff(new DateTimeImmutable($end, $tz))->days + 1);
    $completed = (int)$cur['completed'];
    $total = (int)$cur['total'];

    return [
        'completed'     => $completed,
        'previous'      => (int)$prev['completed'],
        'avgDaily'      => $completed / $days,
        'completionRate' => $total > 0 ? ($completed / $total) * 100 : 0.0,
    ];
}

function analytics_revenue_pacing(PDO $pdo, string $start, string $end): array
{
    $trend = analytics_revenue_trend($pdo, $start, $end);
    $tz = new DateTimeZone('Asia/Manila');
    $startDate = new DateTimeImmutable($start, $tz);
    $count = count($trend['values']);
    $weekly = $count > 14;
    $labels = [];
    $values = [];

    if ($weekly) {
        for ($i = 0; $i < $count; $i += 7) {
            $from = $startDate->modify('+' . $i . ' days');
            $to = $startDate->modify('+' . min($i + 6, $count - 1) . ' days');
            $labels[] = ['Week ' . (intdiv($i, 7) + 1), $from->format('M j') . ' – ' . $to->format('M j')];
            $values[] = (float)array_sum(array_slice($trend['values'], $i, 7));
        }
    } else {
        $labels = $trend['labels'];
        $values = $trend['values'];
    }

    $peak = -1;
    $max = 0.0;
    foreach ($values as $i => $v) {
        if ($v > $max) {
            $max = (float)$v;
            $peak = $i;
        }
    }

    return ['labels' => $labels, 'values' => $values, 'peak' => $peak, 'weekly' => $weekly, 'total' => (float)array_sum($values)];
}

function analytics_service_mix(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        'SELECT s.service_name,
                COUNT(DISTINCT CASE WHEN a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') THEN a.id END) AS bookings,
                COALESCE(SUM(p.amount), 0) AS revenue
         FROM services s
         LEFT JOIN appointments a ON a.service_id = s.id AND a.appointment_date BETWEEN ? AND ?
         LEFT JOIN payments p ON p.appointment_id = a.id AND ' . analytics_revenue_condition() . '
         GROUP BY s.id, s.service_name
         ORDER BY revenue DESC, bookings DESC, s.service_name ASC'
    );
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

function analytics_therapist_matrix(PDO $pdo, string $start, string $end): array
{
    $therapists = $pdo->query("SELECT id, full_name, specialization FROM therapists WHERE COALESCE(employment_status, 'active') = 'active' ORDER BY full_name ASC")->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT therapist_id, COALESCE(SUM(appointment_status = 'completed'), 0) AS completed, COUNT(*) AS booked,
                COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60), 0) AS minutes
         FROM appointments
         WHERE therapist_id IS NOT NULL AND appointment_status IN (" . ANALYTICS_ACTIVE_STATUSES . ') AND appointment_date BETWEEN ? AND ?
         GROUP BY therapist_id'
    );
    $stmt->execute([$start, $end]);
    $activity = [];
    foreach ($stmt->fetchAll() as $row) {
        $activity[(int)$row['therapist_id']] = $row;
    }

    $stmt = $pdo->prepare(
        'SELECT a.therapist_id, COALESCE(SUM(p.amount), 0) AS revenue
         FROM appointments a JOIN payments p ON p.appointment_id = a.id
         WHERE a.therapist_id IS NOT NULL AND ' . analytics_revenue_condition() . ' AND a.appointment_date BETWEEN ? AND ?
         GROUP BY a.therapist_id'
    );
    $stmt->execute([$start, $end]);
    $revenue = [];
    foreach ($stmt->fetchAll() as $row) {
        $revenue[(int)$row['therapist_id']] = (float)$row['revenue'];
    }

    $shiftMinutes = [];
    $shifts = $pdo->query('SELECT therapist_id, day_of_week, COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60), 0) AS m FROM therapist_shifts WHERE is_available = 1 GROUP BY therapist_id, day_of_week')->fetchAll();
    foreach ($shifts as $row) {
        $shiftMinutes[(int)$row['therapist_id']][(int)$row['day_of_week']] = (float)$row['m'];
    }

    $tz = new DateTimeZone('Asia/Manila');
    $weekdayCounts = array_fill(1, 7, 0);
    $cursor = new DateTimeImmutable($start, $tz);
    $last = new DateTimeImmutable($end, $tz);
    while ($cursor <= $last) {
        $weekdayCounts[(int)$cursor->format('N')]++;
        $cursor = $cursor->modify('+1 day');
    }

    $rows = [];
    foreach ($therapists as $t) {
        $id = (int)$t['id'];
        $available = 0.0;
        foreach ($shiftMinutes[$id] ?? [] as $dow => $minutes) {
            $available += $minutes * ($weekdayCounts[$dow] ?? 0);
        }
        $minutes = (float)($activity[$id]['minutes'] ?? 0);
        $utilization = $available > 0 ? min(100.0, ($minutes / $available) * 100) : 0.0;
        $rows[] = [
            'name'        => (string)$t['full_name'],
            'specialty'   => (string)($t['specialization'] ?? ''),
            'completed'   => (int)($activity[$id]['completed'] ?? 0),
            'booked'      => (int)($activity[$id]['booked'] ?? 0),
            'hours'       => $minutes / 60,
            'revenue'     => $revenue[$id] ?? 0.0,
            'utilization' => $utilization,
        ];
    }

    usort($rows, static fn(array $a, array $b): int => [$b['revenue'], $b['completed'], $b['booked']] <=> [$a['revenue'], $a['completed'], $a['booked']]);

    return array_slice($rows, 0, 8);
}

function analytics_suite_health(PDO $pdo, string $start, string $end): array
{
    $rooms = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(COALESCE(room_status, 'available') = 'available'), 0) AS available, COALESCE(SUM(room_status = 'maintenance'), 0) AS maintenance FROM rooms")->fetch() ?: ['total' => 0, 'available' => 0, 'maintenance' => 0];

    $stmt = $pdo->prepare('SELECT COUNT(*) AS logs, COALESCE(SUM(maintenance_status NOT IN (\'completed\', \'cancelled\')), 0) AS open_logs FROM room_maintenance_logs WHERE scheduled_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $logs = $stmt->fetch() ?: ['logs' => 0, 'open_logs' => 0];

    $config = [];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM spa_configuration WHERE setting_key IN ('cleaning_gap_enabled', 'cleaning_gap_minutes', 'cleaning_verification')");
    foreach ($stmt->fetchAll() as $row) {
        $config[(string)$row['setting_key']] = (string)$row['setting_value'];
    }

    $stmt = $pdo->prepare('SELECT COALESCE(AVG(s.duration), 0) AS avg_duration, COALESCE(AVG(a.guest_count), 0) AS avg_guests FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.appointment_status IN (' . ANALYTICS_ACTIVE_STATUSES . ') AND a.appointment_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $avg = $stmt->fetch() ?: ['avg_duration' => 0, 'avg_guests' => 0];

    return [
        'total'        => (int)$rooms['total'],
        'available'    => (int)$rooms['available'],
        'maintenance'  => (int)$rooms['maintenance'],
        'logs'         => (int)$logs['logs'],
        'openLogs'     => (int)$logs['open_logs'],
        'gapEnabled'   => ($config['cleaning_gap_enabled'] ?? '0') === '1',
        'gapMinutes'   => (int)($config['cleaning_gap_minutes'] ?? 0),
        'verification' => ($config['cleaning_verification'] ?? '0') === '1',
        'avgDuration'  => (float)$avg['avg_duration'],
        'avgGuests'    => (float)$avg['avg_guests'],
    ];
}