<?php
declare(strict_types=1);

const SCHEDULE_PER_PAGE = 5;

function dash_status_map(): array
{
    return [
        'completed' => 'Completed',
        'confirmed' => 'Confirmed',
        'pending'   => 'Pending',
        'cancelled' => 'Cancelled',
        'no_show'   => 'No Show',
    ];
}

function dash_payment_map(): array
{
    return [
        'paid'     => 'Paid',
        'deposit'  => 'Deposit',
        'pending'  => 'Pending',
        'refunded' => 'Refunded',
    ];
}

function peso($value, ?int $decimals = null): string
{
    $n = (float)$value;
    if ($decimals === null) {
        $decimals = abs($n - round($n)) < 0.005 ? 0 : 2;
    }
    return '₱' . number_format($n, $decimals);
}

function name_initials(string $name): string
{
    preg_match_all('/\p{L}[\p{L}\'’-]*/u', $name, $m);
    $words = $m[0];
    if (!$words) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($words[0], 0, 1));
    $last  = count($words) > 1 ? mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)) : '';
    return $first . $last;
}

function dash_delta(int $now, int $prev, string $label): array
{
    $diff = $now - $prev;
    if ($diff > 0) {
        return ['up', '▲ ' . $diff . ' ' . $label];
    }
    if ($diff < 0) {
        return ['down', '▼ ' . abs($diff) . ' ' . $label];
    }
    return ['flat', 'No change ' . $label];
}

function dash_time_ago(?string $ts): string
{
    $t = $ts ? strtotime($ts) : false;
    if ($t === false) {
        return '';
    }
    $diff = time() - $t;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return (int)floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return (int)floor($diff / 3600) . ' hr ago';
    }
    return date('M j, g:i A', $t);
}

function api_guard(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $ok = restore_from_cookie();
    if ($ok) {
        $stmt = db()->prepare("SELECT id FROM administrators WHERE id = ? AND status = 'active'");
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $ok = (bool)$stmt->fetch();
    }
    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please sign in again.']);
        exit;
    }
}

function get_dashboard_stats(PDO $pdo, string $today, string $yesterday): array
{
    $stmt = $pdo->prepare(
        'SELECT appointment_date AS d, appointment_status AS s, COUNT(*) AS n
         FROM appointments
         WHERE appointment_date IN (?, ?)
         GROUP BY appointment_date, appointment_status'
    );
    $stmt->execute([$today, $yesterday]);

    $counts = [
        $today     => ['all' => 0],
        $yesterday => ['all' => 0],
    ];
    foreach ($stmt->fetchAll() as $r) {
        $d = (string)$r['d'];
        $s = (string)$r['s'];
        $n = (int)$r['n'];
        $counts[$d]['all'] += $n;
        $counts[$d][$s] = ($counts[$d][$s] ?? 0) + $n;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_status = 'pending' AND appointment_date >= ?");
    $stmt->execute([$today]);
    $pendingUpcoming = (int)$stmt->fetchColumn();

    $room = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(room_status = 'available'), 0) AS avail FROM rooms")->fetch();

    $revenueTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'paid'")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'paid' AND DATE(payment_date) = ?");
    $stmt->execute([$today]);
    $revenueToday = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memberships WHERE membership_status = 'active' AND (expiry_date IS NULL OR expiry_date >= ?)");
    $stmt->execute([$today]);
    $members = (int)$stmt->fetchColumn();

    $t = $counts[$today];
    $y = $counts[$yesterday];
    foreach (['confirmed', 'completed', 'pending', 'cancelled', 'no_show'] as $k) {
        $t[$k] = $t[$k] ?? 0;
        $y[$k] = $y[$k] ?? 0;
    }

    return [
        'today'            => $t,
        'yesterday'        => $y,
        'pending_upcoming' => $pendingUpcoming,
        'rooms_total'      => (int)($room['total'] ?? 0),
        'rooms_available'  => (int)($room['avail'] ?? 0),
        'revenue_total'    => $revenueTotal,
        'revenue_today'    => $revenueToday,
        'members'          => $members,
    ];
}

