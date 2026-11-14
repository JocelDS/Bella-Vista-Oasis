<?php
declare(strict_types=1);

require_once __DIR__ . '/dashboard-data.php';
require_once __DIR__ . '/appointments-data.php';

const CM_PER_PAGE   = 6;
const CM_UPLOAD_DIR = __DIR__ . '/../assets/uploads/customers';
const CM_PHOTO_MAX  = 2097152; // 2 MB

/* ---------- Setup / formatting ---------- */

function cm_ready(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_status'")->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function cm_code(int $id): string
{
    return 'CUS-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function cm_money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

function cm_date(?string $d): string
{
    return $d ? appt_date_label($d) : '—';
}

function cm_digits(string $phone): string
{
    return substr((string)preg_replace('/\D+/', '', $phone), -10);
}

function cm_photo_url(?string $file): ?string
{
    $file = $file ? basename($file) : '';
    return $file !== '' ? url('/assets/uploads/customers/' . rawurlencode($file)) : null;
}

/* ---------- Filters ---------- */

function cm_parse_filters(array $src): array
{
    $pick = static fn(string $k, array $ok, string $d): string =>
        in_array((string)($src[$k] ?? $d), $ok, true) ? (string)($src[$k] ?? $d) : $d;

    return [
        'q'      => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'status' => $pick('status', ['all', 'active', 'inactive'], 'all'),
        'tier'   => mb_substr(trim((string)($src['tier'] ?? 'all')), 0, 50) ?: 'all',
        'reg'    => $pick('reg', ['any', '7', '30', '90', '365'], 'any'),
        'spend'  => $pick('spend', ['any', 'lt5', '5to10', '10to20', 'gt20'], 'any'),
        'sort'   => $pick('sort', ['newest', 'name', 'spending', 'appointments'], 'newest'),
        'period' => $pick('period', ['30', '90', '365', 'all'], '30'),
        'page'   => max(1, (int)($src['page'] ?? 1)),
        'per'    => min(50, max(1, (int)($src['per'] ?? CM_PER_PAGE))),
    ];
}

function cm_tiers(PDO $pdo): array
{
    $rows = $pdo->query("SELECT DISTINCT membership_type FROM memberships WHERE membership_type IS NOT NULL AND membership_type <> '' ORDER BY membership_type")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strval', $rows);
}

function cm_from(PDO $pdo): string
{
    $today = $pdo->quote(date('Y-m-d'));
    $now   = $pdo->quote(date('Y-m-d H:i:s'));
    return "FROM customers c
        LEFT JOIN (
            SELECT customer_id, COUNT(*) AS appts,
                   SUM(appointment_status = 'completed') AS completed,
                   MAX(CASE WHEN appointment_status = 'completed' THEN appointment_date END) AS last_visit
              FROM appointments
             WHERE appointment_status IN ('pending', 'confirmed', 'completed')
             GROUP BY customer_id
        ) a ON a.customer_id = c.id
        LEFT JOIN (
            SELECT customer_id, MIN(TIMESTAMP(appointment_date, start_time)) AS upcoming
              FROM appointments
             WHERE appointment_status IN ('pending', 'confirmed') AND TIMESTAMP(appointment_date, start_time) >= $now
             GROUP BY customer_id
        ) u ON u.customer_id = c.id
        LEFT JOIN (
            SELECT customer_id, SUM(amt) AS spent
              FROM (SELECT customer_id, appointment_id, MAX(amount) AS amt FROM payments WHERE payment_status = 'paid' GROUP BY customer_id, appointment_id) x
             GROUP BY customer_id
        ) p ON p.customer_id = c.id
        LEFT JOIN memberships m ON m.id = (
            SELECT MAX(m2.id) FROM memberships m2
             WHERE m2.customer_id = c.id AND m2.membership_status = 'active' AND (m2.expiry_date IS NULL OR m2.expiry_date >= $today)
        )";
}

function cm_where(array $f): array
{
    $w = [];
    $p = [];

    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $id   = preg_match('/^(?:cus-?|#)?(\d{1,9})$/i', $f['q'], $m) ? (int)$m[1] : 0;
        $dig  = (string)preg_replace('/\D+/', '', $f['q']);
        $sql  = '(c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.id = ?';
        array_push($p, $like, $like, $like, $id);
        if (strlen($dig) >= 3) {
            $sql .= " OR REGEXP_REPLACE(COALESCE(c.phone, ''), '[^0-9]', '') LIKE ?";
            $p[] = '%' . $dig . '%';
        }
        $w[] = $sql . ')';
    }
    if ($f['status'] !== 'all') {
        $w[] = 'c.customer_status = ?';
        $p[] = $f['status'];
    }
    if ($f['tier'] === 'none') {
        $w[] = 'm.id IS NULL';
    } elseif ($f['tier'] !== 'all') {
        $w[] = 'm.membership_type = ?';
        $p[] = $f['tier'];
    }
    if ($f['reg'] !== 'any') {
        $w[] = 'c.registered_at >= ?';
        $p[] = date('Y-m-d 00:00:00', strtotime('-' . (int)$f['reg'] . ' days'));
    }
    $ranges = ['lt5' => [null, 5000], '5to10' => [5000, 10000], '10to20' => [10000, 20000], 'gt20' => [20000, null]];
    if (isset($ranges[$f['spend']])) {
        [$lo, $hi] = $ranges[$f['spend']];
        if ($lo !== null) {
            $w[] = 'COALESCE(p.spent, 0) >= ?';
            $p[] = $lo;
        }
        if ($hi !== null) {
            $w[] = 'COALESCE(p.spent, 0) < ?';
            $p[] = $hi;
        }
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}

function cm_order(string $sort): string
{
    return match ($sort) {
        'name'         => 'c.full_name ASC, c.id ASC',
        'spending'     => 'COALESCE(p.spent, 0) DESC, c.full_name ASC',
        'appointments' => 'COALESCE(a.appts, 0) DESC, c.full_name ASC',
        default        => 'c.registered_at DESC, c.id DESC',
    };
}

/* ---------- Row shaping ---------- */

function cm_row_out(array $r, array $topService = []): array
{
    $id = (int)$r['id'];
    return [
        'id'         => $id,
        'code'       => cm_code($id),
        'name'       => (string)$r['full_name'],
        'initials'   => name_initials((string)$r['full_name']),
        'photo'      => cm_photo_url($r['profile_photo'] ?? null),
        'email'      => (string)($r['email'] ?? ''),
        'phone'      => (string)($r['phone'] ?? ''),
        'status'     => (string)($r['customer_status'] ?? 'active'),
        'tier'       => $r['tier'] ?? null,
        'registered' => cm_date(isset($r['registered_at']) ? substr((string)$r['registered_at'], 0, 10) : null),
        'appts'      => (int)($r['appts'] ?? 0),
        'completed'  => (int)($r['completed'] ?? 0),
        'last_visit' => cm_date($r['last_visit'] ?? null),
        'upcoming'   => !empty($r['upcoming']) ? date('M j, Y · g:i A', strtotime((string)$r['upcoming'])) : null,
        'spent'      => (float)($r['spent'] ?? 0),
        'spent_label' => cm_money($r['spent'] ?? 0),
        'top_service' => $topService[$id] ?? null,
    ];
}

function cm_top_services(PDO $pdo, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.customer_id, s.service_name, COUNT(*) AS n
           FROM appointments a JOIN services s ON s.id = a.service_id
          WHERE a.customer_id IN ($in) AND a.appointment_status IN ('pending', 'confirmed', 'completed')
          GROUP BY a.customer_id, a.service_id, s.service_name
          ORDER BY a.customer_id, n DESC, s.service_name"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['customer_id']] ??= (string)$r['service_name'];
    }
    return $out;
}

/* ---------- List + stats ---------- */

function cm_list(PDO $pdo, array $f): array
{
    $from = cm_from($pdo);
    [$where, $params] = cm_where($f);

    $stmt = $pdo->prepare("SELECT COUNT(*) $from $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $f['per']));
    $page  = min($f['page'], $pages);
    $off   = ($page - 1) * $f['per'];

    $sql = "SELECT c.*, COALESCE(a.appts, 0) AS appts, COALESCE(a.completed, 0) AS completed, a.last_visit, u.upcoming,
                   COALESCE(p.spent, 0) AS spent, m.membership_type AS tier
            $from $where ORDER BY " . cm_order($f['sort']) . ' LIMIT ' . (int)$f['per'] . ' OFFSET ' . (int)$off;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $top = cm_top_services($pdo, array_map(static fn($r) => (int)$r['id'], $rows));

    return [
        'total'   => $total,
        'page'    => $page,
        'pages'   => $pages,
        'per'     => (int)$f['per'],
        'rows'    => array_map(static fn($r) => cm_row_out($r, $top), $rows),
        'summary' => $total === 0
            ? 'No guests found'
            : 'Showing ' . ($off + 1) . '–' . ($off + count($rows)) . ' of ' . $total . ' guests',
    ];
}

function cm_stats(PDO $pdo, string $period): array
{
    $today = date('Y-m-d');
    $since = $period === 'all' ? null : date('Y-m-d 00:00:00', strtotime('-' . (int)$period . ' days'));
    $label = ['30' => 'last 30 days', '90' => 'last 90 days', '365' => 'last 12 months', 'all' => 'all time'][$period];

    $total = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE (? IS NULL OR registered_at >= ?)');
    $stmt->execute([$since, $since]);
    $new = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM customers c
          WHERE c.customer_status = 'active' AND EXISTS (
              SELECT 1 FROM appointments a
               WHERE a.customer_id = c.id AND a.appointment_status IN ('pending', 'confirmed', 'completed') AND a.appointment_date >= ?)"
    );
    $stmt->execute([date('Y-m-d', strtotime('-90 days'))]);
    $active = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amt), 0) FROM (
             SELECT MAX(amount) AS amt FROM payments
              WHERE payment_status = 'paid' AND (? IS NULL OR payment_date >= ?)
              GROUP BY customer_id, appointment_id) x"
    );
    $stmt->execute([$since, $since]);
    $revenue = (float)$stmt->fetchColumn();

    $row = $pdo->query(
        "SELECT COUNT(*) AS visited, SUM(n >= 2) AS returning_guests
           FROM (SELECT customer_id, COUNT(*) AS n FROM appointments WHERE appointment_status = 'completed' GROUP BY customer_id) x"
    )->fetch();
    $visited   = (int)($row['visited'] ?? 0);
    $returning = (int)($row['returning_guests'] ?? 0);

    return [
        'total'     => $total,
        'new'       => $new,
        'active'    => $active,
        'revenue'   => cm_money($revenue),
        'retention' => $visited > 0 ? number_format($returning / $visited * 100, 1) . '%' : '—',
        'notes'     => [
            'total'     => $total === 1 ? '1 registered guest' : 'Registered guests',
            'new'       => 'Registered in the ' . $label,
            'active'    => 'Booked in the last 90 days',
            'revenue'   => 'Paid payments, ' . $label,
            'retention' => $visited > 0 ? $returning . ' of ' . $visited . ' guests returned' : 'No completed visits yet',
        ],
    ];
}

