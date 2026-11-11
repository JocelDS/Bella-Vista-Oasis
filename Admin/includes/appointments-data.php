<?php
declare(strict_types=1);

require_once __DIR__ . '/dashboard-data.php';

const APPT_PER_PAGE_OPTIONS = [10, 25, 50];
const ROOM_GAP_MINUTES = 30;

function appt_minutes(string $t): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return 0;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

const APPT_FROM = " FROM appointments a
    LEFT JOIN customers c  ON c.id = a.customer_id
    LEFT JOIN services s   ON s.id = a.service_id
    LEFT JOIN therapists t ON t.id = a.therapist_id
    LEFT JOIN rooms r      ON r.id = a.room_id
    LEFT JOIN (
        SELECT appointment_id, SUM(amount) AS paid_amount
        FROM payments
        WHERE payment_status = 'paid'
        GROUP BY appointment_id
    ) p ON p.appointment_id = a.id";

function appt_code(int $id): string
{
    return 'BVO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function appt_time_label(?string $t): string
{
    $ts = $t ? strtotime($t) : false;
    return $ts === false ? '—' : date('g:i A', $ts);
}

function appt_date_label(?string $d): string
{
    $ts = $d ? strtotime($d) : false;
    return $ts === false ? '—' : date('M j, Y', $ts);
}

function appt_parse_time(string $v): ?string
{
    if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', trim($v), $m)) {
        return null;
    }
    if ((int)$m[1] > 23 || (int)$m[2] > 59) {
        return null;
    }
    return $m[1] . ':' . $m[2] . ':00';
}

function appt_parse_date(string $v): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', trim($v));
    return ($d && $d->format('Y-m-d') === trim($v)) ? $d->format('Y-m-d') : null;
}

function appt_parse_filters(array $src): array
{
    $status = (string)($src['status'] ?? 'all');
    if ($status !== 'all' && !isset(dash_status_map()[$status])) {
        $status = 'all';
    }
    $per = (int)($src['per_page'] ?? 10);
    if (!in_array($per, APPT_PER_PAGE_OPTIONS, true)) {
        $per = 10;
    }
    return [
        'status'    => $status,
        'q'         => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'date'      => appt_parse_date((string)($src['date'] ?? '')) ?? '',
        'therapist' => max(0, (int)($src['therapist'] ?? 0)),
        'service'   => max(0, (int)($src['service'] ?? 0)),
        'page'      => max(1, (int)($src['page'] ?? 1)),
        'per_page'  => $per,
    ];
}