function get_week_trend(PDO $pdo, string $today): array
{
    $start = date('Y-m-d', strtotime($today . ' -6 days'));
    $stmt = $pdo->prepare('SELECT appointment_date AS d, COUNT(*) AS n FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY appointment_date');
    $stmt->execute([$start, $today]);
    $byDay = [];
    foreach ($stmt->fetchAll() as $r) {
        $byDay[(string)$r['d']] = (int)$r['n'];
    }

    $labels = [];
    $full   = [];
    $values = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime($today . ' -' . $i . ' days');
        $key = date('Y-m-d', $ts);
        $labels[] = date('M j', $ts);
        $full[]   = date('l, M j', $ts);
        $values[] = $byDay[$key] ?? 0;
    }

    $total = array_sum($values);
    $max   = max($values);
    $peak  = $max > 0 ? $full[array_search($max, $values, true)] : '—';

    return [
        'labels'  => $labels,
        'full'    => $full,
        'values'  => $values,
        'total'   => $total,
        'average' => round($total / 7, 1),
        'peak'    => $peak,
        'peak_n'  => $max,
    ];
}

function get_distribution(PDO $pdo): array
{
    $rows = $pdo->query('SELECT appointment_status AS s, COUNT(*) AS n FROM appointments GROUP BY appointment_status')->fetchAll();
    $counts = [];
    $total = 0;
    foreach ($rows as $r) {
        $counts[(string)$r['s']] = (int)$r['n'];
        $total += (int)$r['n'];
    }

    $segments = [];
    $known = 0;
    foreach (dash_status_map() as $key => $label) {
        $n = $counts[$key] ?? 0;
        $known += $n;
        $segments[] = [
            'key'   => $key,
            'label' => $label,
            'count' => $n,
            'pct'   => $total > 0 ? round($n / $total * 100, 1) : 0.0,
        ];
    }
    $other = $total - $known;
    if ($other > 0) {
        $segments[] = ['key' => 'other', 'label' => 'Other', 'count' => $other, 'pct' => round($other / $total * 100, 1)];
    }

    return ['total' => $total, 'segments' => $segments];
}

function get_rooms(PDO $pdo): array
{
    return $pdo->query('SELECT id, room_name, room_type, room_status FROM rooms ORDER BY id ASC')->fetchAll();
}

function get_announcements(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, title, description, priority
         FROM operational_announcements
         ORDER BY CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, id ASC
         LIMIT 6"
    )->fetchAll();
}

function get_notifications(PDO $pdo, int $adminId): array
{
    $stmt = $pdo->prepare('SELECT id, title, message, created_at FROM notifications WHERE admin_id IS NULL OR admin_id = ? ORDER BY id DESC LIMIT 8');
    $stmt->execute([$adminId]);
    return $stmt->fetchAll();
}

function get_booking_options(PDO $pdo): array
{
    return [
        'customers'  => $pdo->query('SELECT id, full_name FROM customers ORDER BY full_name ASC')->fetchAll(),
        'services'   => $pdo->query('SELECT id, service_name, duration, price FROM services ORDER BY service_name ASC')->fetchAll(),
        'therapists' => $pdo->query("SELECT id, full_name FROM therapists WHERE availability_status <> 'on_leave' ORDER BY full_name ASC")->fetchAll(),
        'rooms'      => $pdo->query("SELECT id, room_name, room_type FROM rooms WHERE room_status <> 'maintenance' ORDER BY room_name ASC")->fetchAll(),
    ];
}