/* ---------- Detail ---------- */

function cm_get(PDO $pdo, int $id): ?array
{
    $from = cm_from($pdo);
    $stmt = $pdo->prepare(
        "SELECT c.*, COALESCE(a.appts, 0) AS appts, COALESCE(a.completed, 0) AS completed, a.last_visit, u.upcoming,
                COALESCE(p.spent, 0) AS spent, m.membership_type AS tier, m.expiry_date AS tier_expiry, m.start_date AS tier_start
         $from WHERE c.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function cm_history(PDO $pdo, int $id, string $filter): array
{
    $cond = match ($filter) {
        'upcoming'  => "AND a.appointment_status IN ('pending', 'confirmed') AND TIMESTAMP(a.appointment_date, a.start_time) >= " . $pdo->quote(date('Y-m-d H:i:s')),
        'completed' => "AND a.appointment_status = 'completed'",
        'cancelled' => "AND a.appointment_status = 'cancelled'",
        'no_show'   => "AND a.appointment_status = 'no_show'",
        default     => '',
    };
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                s.service_name, t.full_name AS therapist, r.room_name
           FROM appointments a
           LEFT JOIN services s ON s.id = a.service_id
           LEFT JOIN therapists t ON t.id = a.therapist_id
           LEFT JOIN rooms r ON r.id = a.room_id
          WHERE a.customer_id = ? $cond
          ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 100"
    );
    $stmt->execute([$id]);
    return array_map(static fn($r) => [
        'id'        => (int)$r['id'],
        'code'      => appt_code((int)$r['id']),
        'service'   => (string)($r['service_name'] ?? '—'),
        'therapist' => (string)($r['therapist'] ?? 'Unassigned'),
        'room'      => (string)($r['room_name'] ?? '—'),
        'date'      => appt_date_label($r['appointment_date']),
        'time'      => appt_time_label($r['start_time']) . ' – ' . appt_time_label($r['end_time']),
        'status'    => (string)$r['appointment_status'],
        'payment'   => (string)$r['payment_status'],
        'amount'    => cm_money($r['total_amount']),
    ], $stmt->fetchAll());
}

function cm_detail(PDO $pdo, int $id, string $filter = 'all'): ?array
{
    $r = cm_get($pdo, $id);
    if (!$r) {
        return null;
    }
    $top  = cm_top_services($pdo, [$id]);
    $base = cm_row_out($r, $top);

    $stmt = $pdo->prepare(
        "SELECT SUM(appointment_status = 'completed') AS completed, SUM(appointment_status = 'cancelled') AS cancelled,
                SUM(appointment_status = 'no_show') AS no_show,
                SUM(appointment_status IN ('pending','confirmed') AND TIMESTAMP(appointment_date, start_time) >= ?) AS upcoming,
                COUNT(*) AS everything
           FROM appointments WHERE customer_id = ?"
    );
    $stmt->execute([date('Y-m-d H:i:s'), $id]);
    $c = $stmt->fetch() ?: [];
    $counts = [
        'all'       => (int)($c['everything'] ?? 0),
        'upcoming'  => (int)($c['upcoming'] ?? 0),
        'completed' => (int)($c['completed'] ?? 0),
        'cancelled' => (int)($c['cancelled'] ?? 0),
        'no_show'   => (int)($c['no_show'] ?? 0),
    ];

    $stmt = $pdo->prepare(
        "SELECT p.id, p.amount, p.payment_method, p.payment_status, p.payment_date, p.appointment_id
           FROM payments p WHERE p.customer_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 10"
    );
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (SELECT appointment_id FROM payments WHERE customer_id = ? AND payment_status = 'paid' GROUP BY appointment_id) x"
    );
    $stmt->execute([$id]);
    $paidCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT s.service_name, COUNT(*) AS n FROM appointments a JOIN services s ON s.id = a.service_id
          WHERE a.customer_id = ? AND a.appointment_status IN ('pending','confirmed','completed')
          GROUP BY a.service_id, s.service_name ORDER BY n DESC, s.service_name LIMIT 3"
    );
    $stmt->execute([$id]);
    $preferred = array_map(static fn($x) => (string)$x['service_name'], $stmt->fetchAll());

    $nextAppt = null;
    if ($base['upcoming']) {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.appointment_date, a.start_time, s.service_name FROM appointments a LEFT JOIN services s ON s.id = a.service_id
              WHERE a.customer_id = ? AND a.appointment_status IN ('pending','confirmed') AND TIMESTAMP(a.appointment_date, a.start_time) >= ?
              ORDER BY a.appointment_date, a.start_time LIMIT 1"
        );
        $stmt->execute([$id, date('Y-m-d H:i:s')]);
        $nextAppt = $stmt->fetch() ?: null;
    }

    $alerts = cm_alerts($pdo, $r, $nextAppt);

    $activity = [];
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.appointment_status, s.service_name FROM appointments a
           LEFT JOIN services s ON s.id = a.service_id WHERE a.customer_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 3"
    );
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $a) {
        $activity[] = [
            'ts'    => strtotime($a['appointment_date'] . ' ' . $a['start_time']),
            'title' => ucfirst(str_replace('_', ' ', (string)$a['appointment_status'])) . ' · ' . ($a['service_name'] ?? 'Appointment'),
            'meta'  => appt_code((int)$a['id']) . ' · ' . appt_date_label($a['appointment_date']),
            'type'  => 'appointment',
        ];
    }
    foreach (array_slice($payments, 0, 3) as $pm) {
        if ($pm['payment_date']) {
            $activity[] = [
                'ts'    => strtotime((string)$pm['payment_date']),
                'title' => 'Payment ' . $pm['payment_status'] . ' · ' . cm_money($pm['amount']),
                'meta'  => appt_code((int)$pm['appointment_id']) . ' · ' . appt_date_label(substr((string)$pm['payment_date'], 0, 10)),
                'type'  => 'payment',
            ];
        }
    }
    if (!empty($r['tier_start'])) {
        $activity[] = ['ts' => strtotime((string)$r['tier_start']), 'title' => $r['tier'] . ' membership started', 'meta' => appt_date_label($r['tier_start']), 'type' => 'membership'];
    }
    if (!empty($r['updated_at'])) {
        $activity[] = ['ts' => strtotime((string)$r['updated_at']), 'title' => 'Profile updated', 'meta' => appt_date_label(substr((string)$r['updated_at'], 0, 10)), 'type' => 'profile'];
    }
    usort($activity, static fn($x, $y) => $y['ts'] <=> $x['ts']);
    $activity = array_map(static fn($x) => ['title' => $x['title'], 'meta' => $x['meta'], 'type' => $x['type']], array_slice($activity, 0, 6));

    return [
        'customer' => $base + [
            'first_name'       => (string)($r['first_name'] ?? ''),
            'middle_name'      => (string)($r['middle_name'] ?? ''),
            'last_name'        => (string)($r['last_name'] ?? ''),
            'dob'              => (string)($r['date_of_birth'] ?? ''),
            'dob_label'        => cm_date($r['date_of_birth'] ?? null),
            'gender'           => (string)($r['gender'] ?? ''),
            'address'          => (string)($r['address'] ?? ''),
            'notes'            => (string)($r['notes'] ?? ''),
            'preferences'      => (string)($r['preferences'] ?? ''),
            'special_requests' => (string)($r['special_requests'] ?? ''),
            'registered_raw'   => substr((string)($r['registered_at'] ?? ''), 0, 10),
            'tier_expiry'      => cm_date($r['tier_expiry'] ?? null),
        ],
        'counts'     => $counts,
        'filter'     => $filter,
        'history'    => cm_history($pdo, $id, $filter),
        'payments'   => array_map(static fn($pm) => [
            'code'   => appt_code((int)$pm['appointment_id']),
            'amount' => cm_money($pm['amount']),
            'method' => (string)($pm['payment_method'] ?? '—'),
            'status' => (string)$pm['payment_status'],
            'date'   => cm_date($pm['payment_date'] ? substr((string)$pm['payment_date'], 0, 10) : null),
        ], $payments),
        'stats'      => [
            'top_service'   => $top[$id] ?? null,
            'avg_spend'     => $paidCount > 0 ? cm_money($base['spent'] / $paidCount) : '—',
            'completed'     => $counts['completed'],
            'returning'     => $counts['completed'] >= 2,
            'lifetime'      => $base['spent_label'],
        ],
        'preferred'  => $preferred,
        'alerts'     => $alerts,
        'activity'   => $activity,
        'next'       => $nextAppt ? ['code' => appt_code((int)$nextAppt['id']), 'service' => (string)($nextAppt['service_name'] ?? ''), 'date' => appt_date_label($nextAppt['appointment_date']), 'time' => appt_time_label($nextAppt['start_time'])] : null,
    ];
}