function appt_where(array $f, bool $withStatus): array
{
    $w = ['1 = 1'];
    $p = [];

    if ($withStatus && $f['status'] !== 'all') {
        $w[] = 'a.appointment_status = ?';
        $p[] = $f['status'];
    }
    if ($f['q'] !== '') {
        $like  = '%' . addcslashes($f['q'], '\\%_') . '%';
        $parts = ['c.full_name LIKE ?', 's.service_name LIKE ?', 'c.email LIKE ?'];
        $p[]   = $like;
        $p[]   = $like;
        $p[]   = $like;
        if (preg_match('/^(?:bvo-?|#)?(\d{1,9})$/i', $f['q'], $m)) {
            $parts[] = 'a.id = ?';
            $p[]     = (int)$m[1];
        }
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($f['date'] !== '') {
        $w[] = 'a.appointment_date = ?';
        $p[] = $f['date'];
    }
    if ($f['therapist'] > 0) {
        $w[] = 'a.therapist_id = ?';
        $p[] = $f['therapist'];
    }
    if ($f['service'] > 0) {
        $w[] = 'a.service_id = ?';
        $p[] = $f['service'];
    }
    return [implode(' AND ', $w), $p];
}

function appt_counts(PDO $pdo, array $f): array
{
    [$where, $params] = appt_where($f, false);
    $stmt = $pdo->prepare('SELECT a.appointment_status AS st, COUNT(*) AS n' . APPT_FROM . ' WHERE ' . $where . ' GROUP BY a.appointment_status');
    $stmt->execute($params);

    $counts = ['all' => 0];
    foreach (array_keys(dash_status_map()) as $k) {
        $counts[$k] = 0;
    }
    foreach ($stmt->fetchAll() as $r) {
        $n = (int)$r['n'];
        $counts['all'] += $n;
        $k = (string)$r['st'];
        $counts[$k] = ($counts[$k] ?? 0) + $n;
    }
    return $counts;
}

function appt_fetch(PDO $pdo, array $f): array
{
    [$where, $params] = appt_where($f, true);

    $stmt = $pdo->prepare('SELECT COUNT(*)' . APPT_FROM . ' WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $per    = (int)$f['per_page'];
    $pages  = max(1, (int)ceil($total / $per));
    $page   = min((int)$f['page'], $pages);
    $offset = ($page - 1) * $per;

    $stmt = $pdo->prepare(
        'SELECT a.id, a.customer_id, a.service_id, a.room_id, a.therapist_id,
                a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                s.service_name, s.duration,
                t.full_name AS therapist_name, r.room_name,
                COALESCE(p.paid_amount, 0) AS paid_amount'
        . APPT_FROM . ' WHERE ' . $where
        . ' ORDER BY a.appointment_date DESC, a.start_time ASC, a.id DESC LIMIT ' . $per . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per];
}

function appt_stats(PDO $pdo, string $today): array
{
    $r = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(appointment_status = 'pending'), 0) AS pending,
                COALESCE(SUM(appointment_status = 'completed'), 0) AS completed
         FROM appointments"
    )->fetch();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date = ?');
    $stmt->execute([$today]);
    $todayCount = (int)$stmt->fetchColumn();

    $revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'paid'")->fetchColumn();

    $total     = (int)$r['total'];
    $completed = (int)$r['completed'];
    $rate      = $total > 0 ? (int)round($completed / $total * 100) : 0;

    return [
        'total'     => $total,
        'pending'   => (int)$r['pending'],
        'today'     => $todayCount,
        'revenue'   => peso($revenue),
        'rate'      => $rate . '%',
        'rate_note' => $completed . ' of ' . $total . ' completed',
    ];
}

function appt_transitions(): array
{
    return [
        'confirm' => ['from' => ['pending'],              'to' => 'confirmed'],
        'complete' => ['from' => ['confirmed'],           'to' => 'completed'],
        'no_show'  => ['from' => ['confirmed'],           'to' => 'no_show'],
        'cancel'   => ['from' => ['pending', 'confirmed'], 'to' => 'cancelled'],
    ];
}

function appt_actions_for(string $status): array
{
    $out = [];
    if (in_array($status, ['pending', 'confirmed'], true)) {
        $out[] = 'edit';
    }
    foreach (appt_transitions() as $key => $t) {
        if (in_array($status, $t['from'], true)) {
            $out[] = $key;
        }
    }
    return $out;
}

function appt_render_rows(array $rows, bool $filtered): string
{
    if (!$rows) {
        $msg = $filtered ? 'No appointments match your filters.' : 'No appointments have been booked yet.';
        return '<tr class="empty-row"><td colspan="9">' . e($msg) . '</td></tr>';
    }

    $smap = dash_status_map();
    $pmap = dash_payment_map();

    ob_start();
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $st = (string)$r['appointment_status'];
        $ps = (string)$r['payment_status'];

        $stLabel = $smap[$st] ?? ucwords(str_replace('_', ' ', $st));
        $stClass = isset($smap[$st]) ? 'st-' . $st : 'st-other';
        $psLabel = $pmap[$ps] ?? ucwords(str_replace('_', ' ', $ps));
        $psClass = isset($pmap[$ps]) ? 'pay-' . $ps : 'pay-other';

        $contact = (string)($r['customer_email'] ?: $r['customer_phone']);
        $actions = implode(',', appt_actions_for($st));
        $paid    = (float)$r['paid_amount'];
        $payNote = $paid > 0 ? 'Received ' . peso($paid) : 'Total ' . peso($r['total_amount']);
        $custName = (string)($r['customer_name'] ?? 'Unknown guest');
        $therapist = (string)($r['therapist_name'] ?? '—');
        ?>
        <tr data-id="<?= $id ?>">
            <td data-label="ID"><span class="appt-id">#<?= e(appt_code($id)) ?></span></td>
            <td data-label="Customer">
                <span class="cell-main strong"><?= e($custName) ?></span>
                <?php if ($contact !== ''): ?><span class="cell-sub"><?= e($contact) ?></span><?php endif; ?>
            </td>
            <td data-label="Service">
                <span class="cell-main"><?= e((string)($r['service_name'] ?? '—')) ?></span>
                <span class="cell-sub"><?= (int)$r['duration'] ?> min · <?= e(peso($r['total_amount'])) ?></span>
            </td>
            <td data-label="Schedule">
                <span class="cell-main"><?= e(appt_date_label((string)$r['appointment_date'])) ?></span>
                <span class="cell-sub"><?= e(appt_time_label((string)$r['start_time'])) ?> – <?= e(appt_time_label((string)$r['end_time'])) ?></span>
            </td>
            <td data-label="Therapist">
                <div class="guest-cell">
                    <span class="avatar avatar-sm"><?= e(name_initials($therapist)) ?></span>
                    <span class="cell-main"><?= e($therapist) ?></span>
                </div>
            </td>
            <td data-label="Room"><?= e((string)($r['room_name'] ?? '—')) ?></td>
            <td data-label="Payment">
                <span class="badge <?= e($psClass) ?>"><?= e($psLabel) ?></span>
                <span class="cell-sub"><?= e($payNote) ?></span>
            </td>
            <td data-label="Status"><span class="badge <?= e($stClass) ?>"><?= e($stLabel) ?></span></td>
            <td data-label="Actions" class="col-actions">
                <button type="button" class="row-menu" data-id="<?= $id ?>" data-status="<?= e($st) ?>" data-actions="<?= e($actions) ?>" aria-haspopup="menu" aria-label="Actions for <?= e(appt_code($id)) ?>">
                    <?= icon('dots') ?>
                </button>
            </td>
        </tr>
        <?php
    }
    return (string)ob_get_clean();
}