function fetch_schedule(PDO $pdo, string $date, string $status, string $q, int $page, int $perPage = SCHEDULE_PER_PAGE): array
{
    $where  = 'a.appointment_date = ?';
    $params = [$date];
    if ($status !== 'all') {
        $where   .= ' AND a.appointment_status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $where   .= ' AND c.full_name LIKE ?';
        $params[] = '%' . addcslashes($q, '\\%_') . '%';
    }

    $from = ' FROM appointments a
        JOIN customers c  ON c.id = a.customer_id
        JOIN services s   ON s.id = a.service_id
        JOIN therapists t ON t.id = a.therapist_id
        JOIN rooms r      ON r.id = a.room_id
        LEFT JOIN (
            SELECT appointment_id, SUM(amount) AS paid_amount
            FROM payments
            WHERE payment_status = \'paid\'
            GROUP BY appointment_id
        ) p ON p.appointment_id = a.id
        WHERE ' . $where;

    $stmt = $pdo->prepare('SELECT COUNT(*)' . $from);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    $limit  = (int)$perPage;
    $offset = (int)(($page - 1) * $perPage);

    $stmt = $pdo->prepare(
        'SELECT a.id, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS guest_name, s.service_name, s.duration,
                t.full_name AS therapist_name, r.room_name,
                COALESCE(p.paid_amount, 0) AS paid_amount'
        . $from .
        ' ORDER BY a.start_time ASC, a.id ASC LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
}

function schedule_summary(int $total, int $page, int $perPage, int $shown): string
{
    if ($total === 0 || $shown === 0) {
        return 'No scheduled treatments to show';
    }
    $from = ($page - 1) * $perPage + 1;
    $to   = $from + $shown - 1;
    return 'Showing ' . $from . '–' . $to . ' of ' . $total . ' scheduled treatments today';
}

function render_schedule_rows(array $rows, bool $filtered): string
{
    if (!$rows) {
        $msg = $filtered ? 'No appointments match your filters.' : 'No appointments scheduled for today.';
        return '<tr class="empty-row"><td colspan="7">' . e($msg) . '</td></tr>';
    }

    $smap = dash_status_map();
    $pmap = dash_payment_map();

    ob_start();
    foreach ($rows as $r) {
        $st      = (string)$r['appointment_status'];
        $stLabel = $smap[$st] ?? ucwords(str_replace('_', ' ', $st));
        $stClass = isset($smap[$st]) ? 'st-' . $st : 'st-other';

        $ps      = (string)$r['payment_status'];
        $psLabel = $pmap[$ps] ?? ucwords(str_replace('_', ' ', $ps));
        $psClass = isset($pmap[$ps]) ? 'pay-' . $ps : 'pay-other';

        $paid   = (float)$r['paid_amount'];
        $total  = (float)$r['total_amount'];
        $amount = $ps === 'paid' ? peso($paid > 0 ? $paid : $total) : 'Total ' . peso($total);

        $start = date('g:i A', strtotime((string)$r['start_time']));
        $end   = date('g:i A', strtotime((string)$r['end_time']));
        ?>
        <tr>
            <td data-label="Guest">
                <div class="guest-cell">
                    <span class="avatar"><?= e(name_initials((string)$r['guest_name'])) ?></span>
                    <span class="guest-name"><?= e((string)$r['guest_name']) ?></span>
                </div>
            </td>
            <td data-label="Treatment">
                <span class="cell-main"><?= e((string)$r['service_name']) ?></span>
                <span class="cell-sub"><?= e((string)(int)$r['duration']) ?> min</span>
            </td>
            <td data-label="Therapist"><?= e((string)$r['therapist_name']) ?></td>
            <td data-label="Time">
                <span class="cell-main"><?= e($start) ?></span>
                <span class="cell-sub">to <?= e($end) ?></span>
            </td>
            <td data-label="Suite"><?= e((string)$r['room_name']) ?></td>
            <td data-label="Status"><span class="badge <?= e($stClass) ?>"><?= e($stLabel) ?></span></td>
            <td data-label="Payment">
                <span class="badge <?= e($psClass) ?>"><?= e($psLabel) ?></span>
                <span class="cell-sub"><?= e($amount) ?></span>
            </td>
        </tr>
        <?php
    }
    return (string)ob_get_clean();
}