function cm_alerts(PDO $pdo, array $r, ?array $next): array
{
    $id = (int)$r['id'];
    $out = [];

    if ($next) {
        $days = (int)floor((strtotime($next['appointment_date']) - strtotime(date('Y-m-d'))) / 86400);
        $when = $days <= 0 ? 'today' : ($days === 1 ? 'tomorrow' : appt_date_label($next['appointment_date']));
        $out[] = ['tone' => 'info', 'title' => 'Upcoming appointment', 'text' => ($next['service_name'] ?? 'Appointment') . ' ' . $when . ' at ' . appt_time_label($next['start_time']) . '.'];
    }

    $stmt = $pdo->prepare("SELECT MAX(appointment_date) FROM appointments WHERE customer_id = ? AND appointment_status = 'completed'");
    $stmt->execute([$id]);
    $lastDone = $stmt->fetchColumn();
    if (($r['customer_status'] ?? 'active') === 'inactive') {
        $out[] = ['tone' => 'warn', 'title' => 'Inactive customer', 'text' => 'This profile is marked inactive.'];
    } elseif ($lastDone && $lastDone < date('Y-m-d', strtotime('-90 days')) && !$next) {
        $out[] = ['tone' => 'warn', 'title' => 'Hasn’t visited recently', 'text' => 'Last completed visit was ' . appt_date_label((string)$lastDone) . '.'];
    }

    if (!empty($r['date_of_birth'])) {
        $dob  = new DateTime((string)$r['date_of_birth']);
        $next = new DateTime(date('Y') . '-' . $dob->format('m-d'));
        if ($next < new DateTime('today')) {
            $next->modify('+1 year');
        }
        $d = (int)(new DateTime('today'))->diff($next)->days;
        if ($d <= 14) {
            $out[] = ['tone' => 'info', 'title' => 'Birthday approaching', 'text' => $d === 0 ? 'Birthday is today.' : 'Birthday in ' . $d . ' day' . ($d === 1 ? '' : 's') . ' (' . $next->format('M j') . ').'];
        }
    }

    if (!empty($r['tier']) && !empty($r['tier_expiry'])) {
        $d = (int)floor((strtotime((string)$r['tier_expiry']) - strtotime(date('Y-m-d'))) / 86400);
        if ($d >= 0 && $d <= 30) {
            $out[] = ['tone' => 'warn', 'title' => 'Membership nearing expiration', 'text' => $r['tier'] . ' membership expires ' . appt_date_label((string)$r['tier_expiry']) . '.'];
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id = ? AND appointment_status = 'no_show' AND appointment_date >= ?");
    $stmt->execute([$id, date('Y-m-d', strtotime('-30 days'))]);
    if ((int)$stmt->fetchColumn() > 0) {
        $out[] = ['tone' => 'warn', 'title' => 'Follow-up needed', 'text' => 'Missed an appointment in the last 30 days.'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id = ? AND appointment_status = 'completed' AND payment_status IN ('pending', 'deposit')");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $out[] = ['tone' => 'warn', 'title' => 'Payment pending', 'text' => 'A completed appointment is not fully paid.'];
    }
    return $out;
}

/* ---------- Validation + save ---------- */

function cm_clean_input(array $in, bool $requireEmail): array
{
    $err = [];
    $t = static fn(string $k, int $max): string => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);

    $first = $t('first_name', 80);
    $mid   = $t('middle_name', 80);
    $last  = $t('last_name', 80);
    $email = strtolower($t('email', 150));
    $phone = $t('phone', 30);

    if ($first === '') { $err['first_name'] = 'First name is required.'; }
    if ($last === '')  { $err['last_name']  = 'Last name is required.'; }
    if ($email === '') {
        if ($requireEmail) { $err['email'] = 'Email address is required.'; }
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err['email'] = 'Enter a valid email address.';
    }
    if ($phone === '') {
        $err['phone'] = 'Contact number is required.';
    } elseif (!preg_match('/^[+()\d][\d\s().+-]*$/', $phone) || strlen((string)preg_replace('/\D+/', '', $phone)) < 7 || strlen((string)preg_replace('/\D+/', '', $phone)) > 15) {
        $err['phone'] = 'Enter a valid contact number (7–15 digits).';
    }

    $dob = $t('date_of_birth', 10);
    if ($dob !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob || $dob > date('Y-m-d') || $dob < '1900-01-01') {
            $err['date_of_birth'] = 'Enter a valid date of birth.';
        }
    }
    $gender = $t('gender', 20);
    if ($gender !== '' && !in_array($gender, ['female', 'male', 'other', 'prefer_not'], true)) {
        $err['gender'] = 'Select a valid option.';
    }
    $status = $t('customer_status', 20) ?: 'active';
    if (!in_array($status, ['active', 'inactive'], true)) {
        $err['customer_status'] = 'Status must be Active or Inactive.';
    }
    $reg = $t('registered_at', 10);
    if ($reg !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $reg);
        if (!$d || $d->format('Y-m-d') !== $reg || $reg > date('Y-m-d')) {
            $err['registered_at'] = 'Registration date cannot be in the future.';
        }
    }

    return [
        $err,
        [
            'first_name' => $first, 'middle_name' => $mid, 'last_name' => $last,
            'full_name'  => trim(preg_replace('/\s+/', ' ', $first . ' ' . $mid . ' ' . $last)),
            'email' => $email, 'phone' => $phone,
            'date_of_birth' => $dob, 'gender' => $gender,
            'address' => $t('address', 255), 'customer_status' => $status, 'registered_at' => $reg,
            'notes' => $t('notes', 2000), 'preferences' => $t('preferences', 1000), 'special_requests' => $t('special_requests', 1000),
        ],
    ];
}

