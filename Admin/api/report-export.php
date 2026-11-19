<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/analytics-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = db();
    $period = analytics_normalize_period($_GET['period'] ?? 'today');
    $range = analytics_period($period, analytics_normalize_date($_GET['start'] ?? null), analytics_normalize_date($_GET['end'] ?? null));
    $summary = analytics_summary($pdo, $range['start'], $range['end'], $range['prevStart'], $range['prevEnd']);
    $distribution = analytics_distribution($pdo, $range['start'], $range['end']);
    $trend = analytics_revenue_trend($pdo, $range['start'], $range['end']);
    $services = analytics_service_breakdown($pdo, $range['start'], $range['end']);
    $rooms = analytics_room_breakdown($pdo, $range['start'], $range['end']);
    $therapistRows = analytics_therapist_breakdown($pdo, $range['start'], $range['end']);
    $methods = analytics_payment_methods($pdo, $range['start'], $range['end']);
    $guests = analytics_guest_insights($pdo, $range['start'], $range['end']);
    $loyalty = analytics_loyalty_insights($pdo, $range['start'], $range['end']);
    $checkins = analytics_checkin_insights($pdo, $range['start'], $range['end']);

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bella-vista-report-' . $range['start'] . '_to_' . $range['end'] . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $money = static fn($v): string => number_format((float)$v, 2, '.', '');
    $safe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Bella Vista Oasis - Reports & Analytics']);
    fputcsv($out, ['Period', $range['label']]);
    fputcsv($out, ['From', $range['start']]);
    fputcsv($out, ['To', $range['end']]);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    fputcsv($out, ['Summary', 'Current', 'Previous period', 'Change (%)']);
    fputcsv($out, ['Total revenue (PHP)', $money($summary['revenue']), $money($summary['previousRevenue']), number_format(analytics_change_pct((float)$summary['revenue'], (float)$summary['previousRevenue']), 1, '.', '')]);
    fputcsv($out, ['Appointments', (int)$summary['appointments'], (int)$summary['previousAppointments'], number_format(analytics_change_pct((float)$summary['appointments'], (float)$summary['previousAppointments']), 1, '.', '')]);
    fputcsv($out, ['Average transaction (PHP)', $money($summary['avgTransaction']), $money($summary['previousAvgTransaction']), number_format(analytics_change_pct((float)$summary['avgTransaction'], (float)$summary['previousAvgTransaction']), 1, '.', '')]);
    fputcsv($out, ['Room utilization (%)', number_format((float)$summary['roomUtilization'], 1, '.', '')]);
    fputcsv($out, ['Cancelled and no-show rate (%)', number_format((float)$summary['lostRate'], 1, '.', ''), (int)$summary['lostBookings'] . ' bookings']);
    fputcsv($out, ['Active memberships', (int)$summary['activeMemberships']]);
    fputcsv($out, []);

    fputcsv($out, ['Appointments by status', 'Count']);
    foreach ($distribution['labels'] as $i => $label) {
        fputcsv($out, [$label, (int)($distribution['values'][$i] ?? 0)]);
    }
    fputcsv($out, ['Total', (int)$distribution['total']]);
    fputcsv($out, []);

    fputcsv($out, ['Date', 'Revenue (PHP)']);
    foreach ($trend['labels'] as $i => $label) {
        fputcsv($out, [$label, $money($trend['values'][$i] ?? 0)]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Top services', 'Bookings', 'Revenue (PHP)']);
    foreach ($services as $row) {
        fputcsv($out, [$safe((string)($row['service_name'] ?? 'Unknown')), (int)($row['bookings'] ?? 0), $money($row['revenue'] ?? 0)]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Suite', 'Bookings', 'Revenue (PHP)', 'Status']);
    foreach ($rooms as $row) {
        fputcsv($out, [$safe((string)($row['room_name'] ?? 'Unknown')), (int)($row['bookings'] ?? 0), $money($row['revenue'] ?? 0), (string)($row['room_status'] ?? 'available')]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Payment method', 'Transactions', 'Revenue (PHP)', 'Share (%)']);
    foreach ($methods as $row) {
        fputcsv($out, [$safe((string)$row['method']), (int)$row['transactions'], $money($row['revenue']), number_format((float)$row['share'], 1, '.', '')]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Therapist', 'Specialization', 'Sessions', 'Completed', 'Revenue (PHP)']);
    foreach ($therapistRows as $row) {
        fputcsv($out, [$safe((string)$row['full_name']), $safe((string)($row['specialization'] ?? '')), (int)$row['sessions'], (int)$row['completed'], $money($row['revenue'])]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Guest activity', 'Value']);
    fputcsv($out, ['New guests registered', (int)$guests['newGuests']]);
    fputcsv($out, ['Returning guests', (int)$guests['returning']]);
    fputcsv($out, ['First-time guests', (int)$guests['firstTime']]);
    fputcsv($out, ['Returning rate (%)', number_format((float)$guests['returningRate'], 1, '.', '')]);
    fputcsv($out, []);

    fputcsv($out, ['Top guests', 'Bookings', 'Spent (PHP)']);
    foreach ($guests['top'] as $row) {
        fputcsv($out, [$safe((string)$row['full_name']), (int)$row['bookings'], $money($row['spent'])]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Membership & loyalty', 'Value']);
    fputcsv($out, ['Points earned', (int)$loyalty['earned']]);
    fputcsv($out, ['Points redeemed', (int)$loyalty['redeemed']]);
    fputcsv($out, ['Memberships expiring in 30 days', (int)$loyalty['expiring']]);
    foreach ($loyalty['tiers'] as $row) {
        fputcsv($out, ['Active ' . $safe((string)$row['tier']) . ' members', (int)$row['members']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Check-in performance', 'Value']);
    fputcsv($out, ['Confirmed and completed bookings', (int)$checkins['expected']]);
    fputcsv($out, ['Checked in', (int)$checkins['checkedIn']]);
    fputcsv($out, ['Via QR scan', (int)$checkins['byQr']]);
    fputcsv($out, ['Manual check-in', (int)$checkins['byManual']]);
    fputcsv($out, ['Check-in rate (%)', number_format((float)$checkins['rate'], 1, '.', '')]);
    fclose($out);
} catch (Throwable $e) {
    error_log('[BVO reports] ' . $e->getMessage());
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to export right now.']);
}