function appt_summary_text(int $total, int $page, int $per, int $shown): string
{
    if ($total === 0 || $shown === 0) {
        return 'No appointments to show';
    }
    $from = ($page - 1) * $per + 1;
    return 'Showing ' . $from . '–' . ($from + $shown - 1) . ' of ' . $total . ' appointment' . ($total === 1 ? '' : 's');
}

function appt_payload(PDO $pdo, array $f): array
{
    $res = appt_fetch($pdo, $f);
    $filtered = $f['status'] !== 'all' || $f['q'] !== '' || $f['date'] !== '' || $f['therapist'] > 0 || $f['service'] > 0;
    return [
        'html'     => appt_render_rows($res['rows'], $filtered),
        'total'    => $res['total'],
        'page'     => $res['page'],
        'pages'    => $res['pages'],
        'per_page' => $res['per_page'],
        'summary'  => appt_summary_text($res['total'], $res['page'], $res['per_page'], count($res['rows'])),
        'counts'   => appt_counts($pdo, $f),
        'stats'    => appt_stats($pdo, date('Y-m-d')),
    ];
}

function appt_detail(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.customer_id, a.service_id, a.room_id, a.therapist_id,
                a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                s.service_name, s.duration,
                t.full_name AS therapist_name, r.room_name,
                COALESCE(p.paid_amount, 0) AS paid_amount'
        . APPT_FROM . ' WHERE a.id = ?'
    );
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if (!$a) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT amount, payment_method, payment_status, payment_date FROM payments WHERE appointment_id = ? ORDER BY id ASC');
    $stmt->execute([$id]);
    $payments = [];
    foreach ($stmt->fetchAll() as $p) {
        $payments[] = [
            'amount' => peso($p['amount']),
            'method' => (string)($p['payment_method'] ?? '—'),
            'status' => ucfirst((string)$p['payment_status']),
            'date'   => $p['payment_date'] ? date('M j, Y g:i A', strtotime((string)$p['payment_date'])) : '—',
        ];
    }

    $smap = dash_status_map();
    $pmap = dash_payment_map();
    $st   = (string)$a['appointment_status'];
    $ps   = (string)$a['payment_status'];

    return [
        'id'              => (int)$a['id'],
        'code'            => appt_code((int)$a['id']),
        'customer_id'     => (int)$a['customer_id'],
        'customer_name'   => (string)($a['customer_name'] ?? '—'),
        'customer_email'  => (string)($a['customer_email'] ?? ''),
        'customer_phone'  => (string)($a['customer_phone'] ?? ''),
        'service_id'      => (int)$a['service_id'],
        'service_name'    => (string)($a['service_name'] ?? '—'),
        'duration'        => (int)$a['duration'],
        'therapist_id'    => (int)$a['therapist_id'],
        'therapist_name'  => (string)($a['therapist_name'] ?? '—'),
        'room_id'         => (int)$a['room_id'],
        'room_name'       => (string)($a['room_name'] ?? '—'),
        'date'            => (string)$a['appointment_date'],
        'date_label'      => appt_date_label((string)$a['appointment_date']),
        'start'           => substr((string)$a['start_time'], 0, 5),
        'end'             => substr((string)$a['end_time'], 0, 5),
        'start_label'     => appt_time_label((string)$a['start_time']),
        'end_label'       => appt_time_label((string)$a['end_time']),
        'status'          => $st,
        'status_label'    => $smap[$st] ?? ucfirst($st),
        'payment_status'  => $ps,
        'payment_label'   => $pmap[$ps] ?? ucfirst($ps),
        'total'           => number_format((float)$a['total_amount'], 2, '.', ''),
        'total_label'     => peso($a['total_amount']),
        'paid_label'      => peso($a['paid_amount']),
        'payments'        => $payments,
        'actions'         => appt_actions_for($st),
    ];
}