function cm_find_duplicate(PDO $pdo, string $email, string $phone, int $excludeId = 0): ?array
{
    $conds = [];
    $params = [];
    if ($email !== '') {
        $conds[] = 'LOWER(email) = ?';
        $params[] = strtolower($email);
    }
    $digits = cm_digits($phone);
    if (strlen($digits) >= 7) {
        $conds[] = "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), " . strlen($digits) . ') = ?';
        $params[] = $digits;
    }
    if (!$conds) {
        return null;
    }
    $params[] = $excludeId;
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE (' . implode(' OR ', $conds) . ') AND id <> ? ORDER BY id LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function cm_dup_message(array $dup, string $email): string
{
    $same = $email !== '' && strtolower((string)$dup['email']) === strtolower($email);
    return 'A guest with this ' . ($same ? 'email address' : 'contact number') . ' already exists: ' . $dup['full_name'] . ' (' . cm_code((int)$dup['id']) . ').';
}

/** Validates + stores an uploaded photo. Returns [error|null, filename|null]. */
function cm_store_photo(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['The photo could not be uploaded.', null];
    }
    if ($file['size'] > CM_PHOTO_MAX) {
        return ['The photo must be 2 MB or smaller.', null];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize($file['tmp_name']) === false) {
        return ['Photo must be a JPG, PNG or WebP image.', null];
    }
    if (!is_dir(CM_UPLOAD_DIR) && !mkdir(CM_UPLOAD_DIR, 0755, true) && !is_dir(CM_UPLOAD_DIR)) {
        return ['Unable to store the photo.', null];
    }
    $ht = CM_UPLOAD_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "php_flag engine off\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php5\n");
    }
    $name = bin2hex(random_bytes(14)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], CM_UPLOAD_DIR . '/' . $name)) {
        return ['Unable to store the photo.', null];
    }
    return [null, $name];
}

