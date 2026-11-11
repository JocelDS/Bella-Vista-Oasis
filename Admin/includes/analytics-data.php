<?php
declare(strict_types=1);

function analytics_normalize_period(?string $period): string
{
    $allowed = ['today', '7d', '30d', 'month', 'custom'];
    $value = trim((string)($period ?? '30d'));

    return in_array($value, $allowed, true) ? $value : '30d';
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
    } else {
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
            case 'month':
                $monthStart = new DateTimeImmutable('first day of this month', $tz);
                $startDate = $monthStart;
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
        'today' => 'Today',
        '7d'    => 'Last 7 Days',
        '30d'   => 'Last 30 Days',
        'month' => 'This Month',
        'custom' => 'Custom Range',
    ];

    if ($period === 'custom') {
        return date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
    }

    return $map[$period] ?? 'Custom Range';
}

function analytics_summary(PDO $pdo, string $start, string $end, string $prevStart, string $prevEnd): array
{
    $sqlPaid = 'SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN appointments a ON a.id = p.appointment_id WHERE p.payment_status = "paid" AND a.appointment_date BETWEEN ? AND ?';
    $stmt = $pdo->prepare($sqlPaid);
    $stmt->execute([$start, $end]);
    $revenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare($sqlPaid);
    $stmt->execute([$prevStart, $prevEnd]);
    $previousRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $appointments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ?');
    $stmt->execute([$prevStart, $prevEnd]);
    $previousAppointments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(p.amount), 0) FROM payments p JOIN appointments a ON a.id = p.appointment_id WHERE p.payment_status = "paid" AND a.appointment_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $transactionRow = $stmt->fetch();
    $paidCount = (int)($transactionRow['COUNT(*)'] ?? 0);
    $avgTransaction = $paidCount > 0 ? (float)($transactionRow['COALESCE(SUM(p.amount), 0)'] ?? 0) / $paidCount : 0.0;

    $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(p.amount), 0) FROM payments p JOIN appointments a ON a.id = p.appointment_id WHERE p.payment_status = "paid" AND a.appointment_date BETWEEN ? AND ?');
    $stmt->execute([$prevStart, $prevEnd]);
    $prevTransactionRow = $stmt->fetch();
    $prevPaidCount = (int)($prevTransactionRow['COUNT(*)'] ?? 0);
    $previousAvgTransaction = $prevPaidCount > 0 ? (float)($prevTransactionRow['COALESCE(SUM(p.amount), 0)'] ?? 0) / $prevPaidCount : 0.0;

    $stmt = $pdo->query('SELECT COUNT(*) AS total_rooms FROM rooms');
    $totalRooms = (int)($stmt->fetch()['total_rooms'] ?? 0);
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT room_id) AS used_rooms FROM appointments WHERE room_id IS NOT NULL AND appointment_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    $usedRooms = (int)($stmt->fetch()['used_rooms'] ?? 0);
    $roomUtilization = $totalRooms > 0 ? (($usedRooms / $totalRooms) * 100) : 0.0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())");
    $stmt->execute();
    $activeMemberships = (int)$stmt->fetchColumn();

    return [
        'revenue' => $revenue,
        'previousRevenue' => $previousRevenue,
        'appointments' => $appointments,
        'previousAppointments' => $previousAppointments,
        'avgTransaction' => $avgTransaction,
        'previousAvgTransaction' => $previousAvgTransaction,
        'roomUtilization' => $roomUtilization,
        'activeMemberships' => $activeMemberships,
    ];
}

function analytics_distribution(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT appointment_status AS status, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY appointment_status ORDER BY total DESC');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $labels = [];
    $values = [];
    $colors = ['#173D30', '#C4B58D', '#A3AA91', '#B58A78', '#85877F'];
    $total = 0;
    foreach ($rows as $index => $row) {
        $label = $row['status'] ?? 'Unknown';
        $count = (int)($row['total'] ?? 0);
        $total += $count;
        $labels[] = ucfirst($label);
        $values[] = $count;
    }

    return [
        'labels' => $labels,
        'values' => $values,
        'colors' => $colors,
        'total' => $total,
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
    for ($i = 0; $i < $days; $i++) {
        $current = $startDate->modify('+' . $i . ' days');
        $labels[] = $current->format('M j');
        $values[] = 0;
    }

    $stmt = $pdo->prepare('SELECT DATE(a.appointment_date) AS day, COALESCE(SUM(p.amount), 0) AS revenue FROM appointments a LEFT JOIN payments p ON p.appointment_id = a.id AND p.payment_status = "paid" WHERE a.appointment_date BETWEEN ? AND ? GROUP BY DATE(a.appointment_date) ORDER BY day ASC');
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();
    $indexMap = [];
    foreach ($labels as $index => $k) {
        $indexMap[$startDate->modify('+' . $index . ' days')->format('Y-m-d')] = $index;
    }

    foreach ($rows as $row) {
        $day = (string)($row['day'] ?? '');
        if (isset($indexMap[$day])) {
            $values[$indexMap[$day]] = (float)($row['revenue'] ?? 0);
        }
    }

    return ['labels' => $labels, 'values' => $values];
}

function analytics_service_breakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT s.service_name, COUNT(a.id) AS bookings, COALESCE(SUM(p.amount), 0) AS revenue FROM appointments a JOIN services s ON s.id = a.service_id LEFT JOIN payments p ON p.appointment_id = a.id AND p.payment_status = "paid" WHERE a.appointment_date BETWEEN ? AND ? GROUP BY s.id, s.service_name ORDER BY revenue DESC, bookings DESC LIMIT 5');
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll();
}

function analytics_room_breakdown(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('SELECT r.room_name, COUNT(a.id) AS bookings, COALESCE(SUM(p.amount), 0) AS revenue, r.room_status FROM rooms r LEFT JOIN appointments a ON a.room_id = r.id AND a.appointment_date BETWEEN ? AND ? LEFT JOIN payments p ON p.appointment_id = a.id AND p.payment_status = "paid" GROUP BY r.id, r.room_name, r.room_status ORDER BY bookings DESC, r.room_name ASC LIMIT 5');
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll();
}

function analytics_change_pct(float $current, float $previous): float
{
    if ($previous == 0.0) {
        if ($current == 0.0) {
            return 0.0;
        }
        return 100.0;
    }
    return (($current - $previous) / $previous) * 100;
}
