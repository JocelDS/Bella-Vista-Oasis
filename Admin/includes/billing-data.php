<?php
declare(strict_types=1);

require_once __DIR__ . '/appointments-data.php';

const BILL_CHANNELS = ['GCash', 'Maya', 'Bank Transfer', 'Card', 'Cash', 'Post to Suite'];
const BILL_DIGITAL  = ['GCash', 'Maya', 'Bank Transfer', 'Card'];
const BILL_SORTS    = ['date' => 'paid_at', 'invoice' => 'invoice', 'guest' => 'guest', 'gross' => 'net', 'settled' => 'amount'];
const BILL_TABS     = ['all', 'completed', 'pending', 'refunds'];
const BILL_RANGES   = ['today', 'yesterday', 'week', 'month', 'custom', 'all'];

function bill_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM payments LIKE 'invoice_number'")->fetch();
        $ok  = (bool)$has;
        foreach (['appointment_charges', 'service_addons', 'payment_audit'] as $t) {
            if ($ok && !$pdo->query("SHOW TABLES LIKE '$t'")->fetch()) {
                $ok = false;
            }
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function bill_can_authorize(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    $role = strtolower((string)($_SESSION['admin_role'] ?? ''));
    foreach (['viewer', 'read', 'staff', 'reception', 'therapist', 'guest', 'cashier'] as $limited) {
        if ($role !== '' && str_contains($role, $limited)) {
            return false;
        }
    }
    return true;
}

function bill_config(PDO $pdo): array
{
    $cfg = [];
    try {
        $cfg = $pdo->query("SELECT setting_key, setting_value FROM spa_configuration WHERE setting_key IN ('addon_commission_percent', 'advance_deposit_percent', 'cancellation_notice_hours', 'daily_revenue_target')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $cfg = [];
    }
    $num = static fn(string $k): ?float => isset($cfg[$k]) && is_numeric($cfg[$k]) ? (float)$cfg[$k] : null;
    return [
        'commission' => $num('addon_commission_percent'),
        'deposit'    => $num('advance_deposit_percent'),
        'notice'     => (int)($num('cancellation_notice_hours') ?? 6),
        'target'     => $num('daily_revenue_target'),
    ];
}

function bill_params(array $src): array
{
    $tab = (string)($src['tab'] ?? 'all');
    if (!in_array($tab, BILL_TABS, true)) {
        $tab = 'all';
    }
    $range = (string)($src['range'] ?? 'today');
    if (!in_array($range, BILL_RANGES, true)) {
        $range = 'today';
    }
    $channel = (string)($src['channel'] ?? '');
    if (!in_array($channel, BILL_CHANNELS, true)) {
        $channel = '';
    }
    $sort = (string)($src['sort'] ?? 'date');
    if (!isset(BILL_SORTS[$sort])) {
        $sort = 'date';
    }
    $per = (int)($src['per'] ?? 8);
    if (!in_array($per, [5, 8, 10, 25, 50], true)) {
        $per = 8;
    }
    return [
        'tab'     => $tab,
        'range'   => $range,
        'from'    => appt_parse_date((string)($src['from'] ?? '')),
        'to'      => appt_parse_date((string)($src['to'] ?? '')),
        'q'       => mb_substr(trim((string)($src['q'] ?? '')), 0, 80),
        'channel' => $channel,
        'service' => max(0, (int)($src['service'] ?? 0)),
        'sort'    => $sort,
        'dir'     => strtolower((string)($src['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC',
        'page'    => max(1, (int)($src['page'] ?? 1)),
        'per'     => $per,
    ];
}

function bill_dates(array $p): ?array
{
    $today = new DateTimeImmutable('today');
    switch ($p['range']) {
        case 'today':
            return [$today->format('Y-m-d'), $today->format('Y-m-d')];
        case 'yesterday':
            $y = $today->modify('-1 day')->format('Y-m-d');
            return [$y, $y];
        case 'week':
            $from = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
            return [$from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d')];
        case 'month':
            return [$today->format('Y-m-01'), $today->format('Y-m-t')];
        case 'custom':
            $from = $p['from'] ?? $today->format('Y-m-d');
            $to   = $p['to'] ?? $from;
            return $from <= $to ? [$from, $to] : [$to, $from];
    }
    return null;
}

function bill_base_sql(): string
{
    return "SELECT p.id, COALESCE(p.invoice_number, CONCAT('BVO-INV-', LPAD(p.id, 4, '0'))) AS invoice,
                   p.transaction_reference AS txn_ref, p.external_reference AS ext_ref, p.amount,
                   p.payment_method AS channel, p.payment_status AS pstatus, p.payment_date AS paid_at,
                   p.appointment_id, p.transaction_type AS ttype,
                   a.service_id, a.downpayment_amount, a.payment_status AS appt_ps, a.appointment_status AS appt_st,
                   c.full_name AS guest, s.service_name AS service,
                   (COALESCE(a.total_amount, p.amount) + COALESCE(ch.addons, 0) - COALESCE(ch.disc, 0)) AS net,
                   (COALESCE(a.total_amount, p.amount) + COALESCE(ch.addons, 0)) AS gross,
                   COALESCE(pd.dep, 0) AS dep_rows, COALESCE(pv.valid, 0) AS valid_paid
            FROM payments p
            LEFT JOIN appointments a ON a.id = p.appointment_id
            LEFT JOIN customers c    ON c.id = COALESCE(p.customer_id, a.customer_id)
            LEFT JOIN services s     ON s.id = a.service_id
            LEFT JOIN (SELECT appointment_id,
                              SUM(CASE WHEN charge_type = 'addon' THEN amount ELSE 0 END) AS addons,
                              SUM(CASE WHEN charge_type = 'discount' THEN amount ELSE 0 END) AS disc
                       FROM appointment_charges GROUP BY appointment_id) ch ON ch.appointment_id = a.id
            LEFT JOIN (SELECT appointment_id, SUM(amount) AS dep FROM payments
                       WHERE payment_status = 'deposit' OR transaction_type = 'deposit' GROUP BY appointment_id) pd ON pd.appointment_id = a.id
            LEFT JOIN (SELECT appointment_id, SUM(amount) AS valid FROM payments
                       WHERE payment_status IN ('paid', 'deposit') GROUP BY appointment_id) pv ON pv.appointment_id = a.id";
}

function bill_status_sql(): string
{
    return "SELECT b.*, CASE
                WHEN b.pstatus = 'voided' THEN 'voided'
                WHEN b.pstatus = 'refunded' THEN 'refunded'
                WHEN b.pstatus = 'pending' THEN 'pending'
                WHEN b.pstatus = 'settled' THEN 'paid'
                WHEN b.net - b.valid_paid <= 0.004 THEN 'paid'
                ELSE 'partial' END AS rstatus,
                GREATEST(b.dep_rows, COALESCE(b.downpayment_amount, 0)) AS deposit,
                GREATEST(b.net - b.valid_paid, 0) AS balance
            FROM (" . bill_base_sql() . ") b";
}

function bill_where(array $p, bool $withTab): array
{
    $where  = [];
    $params = [];
    $dates  = bill_dates($p);
    if ($dates) {
        $where[]  = 'DATE(r.paid_at) BETWEEN ? AND ?';
        $params[] = $dates[0];
        $params[] = $dates[1];
    }
    if ($p['q'] !== '') {
        $like = '%' . addcslashes($p['q'], '%_\\') . '%';
        $or   = ['r.guest LIKE ?', 'r.invoice LIKE ?', 'r.txn_ref LIKE ?', 'r.ext_ref LIKE ?'];
        $params = array_merge($params, [$like, $like, $like, $like]);
        if (preg_match('/^(?:bvo-?)?0*(\d{1,9})$/i', $p['q'], $m)) {
            $or[]     = 'r.appointment_id = ?';
            $params[] = (int)$m[1];
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if ($p['channel'] !== '') {
        $where[]  = 'r.channel = ?';
        $params[] = $p['channel'];
    }
    if ($p['service'] > 0) {
        $where[]  = 'r.service_id = ?';
        $params[] = $p['service'];
    }
    if ($withTab && $p['tab'] !== 'all') {
        $map = ['completed' => "('paid')", 'pending' => "('pending', 'partial')", 'refunds' => "('refunded', 'voided')"];
        $where[] = 'r.rstatus IN ' . $map[$p['tab']];
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function bill_row_out(array $r): array
{
    $settled = in_array($r['pstatus'], ['paid', 'deposit'], true) ? (float)$r['amount'] : 0.0;
    $ts      = $r['paid_at'] ? strtotime((string)$r['paid_at']) : false;
    return [
        'id'         => (int)$r['id'],
        'invoice'    => (string)$r['invoice'],
        'date'       => $ts ? date('M j, Y', $ts) : '—',
        'time'       => $ts ? date('g:i A', $ts) : '—',
        'guest'      => (string)($r['guest'] ?? 'Unknown guest'),
        'service'    => (string)($r['service'] ?? '—'),
        'ref'        => $r['appointment_id'] ? appt_code((int)$r['appointment_id']) : '—',
        'channel'    => (string)($r['channel'] ?: 'Front Desk'),
        'ext_ref'    => (string)($r['ext_ref'] ?? ''),
        'gross'      => (float)$r['gross'],
        'deposit'    => (float)$r['deposit'],
        'settled'    => $settled,
        'balance'    => (float)$r['balance'],
        'status'     => (string)$r['rstatus'],
        'pstatus'    => (string)$r['pstatus'],
        'appointment_id' => (int)$r['appointment_id'],
    ];
}

function bill_list(PDO $pdo, array $p, bool $all = false): array
{
    [$whereAll, $paramsAll] = bill_where($p, false);
    $stmt = $pdo->prepare('SELECT r.rstatus, COUNT(*) AS c FROM (' . bill_status_sql() . ') r' . $whereAll . ' GROUP BY r.rstatus');
    $stmt->execute($paramsAll);
    $by = [];
    foreach ($stmt->fetchAll() as $x) {
        $by[(string)$x['rstatus']] = (int)$x['c'];
    }
    $counts = [
        'all'       => array_sum($by),
        'completed' => $by['paid'] ?? 0,
        'pending'   => ($by['pending'] ?? 0) + ($by['partial'] ?? 0),
        'refunds'   => ($by['refunded'] ?? 0) + ($by['voided'] ?? 0),
    ];
    $total = $counts[$p['tab']];

    [$where, $params] = bill_where($p, true);
    $per   = $all ? 5000 : $p['per'];
    $pages = max(1, (int)ceil($total / $per));
    $page  = $all ? 1 : min($p['page'], $pages);
    $order = BILL_SORTS[$p['sort']];
    $sql   = 'SELECT r.* FROM (' . bill_status_sql() . ') r' . $where . ' ORDER BY r.' . $order . ' ' . $p['dir'] . ', r.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
    $stmt  = $pdo->prepare($sql);
    $stmt->execute($params);
    return [
        'rows'   => array_map('bill_row_out', $stmt->fetchAll()),
        'counts' => $counts,
        'total'  => $total,
        'page'   => $page,
        'pages'  => $pages,
        'per'    => $per,
    ];
}

function bill_summary(PDO $pdo): array
{
    $cfg       = bill_config($pdo);
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $sum = static function (string $day) use ($pdo): float {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'paid' AND DATE(payment_date) = ?");
        $s->execute([$day]);
        return (float)$s->fetchColumn();
    };
    $gross = $sum($today);
    $prev  = $sum($yesterday);
    $pct   = $prev > 0 ? round((($gross - $prev) / $prev) * 100, 1) : null;

    $stmt = $pdo->prepare('SELECT payment_status AS s, payment_method AS m, COUNT(*) AS c FROM payments WHERE DATE(payment_date) = ? GROUP BY payment_status, payment_method');
    $stmt->execute([$today]);
    $total = 0;
    $settled = 0;
    $digital = 0;
    $cash = 0;
    foreach ($stmt->fetchAll() as $x) {
        $c = (int)$x['c'];
        $total += $c;
        if ($x['s'] === 'paid') {
            $settled += $c;
            if (in_array($x['m'], BILL_DIGITAL, true)) {
                $digital += $c;
            } elseif ($x['m'] === 'Cash') {
                $cash += $c;
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c, COALESCE(SUM(GREATEST(COALESCE(d.dep, 0), COALESCE(a.downpayment_amount, 0))), 0) AS amt
         FROM appointments a
         LEFT JOIN (SELECT appointment_id, SUM(amount) AS dep FROM payments WHERE payment_status = 'deposit' GROUP BY appointment_id) d ON d.appointment_id = a.id
         WHERE a.payment_status = 'deposit' AND a.appointment_status IN ('pending', 'confirmed') AND a.appointment_date >= ?"
    );
    $stmt->execute([$today]);
    $esc = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) AS up, SUM(CASE WHEN payment_status = 'deposit' THEN 1 ELSE 0 END) AS dep FROM appointments WHERE appointment_status IN ('pending', 'confirmed') AND appointment_date >= ?");
    $stmt->execute([$today]);
    $upc = $stmt->fetch();
    $depPct = (int)$upc['up'] > 0 ? (int)round(((int)$upc['dep'] / (int)$upc['up']) * 100) : 0;

    $open = bill_open_tabs($pdo, $today);

    return [
        'gross'   => $gross,
        'pct'     => $pct,
        'target'  => $cfg['target'],
        'settled' => ['count' => $settled, 'total' => $total, 'digital' => $digital, 'cash' => $cash, 'pct' => $total > 0 ? (int)round(($settled / $total) * 100) : 0],
        'escrow'  => ['amount' => (float)$esc['amt'], 'count' => (int)$esc['c'], 'pct' => $depPct],
        'open'    => $open,
        'date'    => date('M j, Y'),
    ];
}

function bill_open_tabs(PDO $pdo, string $today): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_status AS st, a.appointment_date AS d, a.payment_status AS ps, a.total_amount, a.downpayment_amount,
                COALESCE(ch.addons, 0) AS addons, COALESCE(ch.disc, 0) AS disc, COALESCE(pv.valid, 0) AS valid
         FROM appointments a
         LEFT JOIN (SELECT appointment_id,
                           SUM(CASE WHEN charge_type = 'addon' THEN amount ELSE 0 END) AS addons,
                           SUM(CASE WHEN charge_type = 'discount' THEN amount ELSE 0 END) AS disc
                    FROM appointment_charges GROUP BY appointment_id) ch ON ch.appointment_id = a.id
         LEFT JOIN (SELECT appointment_id, SUM(amount) AS valid FROM payments WHERE payment_status IN ('paid', 'deposit') GROUP BY appointment_id) pv ON pv.appointment_id = a.id
         WHERE a.payment_status IN ('pending', 'deposit')
           AND ((a.appointment_status = 'confirmed' AND a.appointment_date = ?) OR (a.appointment_status = 'completed'))"
    );
    $stmt->execute([$today]);
    $out = 0.0;
    $active = 0;
    $pending = 0;
    foreach ($stmt->fetchAll() as $r) {
        $net  = (float)$r['total_amount'] + (float)$r['addons'] - (float)$r['disc'];
        $recv = $r['ps'] === 'deposit' ? max((float)$r['valid'], (float)$r['downpayment_amount']) : (float)$r['valid'];
        $bal  = max(0.0, $net - $recv);
        if ($bal <= 0) {
            continue;
        }
        $out += $bal;
        if ($r['st'] === 'completed') {
            $pending++;
        } else {
            $active++;
        }
    }
    return ['amount' => $out, 'active' => $active, 'pending' => $pending];
}

function bill_figures(PDO $pdo, int $id, bool $lock = false): ?array
{
    $stmt = $pdo->prepare('SELECT id, customer_id, therapist_id, service_id, appointment_status, payment_status, total_amount, downpayment_amount, appointment_date, start_time, end_time FROM appointments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if (!$a) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN charge_type = 'addon' THEN amount END), 0) AS addons, COALESCE(SUM(CASE WHEN charge_type = 'discount' THEN amount END), 0) AS disc FROM appointment_charges WHERE appointment_id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id = ? AND payment_status IN ('paid', 'deposit')");
    $stmt->execute([$id]);
    $valid = (float)$stmt->fetchColumn();

    $total  = round((float)$a['total_amount'], 2);
    $addons = round((float)$c['addons'], 2);
    $disc   = round((float)$c['disc'], 2);
    $gross  = round($total + $addons, 2);
    $net    = round($gross - $disc, 2);
    $down   = round((float)$a['downpayment_amount'], 2);
    $ps     = (string)$a['payment_status'];
    if ($ps === 'paid') {
        $recv = max($valid, $net);
    } elseif ($ps === 'deposit') {
        $recv = max($valid, $down);
    } else {
        $recv = $valid;
    }
    $recv    = round($recv, 2);
    $blocked = in_array($a['appointment_status'], ['cancelled', 'no_show'], true) || $ps === 'refunded';
    $balance = $blocked ? 0.0 : round(max(0.0, $net - $recv), 2);
    return $a + ['total' => $total, 'addons' => $addons, 'disc' => $disc, 'gross' => $gross, 'net' => $net, 'down' => $down, 'received' => $recv, 'valid_rows' => round($valid, 2), 'balance' => $balance];
}

function bill_folio_list(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT a.id FROM appointments a
         WHERE a.appointment_status IN ('confirmed', 'completed') AND a.payment_status IN ('pending', 'deposit') AND a.appointment_date <= CURDATE()
         ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 80"
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $f = bill_figures($pdo, (int)$id);
        if (!$f || $f['balance'] <= 0) {
            continue;
        }
        $q = $pdo->prepare('SELECT c.full_name, s.service_name FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN services s ON s.id = a.service_id WHERE a.id = ?');
        $q->execute([(int)$id]);
        $n = $q->fetch();
        $out[] = [
            'id'      => (int)$id,
            'code'    => appt_code((int)$id),
            'guest'   => (string)($n['full_name'] ?? 'Unknown guest'),
            'service' => (string)($n['service_name'] ?? '—'),
            'date'    => appt_date_label((string)$f['appointment_date']),
            'balance' => $f['balance'],
            'status'  => (string)$f['appointment_status'],
        ];
    }
    return $out;
}

function bill_folio(PDO $pdo, int $id): ?array
{
    $f = bill_figures($pdo, $id);
    if (!$f) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.full_name, c.email, c.phone, s.service_name, s.duration, t.full_name AS therapist, r.room_name
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN services s ON s.id = a.service_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         LEFT JOIN rooms r ON r.id = a.room_id
         WHERE a.id = ?'
    );
    $stmt->execute([$id]);
    $n = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT id, charge_type, label, amount FROM appointment_charges WHERE appointment_id = ? ORDER BY id ASC');
    $stmt->execute([$id]);
    $charges = $stmt->fetchAll();

    $addons = $pdo->query('SELECT id, addon_name, price FROM service_addons WHERE is_active = 1 ORDER BY addon_name ASC')->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE appointment_id = ? AND payment_status = 'pending' AND payment_method = 'Post to Suite'");
    $stmt->execute([$id]);
    $posted = (int)$stmt->fetchColumn() > 0;

    $cfg = bill_config($pdo);
    return [
        'id'        => $id,
        'code'      => appt_code($id),
        'guest'     => (string)($n['full_name'] ?? 'Unknown guest'),
        'service'   => (string)($n['service_name'] ?? '—'),
        'therapist' => (string)($n['therapist'] ?? '—'),
        'room'      => (string)($n['room_name'] ?? '—'),
        'date'      => appt_date_label((string)$f['appointment_date']),
        'time'      => appt_time_label((string)$f['start_time']) . ' – ' . appt_time_label((string)$f['end_time']),
        'status'    => (string)$f['appointment_status'],
        'total'     => $f['total'],
        'addons'    => $f['addons'],
        'discount'  => $f['disc'],
        'gross'     => $f['gross'],
        'net'       => $f['net'],
        'deposit'   => $f['received'],
        'balance'   => $f['balance'],
        'charges'   => array_map(static fn(array $c): array => ['id' => (int)$c['id'], 'type' => (string)$c['charge_type'], 'label' => (string)$c['label'], 'amount' => (float)$c['amount']], $charges),
        'catalog'   => array_map(static fn(array $a): array => ['id' => (int)$a['id'], 'name' => (string)$a['addon_name'], 'price' => (float)$a['price']], $addons),
        'posted'    => $posted,
        'commission' => $cfg['commission'],
    ];
}

function bill_sync_status(PDO $pdo, int $id, string $emptyStatus): void
{
    $f = bill_figures($pdo, $id, true);
    if (!$f) {
        return;
    }
    $valid = $f['valid_rows'];
    if ($valid <= 0) {
        $ps = $emptyStatus;
    } elseif ($valid + 0.004 >= $f['net']) {
        $ps = 'paid';
    } else {
        $ps = 'deposit';
    }
    $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ?')->execute([$ps, $id]);
}

function bill_new_ref(): string
{
    return 'TXN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function bill_insert_payment(PDO $pdo, int $apptId, int $customerId, float $amount, ?string $method, string $status, string $type, ?string $ext, ?int $admin, ?string $notes, ?string $key, ?string $date = null): int
{
    $pdo->prepare(
        'INSERT INTO payments (appointment_id, customer_id, amount, payment_method, payment_status, payment_date, transaction_reference, external_reference, transaction_type, idempotency_key, processed_by, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$apptId, $customerId, $amount, $method, $status, $date ?? now(), bill_new_ref(), $ext, $type, $key, $admin, $notes]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE payments SET invoice_number = ? WHERE id = ?')->execute(['BVO-INV-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
    return $id;
}

function bill_settle(PDO $pdo, int $adminId, array $in): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];
    if (!bill_ready($pdo)) {
        return $fail('Run database/payment_management_migration.sql first.');
    }

    $apptId = (int)($in['appointment_id'] ?? 0);
    $method = trim((string)($in['payment_method'] ?? ''));
    $quick  = !empty($in['quick']);
    $ref    = mb_substr(trim((string)($in['reference'] ?? '')), 0, 60);
    $notes  = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);
    $key    = trim((string)($in['idempotency_key'] ?? ''));
    $key    = preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) ? $key : null;

    if ($apptId < 1) {
        return $fail('Select a guest folio to settle.');
    }
    if (!in_array($method, BILL_CHANNELS, true)) {
        return $fail('Select a settlement method.');
    }
    if (!$quick && in_array($method, BILL_DIGITAL, true)) {
        if (mb_strlen($ref) < 4) {
            return $fail('Enter the ' . ($method === 'Card' ? 'terminal approval code' : 'payment reference number') . ' shown on the actual transaction.');
        }
        if ((string)($in['confirmed'] ?? '') !== '1') {
            return $fail('Confirm that the payment was actually received before recording it.');
        }
    }

    $addonIds = array_values(array_filter(array_map('intval', (array)($in['addon_ids'] ?? [])), static fn(int $v): bool => $v > 0));
    if (count($addonIds) > 10) {
        return $fail('Too many add-ons in one settlement.');
    }
    $discRaw = trim((string)($in['discount_amount'] ?? ''));
    if ($discRaw !== '' && (!is_numeric($discRaw) || (float)$discRaw < 0)) {
        return $fail('Enter a valid discount amount.');
    }
    $disc       = $discRaw === '' ? 0.0 : round((float)$discRaw, 2);
    $discReason = mb_substr(trim((string)($in['discount_reason'] ?? '')), 0, 100);
    if ($disc > 0 && mb_strlen($discReason) < 3) {
        return $fail('Enter a reason for the discount.');
    }

    try {
        $pdo->beginTransaction();

        if ($key !== null) {
            $stmt = $pdo->prepare('SELECT id, invoice_number FROM payments WHERE idempotency_key = ?');
            $stmt->execute([$key]);
            $dup = $stmt->fetch();
            if ($dup) {
                $pdo->rollBack();
                return ['ok' => true, 'duplicate' => true, 'payment_id' => (int)$dup['id'], 'invoice' => (string)$dup['invoice_number'], 'message' => 'This payment was already recorded.'];
            }
        }

        $f = bill_figures($pdo, $apptId, true);
        if (!$f) {
            $pdo->rollBack();
            return $fail('That appointment no longer exists.');
        }
        if (!in_array($f['appointment_status'], ['confirmed', 'completed'], true)) {
            $pdo->rollBack();
            return $fail('Only confirmed or completed appointments can be settled.');
        }
        if (in_array($f['payment_status'], ['paid', 'refunded'], true)) {
            $pdo->rollBack();
            return $fail('This appointment is already settled.');
        }

        $cfg = bill_config($pdo);
        if ($addonIds) {
            $in2   = implode(',', array_fill(0, count($addonIds), '?'));
            $stmt  = $pdo->prepare("SELECT id, addon_name, price, commissionable FROM service_addons WHERE is_active = 1 AND id IN ($in2)");
            $stmt->execute($addonIds);
            $cat = [];
            foreach ($stmt->fetchAll() as $c) {
                $cat[(int)$c['id']] = $c;
            }
            foreach ($addonIds as $aid) {
                if (!isset($cat[$aid])) {
                    $pdo->rollBack();
                    return $fail('One of the selected add-ons is no longer available.');
                }
                $c    = $cat[$aid];
                $comm = ((int)$c['commissionable'] === 1 && $cfg['commission'] !== null && (int)$f['therapist_id'] > 0)
                    ? round((float)$c['price'] * $cfg['commission'] / 100, 2) : 0.0;
                $pdo->prepare("INSERT INTO appointment_charges (appointment_id, charge_type, addon_id, label, amount, therapist_id, commission_amount, added_by) VALUES (?, 'addon', ?, ?, ?, ?, ?, ?)")
                    ->execute([$apptId, $aid, $c['addon_name'], $c['price'], (int)$f['therapist_id'] > 0 ? (int)$f['therapist_id'] : null, $comm, $adminId]);
            }
        }

        if ($disc > 0) {
            $f = bill_figures($pdo, $apptId, true);
            if ($f['disc'] + $disc > $f['gross'] + 0.004) {
                $pdo->rollBack();
                return $fail('The discount cannot be more than the gross amount of ' . peso($f['gross']) . '.');
            }
            $pdo->prepare("INSERT INTO appointment_charges (appointment_id, charge_type, label, amount, added_by) VALUES (?, 'discount', ?, ?, ?)")
                ->execute([$apptId, 'Discount · ' . $discReason, $disc, $adminId]);
        }

        $f       = bill_figures($pdo, $apptId, true);
        $balance = $f['balance'];
        if ($balance <= 0) {
            $pdo->rollBack();
            return $fail('There is no balance to collect.');
        }

        $customerId = (int)$f['customer_id'];
        if ($method === 'Post to Suite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE appointment_id = ? AND payment_status = 'pending' AND payment_method = 'Post to Suite'");
            $stmt->execute([$apptId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                return $fail('The balance is already posted to the suite folio.');
            }
            $pid = bill_insert_payment($pdo, $apptId, $customerId, $balance, 'Post to Suite', 'pending', 'suite_charge', $ref !== '' ? $ref : null, $adminId, $notes !== '' ? $notes : null, $key);
            $pdo->commit();
            return ['ok' => true, 'payment_id' => $pid, 'balance' => $balance, 'posted' => true, 'invoice' => 'BVO-INV-' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT), 'message' => peso($balance) . ' posted to the suite folio for ' . appt_code($apptId) . '. It stays open until settled.'];
        }

        $amtRaw = trim((string)($in['amount'] ?? ''));
        $amount = $amtRaw === '' ? $balance : (is_numeric($amtRaw) ? round((float)$amtRaw, 2) : -1.0);
        if ($amount <= 0) {
            $pdo->rollBack();
            return $fail('Enter a payment amount greater than zero.');
        }
        if ($amount > $balance + 0.004) {
            $pdo->rollBack();
            return $fail('The amount cannot exceed the remaining balance of ' . peso($balance) . '.');
        }

        $finishes = $amount + 0.004 >= $balance;
        if ($finishes) {
            if ($f['payment_status'] === 'deposit') {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id = ? AND payment_status IN ('paid', 'deposit')");
                $stmt->execute([$apptId]);
                $rows = round((float)$stmt->fetchColumn(), 2);
                $pdo->prepare("UPDATE payments SET payment_status = 'paid', transaction_type = 'deposit' WHERE appointment_id = ? AND payment_status = 'deposit'")->execute([$apptId]);
                if ($f['down'] > $rows) {
                    bill_insert_payment($pdo, $apptId, $customerId, round($f['down'] - $rows, 2), null, 'paid', 'deposit', null, $adminId, 'Downpayment recorded at settlement', null);
                }
            }
            $pdo->prepare("UPDATE payments SET payment_status = 'settled' WHERE appointment_id = ? AND payment_status = 'pending' AND payment_method = 'Post to Suite'")->execute([$apptId]);
        }

        $pid = bill_insert_payment($pdo, $apptId, $customerId, $amount, $method, 'paid', 'payment', $ref !== '' ? $ref : null, $adminId, $notes !== '' ? $notes : null, $key);
        bill_sync_status($pdo, $apptId, 'pending');
        $left = bill_figures($pdo, $apptId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO bill_settle] ' . get_class($e) . ': ' . $e->getMessage());
        return $fail('Unable to record the payment right now.');
    }

    $invoice = 'BVO-INV-' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT);
    return [
        'ok'         => true,
        'id'         => $apptId,
        'payment_id' => $pid,
        'invoice'    => $invoice,
        'balance'    => $left ? $left['balance'] : 0.0,
        'message'    => 'Payment of ' . peso($amount) . ' received via ' . $method . ' for ' . appt_code($apptId) . ' (' . $invoice . ').' . (($left && $left['balance'] > 0) ? ' Balance left: ' . peso($left['balance']) . '.' : ' Appointment is now fully paid.'),
    ];
}

function bill_reverse(PDO $pdo, int $adminId, int $paymentId, string $action, string $reason): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];
    if (!bill_ready($pdo)) {
        return $fail('Run database/payment_management_migration.sql first.');
    }
    if (!bill_can_authorize()) {
        return $fail('Only an authorized administrator can ' . $action . ' a transaction.');
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (mb_strlen($reason) < 5) {
        return $fail('Enter a clear reason (at least 5 characters).');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, appointment_id, amount, payment_status, payment_date FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $p = $stmt->fetch();
        if (!$p) {
            $pdo->rollBack();
            return $fail('That transaction no longer exists.');
        }
        $st = (string)$p['payment_status'];
        if ($action === 'refund') {
            if (!in_array($st, ['paid', 'deposit'], true)) {
                $pdo->rollBack();
                return $fail('Only received payments can be refunded.');
            }
            $new = 'refunded';
        } else {
            $sameDay = $p['payment_date'] && date('Y-m-d', strtotime((string)$p['payment_date'])) === date('Y-m-d');
            if (!($st === 'pending' || (in_array($st, ['paid', 'deposit'], true) && $sameDay))) {
                $pdo->rollBack();
                return $fail('Only pending charges or payments recorded today can be voided. Use a refund for older payments.');
            }
            $new = 'voided';
        }

        $pdo->prepare('UPDATE payments SET payment_status = ? WHERE id = ?')->execute([$new, $paymentId]);
        $pdo->prepare('INSERT INTO payment_audit (payment_id, appointment_id, action, amount, reason, admin_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$paymentId, $p['appointment_id'] ?: null, $action, $p['amount'], $reason, $adminId]);
        if ($p['appointment_id']) {
            bill_sync_status($pdo, (int)$p['appointment_id'], $action === 'refund' ? 'refunded' : 'pending');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO bill_reverse] ' . get_class($e) . ': ' . $e->getMessage());
        return $fail('Unable to process that right now.');
    }
    return ['ok' => true, 'message' => 'Transaction ' . ($action === 'refund' ? 'refunded' : 'voided') . ' and logged in the audit trail.'];
}

function bill_detail(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare('SELECT r.* FROM (' . bill_status_sql() . ') r WHERE r.id = ?');
    $stmt->execute([$paymentId]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $row = bill_row_out($r);

    $stmt = $pdo->prepare(
        'SELECT p.id, COALESCE(p.invoice_number, CONCAT(\'BVO-INV-\', LPAD(p.id, 4, \'0\'))) AS invoice, p.amount, p.payment_method, p.payment_status, p.payment_date, p.notes, adm.full_name AS admin
         FROM payments p LEFT JOIN administrators adm ON adm.id = p.processed_by
         WHERE p.id = ?'
    );
    $stmt->execute([$paymentId]);
    $self = $stmt->fetch();

    $history = [];
    $charges = [];
    $audit   = [];
    if ($r['appointment_id']) {
        $stmt = $pdo->prepare("SELECT id, COALESCE(invoice_number, CONCAT('BVO-INV-', LPAD(id, 4, '0'))) AS invoice, amount, payment_method, payment_status, payment_date FROM payments WHERE appointment_id = ? ORDER BY id ASC");
        $stmt->execute([(int)$r['appointment_id']]);
        foreach ($stmt->fetchAll() as $h) {
            $ts = $h['payment_date'] ? strtotime((string)$h['payment_date']) : false;
            $history[] = ['id' => (int)$h['id'], 'invoice' => (string)$h['invoice'], 'amount' => (float)$h['amount'], 'method' => (string)($h['payment_method'] ?: 'Front Desk'), 'status' => (string)$h['payment_status'], 'at' => $ts ? date('M j, Y g:i A', $ts) : '—'];
        }
        $stmt = $pdo->prepare('SELECT label, charge_type, amount FROM appointment_charges WHERE appointment_id = ? ORDER BY id ASC');
        $stmt->execute([(int)$r['appointment_id']]);
        foreach ($stmt->fetchAll() as $c) {
            $charges[] = ['label' => (string)$c['label'], 'type' => (string)$c['charge_type'], 'amount' => (float)$c['amount']];
        }
        $stmt = $pdo->prepare('SELECT a.action, a.amount, a.reason, a.created_at, adm.full_name AS admin FROM payment_audit a LEFT JOIN administrators adm ON adm.id = a.admin_id WHERE a.appointment_id = ? ORDER BY a.id DESC');
        $stmt->execute([(int)$r['appointment_id']]);
        foreach ($stmt->fetchAll() as $a) {
            $audit[] = ['action' => (string)$a['action'], 'amount' => (float)$a['amount'], 'reason' => (string)$a['reason'], 'at' => date('M j, Y g:i A', strtotime((string)$a['created_at'])), 'admin' => (string)($a['admin'] ?? '—')];
        }
    }

    $st = (string)$r['pstatus'];
    $sameDay = $r['paid_at'] && date('Y-m-d', strtotime((string)$r['paid_at'])) === date('Y-m-d');
    return $row + [
        'txn_ref'      => (string)($r['txn_ref'] ?? ''),
        'processed_by' => (string)($self['admin'] ?? '—'),
        'notes'        => (string)($self['notes'] ?? ''),
        'net'          => (float)$r['net'],
        'history'      => $history,
        'charges'      => $charges,
        'audit'        => $audit,
        'can_refund'   => bill_can_authorize() && in_array($st, ['paid', 'deposit'], true),
        'can_void'     => bill_can_authorize() && ($st === 'pending' || (in_array($st, ['paid', 'deposit'], true) && $sameDay)),
        'can_pay'      => $r['appointment_id'] && (float)$r['balance'] > 0 && in_array($r['appt_st'], ['confirmed', 'completed'], true) && !in_array($r['appt_ps'], ['paid', 'refunded'], true),
    ];
}

function bill_receipt(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, COALESCE(p.invoice_number, CONCAT('BVO-INV-', LPAD(p.id, 4, '0'))) AS invoice, p.amount, p.payment_method, p.payment_status, p.payment_date,
                p.external_reference, p.appointment_id, adm.full_name AS admin, c.full_name AS guest, s.service_name, s.duration, a.total_amount, a.downpayment_amount, a.payment_status AS appt_ps
         FROM payments p
         LEFT JOIN administrators adm ON adm.id = p.processed_by
         LEFT JOIN appointments a ON a.id = p.appointment_id
         LEFT JOIN customers c ON c.id = COALESCE(p.customer_id, a.customer_id)
         LEFT JOIN services s ON s.id = a.service_id
         WHERE p.id = ?"
    );
    $stmt->execute([$paymentId]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $aid = (int)$r['appointment_id'];
    $f   = $aid > 0 ? bill_figures($pdo, $aid) : null;
    $charges = [];
    if ($aid > 0) {
        $stmt = $pdo->prepare('SELECT label, charge_type, amount FROM appointment_charges WHERE appointment_id = ? ORDER BY id ASC');
        $stmt->execute([$aid]);
        $charges = $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id = ? AND payment_status IN ('paid', 'deposit', 'settled') AND id <= ?");
    $stmt->execute([$aid, $paymentId]);
    $paidToDate = (float)$stmt->fetchColumn();
    $net = $f ? $f['net'] : (float)$r['amount'];
    $dep = $f ? max((float)$r['downpayment_amount'], 0.0) : 0.0;
    return [
        'invoice'  => (string)$r['invoice'],
        'date'     => $r['payment_date'] ? date('M j, Y g:i A', strtotime((string)$r['payment_date'])) : '—',
        'guest'    => (string)($r['guest'] ?? '—'),
        'ref'      => $aid > 0 ? appt_code($aid) : '—',
        'service'  => (string)($r['service_name'] ?? '—'),
        'duration' => (int)($r['duration'] ?? 0),
        'base'     => $f ? $f['total'] : (float)$r['amount'],
        'charges'  => $charges,
        'gross'    => $f ? $f['gross'] : (float)$r['amount'],
        'discount' => $f ? $f['disc'] : 0.0,
        'deposit'  => $dep,
        'paid'     => (float)$r['amount'],
        'balance'  => max(0.0, round($net - $paidToDate, 2)),
        'method'   => (string)($r['payment_method'] ?: 'Front Desk'),
        'ext_ref'  => (string)($r['external_reference'] ?? ''),
        'status'   => (string)$r['payment_status'],
        'admin'    => (string)($r['admin'] ?? '—'),
    ];
}

function bill_drawer(PDO $pdo): array
{
    $today = date('Y-m-d');
    $stmt  = $pdo->prepare("SELECT COALESCE(payment_method, 'Front Desk') AS m, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t FROM payments WHERE payment_status = 'paid' AND DATE(payment_date) = ? GROUP BY COALESCE(payment_method, 'Front Desk') ORDER BY t DESC");
    $stmt->execute([$today]);
    $channels = [];
    $total = 0.0;
    $cash = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $channels[] = ['channel' => (string)$r['m'], 'count' => (int)$r['c'], 'total' => (float)$r['t']];
        $total += (float)$r['t'];
        if ($r['m'] === 'Cash') {
            $cash = (float)$r['t'];
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t FROM payments WHERE payment_status = 'refunded' AND id IN (SELECT payment_id FROM payment_audit WHERE action = 'refund' AND DATE(created_at) = ?)");
    $stmt->execute([$today]);
    $ref = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t FROM payments WHERE payment_status = 'pending' AND payment_method = 'Post to Suite'");
    $stmt->execute();
    $suite = $stmt->fetch();
    return [
        'date'     => date('l, F j, Y'),
        'channels' => $channels,
        'total'    => $total,
        'cash'     => $cash,
        'refunds'  => ['count' => (int)$ref['c'], 'total' => (float)$ref['t']],
        'suite'    => ['count' => (int)$suite['c'], 'total' => (float)$suite['t']],
        'admin'    => (string)($_SESSION['admin_name'] ?? 'Administrator'),
    ];
}

function bill_escrow(PDO $pdo): array
{
    $cfg   = bill_config($pdo);
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(downpayment_amount), 0) AS t FROM appointments WHERE cancellation_forfeited = 1 AND appointment_date = ?");
    $stmt->execute([$today]);
    $forf = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t FROM payment_audit WHERE action = 'refund' AND DATE(created_at) = ?");
    $stmt->execute([$today]);
    $ref = $stmt->fetch();

    $sum = bill_summary($pdo);
    return [
        'deposit_pct' => $cfg['deposit'],
        'notice'      => $cfg['notice'],
        'held'        => $sum['escrow']['amount'],
        'held_count'  => $sum['escrow']['count'],
        'forfeit'     => ['count' => (int)$forf['c'], 'total' => (float)$forf['t']],
        'refunds'     => ['count' => (int)$ref['c'], 'total' => (float)$ref['t']],
    ];
}

function bill_ledger(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT a.id, a.appointment_status AS st, a.payment_status AS ps, a.downpayment_amount AS down, a.cancellation_forfeited AS forf, a.appointment_date AS d,
                c.full_name AS guest,
                (SELECT COALESCE(transaction_reference, CONCAT('BVO-INV-', LPAD(id, 4, '0'))) FROM payments WHERE appointment_id = a.id ORDER BY id ASC LIMIT 1) AS txn,
                (SELECT MIN(payment_date) FROM payments WHERE appointment_id = a.id) AS pdate,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id = a.id AND (payment_status = 'deposit' OR transaction_type = 'deposit')) AS dep_rows,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id = a.id AND payment_status = 'refunded') AS refunded
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.downpayment_amount > 0
            OR EXISTS (SELECT 1 FROM payments p WHERE p.appointment_id = a.id AND (p.payment_status = 'deposit' OR p.transaction_type = 'deposit' OR p.payment_status = 'refunded'))
         ORDER BY a.appointment_date DESC, a.id DESC LIMIT 200"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $deposit = max((float)$r['down'], (float)$r['dep_rows']);
        $forf    = (int)$r['forf'] === 1;
        $refund  = (float)$r['refunded'];
        if ($forf) {
            $status = 'Forfeited';
        } elseif ($refund > 0) {
            $status = 'Refunded';
        } elseif ($r['st'] === 'completed') {
            $status = 'Applied to Bill';
        } elseif (in_array($r['st'], ['cancelled', 'no_show'], true)) {
            $status = 'Awaiting Review';
        } else {
            $status = 'Held in Escrow';
        }
        $out[] = [
            'txn'      => (string)($r['txn'] ?? '—'),
            'ref'      => appt_code((int)$r['id']),
            'guest'    => (string)($r['guest'] ?? 'Unknown guest'),
            'deposit'  => $deposit,
            'refund'   => $refund,
            'forfeit'  => $forf ? (float)$r['down'] : 0.0,
            'date'     => $r['pdate'] ? date('M j, Y', strtotime((string)$r['pdate'])) : appt_date_label((string)$r['d']),
            'status'   => $status,
        ];
    }
    return $out;
}

function bill_commissions(PDO $pdo): array
{
    $cfg = bill_config($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(commission_amount), 0) AS t FROM appointment_charges WHERE charge_type = 'addon' AND commission_amount > 0 AND created_at >= ?");
    $stmt->execute([date('Y-m-01 00:00:00')]);
    $r = $stmt->fetch();
    $stmt = $pdo->query("SELECT COUNT(*) FROM service_addons WHERE is_active = 1");
    return ['rate' => $cfg['commission'], 'count' => (int)$r['c'], 'total' => (float)$r['t'], 'addons' => (int)$stmt->fetchColumn()];
}