function cm_drop_photo(?string $name): void
{
    $name = $name ? basename($name) : '';
    if ($name !== '' && is_file(CM_UPLOAD_DIR . '/' . $name)) {
        @unlink(CM_UPLOAD_DIR . '/' . $name);
    }
}

function cm_save(PDO $pdo, array $in, ?array $file, ?int $id): array
{
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, email, profile_photo FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            return ['ok' => false, 'error' => 'That guest no longer exists.'];
        }
    }

    [$err, $d] = cm_clean_input($in, $existing === null || !empty($existing['email']));
    if ($err) {
        return ['ok' => false, 'error' => reset($err), 'fields' => $err];
    }
    $dup = cm_find_duplicate($pdo, $d['email'], $d['phone'], $id ?? 0);
    if ($dup) {
        return ['ok' => false, 'error' => cm_dup_message($dup, $d['email']), 'fields' => [str_contains(cm_dup_message($dup, $d['email']), 'email') ? 'email' : 'phone' => 'Already registered.']];
    }
    [$photoErr, $photo] = cm_store_photo($file);
    if ($photoErr) {
        return ['ok' => false, 'error' => $photoErr, 'fields' => ['profile_photo' => $photoErr]];
    }

    $nullable = static fn(string $v): ?string => $v === '' ? null : $v;
    try {
        if ($id === null) {
            $reg = $d['registered_at'] !== '' ? $d['registered_at'] . ' ' . date('H:i:s') : now();
            $pdo->prepare(
                'INSERT INTO customers (full_name, first_name, middle_name, last_name, email, phone, date_of_birth, gender, address,
                                        profile_photo, customer_status, registered_at, notes, preferences, special_requests, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)'
            )->execute([
                $d['full_name'], $d['first_name'], $nullable($d['middle_name']), $d['last_name'], $nullable($d['email']), $d['phone'],
                $nullable($d['date_of_birth']), $nullable($d['gender']), $nullable($d['address']), $photo, $d['customer_status'], $reg,
                $nullable($d['notes']), $nullable($d['preferences']), $nullable($d['special_requests']),
            ]);
            $id = (int)$pdo->lastInsertId();
            return ['ok' => true, 'id' => $id, 'message' => $d['full_name'] . ' registered as ' . cm_code($id) . '.'];
        }
        $pdo->prepare(
            'UPDATE customers SET full_name = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, date_of_birth = ?,
                    gender = ?, address = ?, profile_photo = COALESCE(?, profile_photo), customer_status = ?, notes = ?, preferences = ?,
                    special_requests = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $d['full_name'], $d['first_name'], $nullable($d['middle_name']), $d['last_name'], $nullable($d['email']), $d['phone'],
            $nullable($d['date_of_birth']), $nullable($d['gender']), $nullable($d['address']), $photo, $d['customer_status'],
            $nullable($d['notes']), $nullable($d['preferences']), $nullable($d['special_requests']), now(), $id,
        ]);
        if ($photo !== null) {
            cm_drop_photo($existing['profile_photo'] ?? null);
        }
        return ['ok' => true, 'id' => $id, 'message' => 'Profile for ' . $d['full_name'] . ' updated.'];
    } catch (Throwable $e) {
        cm_drop_photo($photo);
        error_log('[BVO customers] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to save the guest right now.'];
    }
}