function appt_save(PDO $pdo, array $in, ?int $id, int $roomGap = ROOM_GAP_MINUTES): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m];

    $customerId  = (int)($in['customer_id'] ?? 0);
    $serviceId   = (int)($in['service_id'] ?? 0);
    $therapistId = (int)($in['therapist_id'] ?? 0);
    $roomId      = (int)($in['room_id'] ?? 0);
    $payStatus   = (string)($in['payment_status'] ?? 'pending');
    $apptStatus  = (string)($in['appointment_status'] ?? 'pending');

    if ($customerId < 1 || $serviceId < 1 || $therapistId < 1 || $roomId < 1) {
        return $fail('Select a customer, service, therapist and room.');
    }
    $date = appt_parse_date((string)($in['appointment_date'] ?? ''));
    if ($date === null) {
        return $fail('Enter a valid appointment date.');
    }
    $start = appt_parse_time((string)($in['start_time'] ?? ''));
    if ($start === null) {
        return $fail('Enter a valid start time.');
    }
    if (!in_array($apptStatus, ['pending', 'confirmed'], true)) {
        return $fail('Appointment status must be Pending or Confirmed.');
    }
    if (!in_array($payStatus, ['pending', 'deposit', 'paid'], true)) {
        return $fail('Payment status must be Pending, Deposit or Paid.');
    }

    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT appointment_date, start_time, appointment_status FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            return $fail('That appointment no longer exists.');
        }
        if (!in_array($existing['appointment_status'], ['pending', 'confirmed'], true)) {
            return $fail('Only pending or confirmed appointments can be edited.');
        }
    }

    $changed = $existing === null
        || (string)$existing['appointment_date'] !== $date
        || substr((string)$existing['start_time'], 0, 8) !== $start;
    if ($changed) {
        if ($date < date('Y-m-d')) {
            return $fail('The appointment date cannot be in the past.');
        }
        if ($date === date('Y-m-d') && strtotime($date . ' ' . $start) <= time()) {
            return $fail('The start time must be later than the current time.');
        }
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        if (!$stmt->fetch()) {
            return $fail('The selected customer no longer exists.');
        }

        $stmt = $pdo->prepare('SELECT duration, price FROM services WHERE id = ?');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();
        if (!$service) {
            return $fail('The selected service no longer exists.');
        }

        $stmt = $pdo->prepare('SELECT availability_status FROM therapists WHERE id = ?');
        $stmt->execute([$therapistId]);
        $therapist = $stmt->fetch();
        if (!$therapist) {
            return $fail('The selected therapist no longer exists.');
        }
        if ($therapist['availability_status'] === 'on_leave') {
            return $fail('The selected therapist is on leave.');
        }

        $stmt = $pdo->prepare('SELECT room_status FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room) {
            return $fail('The selected room no longer exists.');
        }
        if ($room['room_status'] === 'maintenance') {
            return $fail('The selected room is under maintenance.');
        }
    } catch (PDOException $e) {
        return $fail('Unable to validate the appointment right now.');
    }

    $endIn = trim((string)($in['end_time'] ?? ''));
    if ($endIn !== '') {
        $end = appt_parse_time($endIn);
        if ($end === null) {
            return $fail('Enter a valid end time.');
        }
    } else {
        $end = (new DateTime($date . ' ' . $start))->modify('+' . (int)$service['duration'] . ' minutes')->format('H:i:s');
        if ($end <= $start) {
            return $fail('The session must finish before midnight.');
        }
    }
    if ($end <= $start) {
        return $fail('The end time must be later than the start time.');
    }

    $amountIn = trim((string)($in['total_amount'] ?? ''));
    if ($amountIn !== '') {
        if (!is_numeric($amountIn) || (float)$amountIn < 0 || (float)$amountIn > 9999999.99) {
            return $fail('Enter a valid total amount.');
        }
        $amount = round((float)$amountIn, 2);
    } else {
        $amount = (float)$service['price'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT room_id, therapist_id, start_time, end_time FROM appointments
             WHERE appointment_date = ?
               AND id <> ?
               AND appointment_status NOT IN ('cancelled', 'no_show')
               AND (room_id = ? OR therapist_id = ?)
             FOR UPDATE"
        );
        $stmt->execute([$date, $id ?? 0, $roomId, $therapistId]);
        $newS = appt_minutes($start);
        $newE = appt_minutes($end);
        foreach ($stmt->fetchAll() as $c) {
            $s = appt_minutes((string)$c['start_time']);
            $e = appt_minutes((string)$c['end_time']);
            if ((int)$c['room_id'] === $roomId && $newS < $e + $roomGap && $s < $newE + $roomGap) {
                $pdo->rollBack();
                return $fail('The selected room is booked or still in its ' . $roomGap . '-minute cleaning gap at that time.');
            }
            if ((int)$c['therapist_id'] === $therapistId && $newS < $e && $s < $newE) {
                $pdo->rollBack();
                return $fail('The selected therapist is already booked during that time.');
            }
        }

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO appointments
                 (customer_id, service_id, room_id, therapist_id, appointment_date, start_time, end_time, appointment_status, payment_status, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$customerId, $serviceId, $roomId, $therapistId, $date, $start, $end, $apptStatus, $payStatus, $amount]);
            $savedId = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE appointments
                 SET customer_id = ?, service_id = ?, room_id = ?, therapist_id = ?, appointment_date = ?, start_time = ?, end_time = ?,
                     appointment_status = ?, payment_status = ?, total_amount = ?
                 WHERE id = ?'
            );
            $stmt->execute([$customerId, $serviceId, $roomId, $therapistId, $date, $start, $end, $apptStatus, $payStatus, $amount, $id]);
            $savedId = $id;
        }

        if ($payStatus === 'paid') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE appointment_id = ? AND payment_status = 'paid'");
            $stmt->execute([$savedId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO payments (appointment_id, customer_id, amount, payment_method, payment_status, payment_date) VALUES (?, ?, ?, NULL, 'paid', ?)")
                    ->execute([$savedId, $customerId, $amount, now()]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $fail('Unable to save the appointment right now.');
    }

    return [
        'ok'      => true,
        'id'      => $savedId,
        'message' => $id === null ? 'Appointment ' . appt_code($savedId) . ' created.' : 'Appointment ' . appt_code($savedId) . ' updated.',
    ];
}

function appt_transition(PDO $pdo, int $id, string $action): array
{
    $map = appt_transitions();
    if (!isset($map[$action])) {
        return ['ok' => false, 'error' => 'Unknown action.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT appointment_status, appointment_date FROM appointments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That appointment no longer exists.'];
        }
        if (!in_array($row['appointment_status'], $map[$action]['from'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This action is not allowed for the current status. Refresh the list and try again.'];
        }
        if (in_array($action, ['complete', 'no_show'], true) && (string)$row['appointment_date'] > date('Y-m-d')) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only appointments scheduled for today or earlier can be updated this way.'];
        }

        $pdo->prepare('UPDATE appointments SET appointment_status = ? WHERE id = ?')->execute([$map[$action]['to'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Unable to update the appointment right now.'];
    }

    $labels = ['confirm' => 'confirmed', 'complete' => 'marked as completed', 'no_show' => 'marked as no show', 'cancel' => 'cancelled'];
    return ['ok' => true, 'message' => 'Appointment ' . appt_code($id) . ' ' . $labels[$action] . '.'];
}