function cm_set_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'error' => 'Invalid status.'];
    }
    $stmt = $pdo->prepare('UPDATE customers SET customer_status = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$status, now(), $id]);
    $chk = $pdo->prepare('SELECT full_name FROM customers WHERE id = ?');
    $chk->execute([$id]);
    $name = $chk->fetchColumn();
    if ($name === false) {
        return ['ok' => false, 'error' => 'That guest no longer exists.'];
    }
    return ['ok' => true, 'id' => $id, 'message' => $name . ' is now ' . $status . '.'];
}

function cm_delete(PDO $pdo, int $id): array
{
    $photo = null;
    $name = '';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT full_name, profile_photo FROM customers WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That guest no longer exists.'];
        }
        $name = (string)$row['full_name'];
        $photo = $row['profile_photo'] ?? null;

        $pdo->prepare('DELETE FROM payments WHERE customer_id = ? OR appointment_id IN (SELECT id FROM appointments WHERE customer_id = ?)')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM appointments WHERE customer_id = ?')->execute([$id]);
        foreach (['reward_redemptions', 'loyalty_transactions', 'membership_history', 'memberships', 'customer_tokens'] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE customer_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO customer delete] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to delete the guest right now.'];
    }
    cm_drop_photo(is_string($photo) ? $photo : null);
    return ['ok' => true, 'id' => $id, 'message' => $name . ' and all related records were deleted permanently.'];
}

/* ---------- Walk-in ---------- */

function cm_walkin(PDO $pdo, array $in): array
{
    $fail = static fn(string $m, array $x = []): array => ['ok' => false, 'error' => $m] + $x;

    $serviceId = (int)($in['service_id'] ?? 0);
    if ($serviceId < 1) {
        return $fail('Select the requested service.');
    }
    $date = (string)($in['appointment_date'] ?? '');
    $time = (string)($in['start_time'] ?? '');
    if (appt_parse_date($date) === null) { return $fail('Enter a valid appointment date.'); }
    if (appt_parse_time($time) === null) { return $fail('Enter a valid preferred time.'); }

    $health = bk_health($in);
    if (!$health['ok']) { return $fail((string)$health['error']); }

    $customerId = (int)($in['customer_id'] ?? 0);
    $created = false;
    $reused  = false;
    $note = mb_substr(trim((string)($in['notes'] ?? '')), 0, 500);

    if ($customerId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) {
            return $fail('The selected guest no longer exists.');
        }
        $reused = true;
    } else {
        $name  = trim((string)preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
        $phone = trim((string)($in['phone'] ?? ''));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if ($name === '') { return $fail('Enter the guest’s name.'); }
        $parts = explode(' ', $name, 2);
        [$err, $d] = cm_clean_input(['first_name' => $parts[0], 'last_name' => $parts[1] ?? $parts[0], 'email' => $email, 'phone' => $phone], false);
        unset($err['last_name']);
        if ($err) { return $fail(reset($err), ['fields' => $err]); }

        $dup = cm_find_duplicate($pdo, $email, $phone);
        if ($dup) {
            $customerId = (int)$dup['id'];
            $reused = true;
        } else {
            $pdo->prepare(
                "INSERT INTO customers (full_name, first_name, last_name, email, phone, customer_status, registered_at)
                 VALUES (?, ?, ?, ?, ?, 'active', ?)"
            )->execute([$name, $parts[0], $parts[1] ?? null, $email !== '' ? $email : null, $phone, now()]);
            $customerId = (int)$pdo->lastInsertId();
            $created = true;
        }
    }

    // A guest created only for this request is removed again if the booking cannot be made,
    // so a failed walk-in never leaves an empty profile behind. Existing guests are never touched.
    $abort = static function (string $m, array $x = []) use ($pdo, &$customerId, $created): array {
        if ($created && $customerId > 0) {
            $pdo->prepare('DELETE FROM customers WHERE id = ? AND NOT EXISTS (SELECT 1 FROM appointments WHERE customer_id = ?)')->execute([$customerId, $customerId]);
            return ['ok' => false, 'error' => $m] + $x;
        }
        return ['ok' => false, 'error' => $m, 'customer_id' => $customerId] + $x;
    };

    $roomPick = (int)($in['room_id'] ?? 0);
    $therPick = (int)($in['therapist_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT room_code FROM services WHERE id = ?');
    $stmt->execute([$serviceId]);
    $roomCode = strtoupper((string)$stmt->fetchColumn());
    if ($roomCode === '') { return $abort('The selected service no longer exists.'); }
    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE room_status <> 'maintenance' AND room_name LIKE ? ORDER BY id");
    $stmt->execute([$roomCode . '-%']);
    $rooms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $thers = $pdo->query("SELECT id FROM therapists WHERE availability_status <> 'on_leave' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if ($roomPick > 0) { $rooms = [$roomPick]; }
    if ($therPick > 0) { $thers = [$therPick]; } else { $thers[] = 0; }

    $info = ['customer_id' => $customerId, 'customer_created' => $created, 'customer_reused' => $reused];
    $lastErr = 'No suite is available for that time.';
    $tries = 0;
    foreach ($rooms as $rid) {
        foreach ($thers as $tid) {
            if (++$tries > 150) { break 2; }
            $res = appt_save($pdo, [
                'customer_id' => $customerId, 'service_id' => $serviceId, 'room_id' => (int)$rid, 'therapist_id' => (int)$tid,
                'appointment_date' => $date, 'start_time' => $time, 'appointment_status' => 'confirmed', 'payment_status' => 'pending',
                'guest_count' => (string)($in['guest_count'] ?? ''),
                'health_conditions' => $health['conditions'], 'health_injuries' => $health['injuries'], 'is_pregnant' => (string)$health['pregnant'],
            ], null);
            if (!empty($res['ok'])) {
                if ($note !== '') {
                    $pdo->prepare("UPDATE customers SET notes = CONCAT(COALESCE(CONCAT(notes, '\n'), ''), ?), updated_at = ? WHERE id = ?")
                        ->execute(['Walk-in ' . date('Y-m-d') . ': ' . $note, now(), $customerId]);
                }
                return $info + ['ok' => true, 'appointment_id' => $res['id'], 'message' => ($created ? 'Guest registered. ' : '') . $res['message']];
            }
            $lastErr = (string)$res['error'];
            if (!preg_match('/booked|cleaning gap|on leave|maintenance|inactive|scheduled to work|shift hours/i', $lastErr)) {
                return $abort($lastErr);
            }
        }
    }
    $auto = $roomPick === 0 && $therPick === 0;
    return $abort($auto ? 'No suite is free at that time. Try another time.' : $lastErr);
}