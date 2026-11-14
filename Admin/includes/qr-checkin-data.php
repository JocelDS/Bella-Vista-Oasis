<?php
declare(strict_types=1);

require_once __DIR__ . '/appointments-data.php';

const QR_PREFIX   = 'BVO' . 'QR:';
const QR_PER_PAGE = 6;
const QR_TOKEN_RE = '/^[A-Za-z0-9_-]{43}$/';

/* =====================================================================
 * Plumbing
 * ===================================================================== */

function qr_ready(PDO $pdo): bool
{
    try {
        $n = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('appointment_checkins', 'checkin_activity_logs', 'checkin_settings')"
        )->fetchColumn();
        return (int)$n === 3;
    } catch (Throwable $e) {
        return false;
    }
}

function qr_out(array $payload, int $code = 200): never
{
    // Keep a failure status the endpoint already set (422 / 409 ...) instead of overwriting it with 200.
    $already = http_response_code();
    http_response_code($code === 200 && is_int($already) && $already >= 400 ? $already : $code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Common endpoint preamble: auth, method, CSRF, readiness. Returns [PDO, adminId]. */
function qr_api(string $method): array
{
    api_guard();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        qr_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if ($method === 'POST' && !csrf_verify()) {
        qr_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
    $pdo = db();
    if (!qr_ready($pdo)) {
        qr_out(['ok' => false, 'error' => 'QR check-in tables are missing. Import database/qr-checkin-extensions.sql first.'], 503);
    }
    return [$pdo, (int)$_SESSION['admin_id']];
}

function qr_guard_errors(callable $fn): never
{
    try {
        qr_out($fn());
    } catch (Throwable $e) {
        error_log('[BVO qr-checkin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        qr_out(['ok' => false, 'error' => 'Something went wrong on the server. Please try again.'], 500);
    }
}

function qr_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function qr_dt(string $date, string $time): DateTimeImmutable
{
    return new DateTimeImmutable($date . ' ' . substr($time, 0, 8));
}

function qr_dur(int $min): string
{
    if ($min < 60) {
        return $min . ' min';
    }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h . ' hr' . ($m ? ' ' . $m . ' min' : '');
}

function qr_late_info(array $a, ?string $checkedInAt, DateTimeImmutable $now): ?array
{
    if ($checkedInAt === null && (string)$a['appointment_date'] !== $now->format('Y-m-d')) {
        return null;
    }
    $ref   = $checkedInAt !== null ? new DateTimeImmutable($checkedInAt) : $now;
    $start = qr_dt((string)$a['appointment_date'], (string)$a['start_time']);
    $late  = intdiv($ref->getTimestamp() - $start->getTimestamp(), 60);
    if ($late < 1) {
        return null;
    }
    $session = max(0, (int)$a['duration'] - $late);
    return [
        'minutes'         => $late,
        'session_minutes' => $session,
        'late_label'      => qr_dur($late),
        'session_label'   => qr_dur($session),
        'end_label'       => appt_time_label((string)$a['end_time']),
    ];
}

function qr_ts_label(?string $ts): string
{
    $t = $ts ? strtotime($ts) : false;
    return $t === false ? '' : date('M j, g:i A', $t);
}

function qr_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Pulls a token out of whatever the scanner / input box produced, or null. */
function qr_extract_token(string $raw): ?string
{
    $raw = trim($raw);
    if (strncasecmp($raw, QR_PREFIX, strlen(QR_PREFIX)) === 0) {
        $raw = substr($raw, strlen(QR_PREFIX));
    }
    return preg_match(QR_TOKEN_RE, $raw) === 1 ? $raw : null;
}

/** "BVO-0003" / "bvo0003" -> 3, otherwise null. */
function qr_parse_reference(string $raw): ?int
{
    if (preg_match('/^\s*BVO-?(\d{1,9})\s*$/i', $raw, $m)) {
        $id = (int)$m[1];
        return $id > 0 ? $id : null;
    }
    return null;
}

/* =====================================================================
 * Settings
 * ===================================================================== */

function qr_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT early_minutes, late_minutes, allow_pending, updated_at FROM checkin_settings WHERE id = 1')->fetch();
    return [
        'early'         => $r ? (int)$r['early_minutes'] : 60,
        'late'          => $r ? (int)$r['late_minutes'] : 30,
        'allow_pending' => $r ? ((int)$r['allow_pending'] === 1) : false,
        'updated_at'    => $r ? $r['updated_at'] : null,
    ];
}

function qr_save_settings(PDO $pdo, int $early, int $late, bool $allowPending, int $adminId): array
{
    if ($early < 0 || $early > 720 || $late < 0 || $late > 720) {
        return ['ok' => false, 'error' => 'Enter a window between 0 and 720 minutes.'];
    }
    $stamp = now();
    $pdo->prepare(
        'INSERT INTO checkin_settings (id, early_minutes, late_minutes, allow_pending, updated_by, updated_at)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE early_minutes = VALUES(early_minutes), late_minutes = VALUES(late_minutes),
                                 allow_pending = VALUES(allow_pending), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
    )->execute([$early, $late, $allowPending ? 1 : 0, $adminId, $stamp]);

    qr_log($pdo, null, 'window_updated',
        'Check-in window set to ' . $early . ' min early / ' . $late . ' min late; pending bookings '
        . ($allowPending ? 'allowed' : 'not allowed') . '.', $adminId);

    return ['ok' => true, 'settings' => qr_settings($pdo)];
}

/* =====================================================================
 * Activity log + notifications
 * ===================================================================== */

function qr_log(PDO $pdo, ?int $apptId, string $type, ?string $details, ?int $adminId): void
{
    $pdo->prepare(
        'INSERT INTO checkin_activity_logs (appointment_id, activity_type, activity_details, processed_by, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$apptId, $type, $details !== null ? mb_substr($details, 0, 500) : null, $adminId, client_ip(), now()]);
}

function qr_notify(PDO $pdo, string $title, string $message): void
{
    $pdo->prepare('INSERT INTO notifications (admin_id, title, message, created_at) VALUES (NULL, ?, ?, ?)')
        ->execute([mb_substr($title, 0, 200), $message, now()]);
}

function qr_activity_meta(): array
{
    // type => [label, tone]
    return [
        'qr_generated'          => ['QR code generated', 'info'],
        'guest_verified'        => ['QR scanned · guest verified', 'ok'],
        'arrival_confirmed'     => ['Arrival confirmed (QR)', 'ok'],
        'manual_checkin'        => ['Manual check-in processed', 'ok'],
        'invalid_attempt'       => ['Invalid QR attempt', 'bad'],
        'expired_attempt'       => ['Expired QR attempt', 'bad'],
        'blocked_attempt'       => ['Check-in blocked', 'bad'],
        'verification_cancelled' => ['Verification cancelled', 'muted'],
        'window_updated'        => ['Check-in window updated', 'muted'],
    ];
}

function qr_activity(PDO $pdo, int $limit = 8): array
{
    $limit = max(1, min(30, $limit));
    $rows = $pdo->query(
        'SELECT l.id, l.appointment_id, l.activity_type, l.activity_details, l.created_at,
                c.full_name AS guest, ad.full_name AS staff
         FROM checkin_activity_logs l
         LEFT JOIN appointments a ON a.id = l.appointment_id
         LEFT JOIN customers c    ON c.id = a.customer_id
         LEFT JOIN administrators ad ON ad.id = l.processed_by
         ORDER BY l.id DESC LIMIT ' . $limit
    )->fetchAll();

    $meta = qr_activity_meta();
    $out  = [];
    foreach ($rows as $r) {
        $type = (string)$r['activity_type'];
        [$label, $tone] = $meta[$type] ?? [ucfirst(str_replace('_', ' ', $type)), 'muted'];
        $out[] = [
            'id'      => (int)$r['id'],
            'type'    => $type,
            'label'   => $label,
            'tone'    => $tone,
            'code'    => $r['appointment_id'] ? appt_code((int)$r['appointment_id']) : '',
            'guest'   => (string)($r['guest'] ?? ''),
            'details' => (string)($r['activity_details'] ?? ''),
            'staff'   => (string)($r['staff'] ?? ''),
            'time'    => dash_time_ago((string)$r['created_at']),
            'stamp'   => qr_ts_label((string)$r['created_at']),
        ];
    }
    return $out;
}

/* =====================================================================
 * Appointment + check-in state
 * ===================================================================== */

function qr_appt(PDO $pdo, int $id, bool $lock = false): ?array
{
    if ($lock) {
        $pdo->prepare('SELECT id FROM appointments WHERE id = ? FOR UPDATE')->execute([$id]);
    }
    $stmt = $pdo->prepare(
        'SELECT a.id, a.customer_id, a.service_id, a.room_id, a.therapist_id,
                a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                s.service_name, s.duration,
                r.room_name, r.room_type,
                t.full_name AS therapist_name, t.specialization
         FROM appointments a
         LEFT JOIN customers c  ON c.id = a.customer_id
         LEFT JOIN services s   ON s.id = a.service_id
         LEFT JOIN rooms r      ON r.id = a.room_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.id = ?'
    );
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    return $a ?: null;
}

/** @return array<int, array<int, array>> check-in rows grouped by appointment id, oldest first */
function qr_rows(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT k.*, ad.full_name AS checked_in_by_name
         FROM appointment_checkins k
         LEFT JOIN administrators ad ON ad.id = k.checked_in_by
         WHERE k.appointment_id IN (' . $in . ') ORDER BY k.id ASC'
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['appointment_id']][] = $r;
    }
    return $out;
}

function qr_window(array $a, array $s, DateTimeImmutable $now): array
{
    $start      = qr_dt((string)$a['appointment_date'], (string)$a['start_time']);
    $untilStart = intdiv($start->getTimestamp() - $now->getTimestamp(), 60); // + = guest is early
    $state      = 'ok';
    $message    = '';
    if ($untilStart > $s['early']) {
        $state   = 'early';
        $message = 'Early arrival: the session starts in ' . qr_dur($untilStart)
            . ' and check-in normally opens ' . qr_dur($s['early']) . ' before the start time.';
    } elseif (-$untilStart > $s['late']) {
        $state   = 'late';
        $message = 'Late arrival: the session started ' . qr_dur(-$untilStart)
            . ' ago and the late check-in window is ' . qr_dur($s['late']) . '.';
    }
    return ['state' => $state, 'message' => $message, 'minutes_to_start' => $untilStart];
}

/**
 * Can this appointment be checked in right now?
 * Hard blocks: cancelled / no-show / completed / unconfirmed / not today.
 * Outside the minute window is only a warning that staff must acknowledge.
 */
function qr_eligibility(array $a, array $s, DateTimeImmutable $now): array
{
    $st     = (string)$a['appointment_status'];
    $window = qr_window($a, $s, $now);
    $block  = static fn(string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg, 'window' => $window];

    if ($st === 'cancelled') {
        return $block('cancelled', 'This appointment has been cancelled and cannot be checked in.');
    }
    if ($st === 'no_show') {
        return $block('not_eligible', 'This appointment is marked as a no-show.');
    }
    if ($st === 'completed') {
        return $block('not_eligible', 'This appointment is already completed.');
    }
    if ($st === 'pending' && !$s['allow_pending']) {
        return $block('not_eligible', 'This booking is still pending. Confirm it in Appointment Management before checking the guest in.');
    }
    if ($st !== 'pending' && $st !== 'confirmed') {
        return $block('not_eligible', 'This appointment status does not allow check-in.');
    }
    if ((string)$a['appointment_date'] !== $now->format('Y-m-d')) {
        return $block('not_eligible', 'This appointment is scheduled for ' . appt_date_label((string)$a['appointment_date']) . '. Check-in is only available on the appointment date.');
    }
    return ['ok' => true, 'code' => 'ok', 'message' => '', 'window' => $window];
}

/** Derived, display-ready check-in state for one appointment. */
function qr_state(array $a, array $rows, array $s, DateTimeImmutable $now): array
{
    $done     = null;
    $tokenRow = null;
    foreach ($rows as $r) {
        if ($r['checkin_status'] === 'checked_in') {
            $done = $r;
        }
        if ($r['checkin_token_hash'] !== null) {
            $tokenRow = $r;
        }
    }
    $st = (string)$a['appointment_status'];

    if ($done) {
        $verification = $done['checkin_method'] === 'manual' ? 'not_used' : 'verified';
    } elseif ($tokenRow) {
        $v = (string)$tokenRow['verification_status'];
        if ($v === 'invalid') {
            $verification = 'invalid';
        } elseif ($tokenRow['token_expires_at'] && strtotime((string)$tokenRow['token_expires_at']) < $now->getTimestamp()) {
            $verification = 'expired';
        } elseif (in_array($st, ['cancelled', 'no_show', 'completed'], true)) {
            $verification = 'invalid';
        } else {
            $verification = $v === 'verified' ? 'verified' : 'pending';
        }
    } else {
        $verification = 'pending';
    }

    if ($done) {
        $arrival = 'checked_in';
    } elseif ($st === 'cancelled') {
        $arrival = 'cancelled';
    } elseif ($st === 'no_show') {
        $arrival = 'no_show';
    } elseif ($st === 'completed') {
        $arrival = 'completed';
    } elseif ($verification === 'verified') {
        $arrival = 'arrived';
    } elseif ((string)$a['appointment_date'] < $now->format('Y-m-d')) {
        $arrival = 'no_record'; // a past booking nobody checked in: say what is known, not "expected"
    } else {
        $arrival = 'expected';
    }

    return [
        'verification'  => $verification,
        'arrival'       => $arrival,
        'method'        => $done ? (string)$done['checkin_method'] : null,
        'checked_in_at' => $done ? (string)$done['checked_in_at'] : null,
        'checked_in_by' => $done ? (string)($done['checked_in_by_name'] ?? '') : '',
        'verified_at'   => $tokenRow && $verification === 'verified' ? (string)$tokenRow['verified_at'] : ($done ? (string)($done['verified_at'] ?? '') : ''),
        'token_row'     => $tokenRow,
        'done_row'      => $done,
        'eligibility'   => qr_eligibility($a, $s, $now),
    ];
}

function qr_verification_label(string $key): string
{
    return [
        'verified' => 'Verified',
        'pending'  => 'Pending',
        'invalid'  => 'Invalid',
        'expired'  => 'Expired',
        'not_used' => 'Manual · QR not used',
    ][$key] ?? ucfirst($key);
}

function qr_arrival_label(string $key): string
{
    return [
        'expected'   => 'Expected',
        'arrived'    => 'Arrived',
        'checked_in' => 'Checked In',
        'cancelled'  => 'Cancelled',
        'no_show'    => 'No-show',
        'completed'  => 'Completed',
        'no_record'  => 'No check-in record',
    ][$key] ?? ucfirst($key);
}

function qr_method_label(?string $m): string
{
    return $m === 'qr' ? 'QR Scan' : ($m === 'manual' ? 'Manual Check-in' : '');
}

/** Everything the Live Verification Spotlight needs about one appointment. */
function qr_card(array $a, array $state): array
{
    $smap = dash_status_map();
    $pmap = dash_payment_map();
    $st   = (string)$a['appointment_status'];
    $ps   = (string)$a['payment_status'];
    $name = (string)($a['customer_name'] ?? '');
    $elig = $state['eligibility'];

    return [
        'appointment' => [
            'id'            => (int)$a['id'],
            'code'          => appt_code((int)$a['id']),
            'date'          => (string)$a['appointment_date'],
            'date_label'    => appt_date_label((string)$a['appointment_date']),
            'start_label'   => appt_time_label((string)$a['start_time']),
            'end_label'     => appt_time_label((string)$a['end_time']),
            'status'        => $st,
            'status_label'  => $smap[$st] ?? ucfirst($st),
            'payment'       => $ps,
            'payment_label' => $pmap[$ps] ?? ucfirst($ps),
            'service'       => (string)($a['service_name'] ?? '—'),
            'duration'      => (int)$a['duration'],
            'room'          => (string)($a['room_name'] ?? '—'),
            'room_type'     => (string)($a['room_type'] ?? ''),
            'therapist'     => $a['therapist_name'] !== null ? (string)$a['therapist_name'] : 'Unassigned',
            'specialization' => (string)($a['specialization'] ?? ''),
        ],
        'customer' => [
            'id'       => (int)$a['customer_id'],
            'name'     => $name !== '' ? $name : 'Unknown guest',
            'initials' => name_initials($name),
            'phone'    => (string)($a['customer_phone'] ?? ''),
            'email'    => (string)($a['customer_email'] ?? ''),
        ],
        'checkin' => [
            'verification'       => $state['verification'],
            'verification_label' => qr_verification_label($state['verification']),
            'arrival'            => $state['arrival'],
            'arrival_label'      => qr_arrival_label($state['arrival']),
            'method'             => $state['method'],
            'method_label'       => qr_method_label($state['method']),
            'checked_in_at'      => qr_ts_label($state['checked_in_at']),
            'checked_in_by'      => $state['checked_in_by'],
            'verified_at'        => qr_ts_label($state['verified_at'] ?: null),
        ],
        'late'     => qr_late_info($a, $state['checked_in_at'] ?: null, qr_now()),
        'window'   => $elig['window'],
        'eligible' => $elig['ok'] && $state['arrival'] !== 'checked_in',
        'block'    => ($elig['ok'] || $state['arrival'] === 'checked_in') ? null : ['code' => $elig['code'], 'message' => $elig['message']],
    ];
}

/* =====================================================================
 * Generate QR
 * ===================================================================== */

/** Appointments a QR can be issued for: upcoming, eligible status, not yet checked in. */
function qr_generatable(PDO $pdo, array $s, DateTimeImmutable $now): array
{
    $statuses = $s['allow_pending'] ? "'confirmed','pending'" : "'confirmed'";
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status,
                c.full_name AS customer_name, sv.service_name,
                (SELECT COUNT(*) FROM appointment_checkins k
                  WHERE k.appointment_id = a.id AND k.checkin_token_hash IS NOT NULL
                    AND k.verification_status IN ('pending','verified') AND k.token_expires_at > ?) AS active_tokens
         FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         LEFT JOIN services sv ON sv.id = a.service_id
         WHERE a.appointment_date >= ? AND a.appointment_status IN ($statuses)
           AND NOT EXISTS (SELECT 1 FROM appointment_checkins d WHERE d.appointment_id = a.id AND d.checkin_status = 'checked_in')
         ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 120"
    );
    $stmt->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        if (qr_dt((string)$r['appointment_date'], (string)$r['end_time']) <= $now) {
            continue; // session already over
        }
        $isToday = (string)$r['appointment_date'] === $now->format('Y-m-d');
        $out[] = [
            'id'         => (int)$r['id'],
            'code'       => appt_code((int)$r['id']),
            'guest'      => (string)($r['customer_name'] ?? 'Unknown guest'),
            'service'    => (string)($r['service_name'] ?? ''),
            'when'       => ($isToday ? 'Today' : appt_date_label((string)$r['appointment_date'])) . ' · ' . appt_time_label((string)$r['start_time']),
            'has_active' => (int)$r['active_tokens'] > 0,
        ];
    }
    return $out;
}

function qr_generate(PDO $pdo, int $apptId, ?int $adminId): array
{
    $s   = qr_settings($pdo);
    $now = qr_now();
    $pdo->beginTransaction();
    try {
        $a = qr_appt($pdo, $apptId, true);
        if (!$a) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Appointment not found.'];
        }
        $st = (string)$a['appointment_status'];
        $okStatus = $st === 'confirmed' || ($st === 'pending' && $s['allow_pending']);
        if (!$okStatus) {
            $pdo->rollBack();
            $msg = $st === 'pending'
                ? 'This booking is still pending. Confirm it in Appointment Management before issuing a QR code.'
                : 'A QR code cannot be issued for a ' . strtolower((dash_status_map()[$st] ?? $st)) . ' appointment.';
            return ['ok' => false, 'error' => $msg];
        }
        $end = qr_dt((string)$a['appointment_date'], (string)$a['end_time']);
        if ($end <= $now) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This appointment has already ended.'];
        }
        $chk = $pdo->prepare("SELECT 1 FROM appointment_checkins WHERE appointment_id = ? AND checkin_status = 'checked_in' LIMIT 1");
        $chk->execute([$apptId]);
        if ($chk->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This guest is already checked in.'];
        }

        $stamp = $now->format('Y-m-d H:i:s');
        // Retire any earlier unused token so only the newest QR works.
        $old = $pdo->prepare(
            "UPDATE appointment_checkins SET verification_status = 'invalid', verified_at = NULL, updated_at = ?
             WHERE appointment_id = ? AND checkin_status = 'pending' AND checkin_token_hash IS NOT NULL
               AND verification_status IN ('pending','verified')"
        );
        $old->execute([$stamp, $apptId]);
        $replaced = $old->rowCount() > 0;

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $end->format('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO appointment_checkins
                (appointment_id, checkin_token_hash, token_expires_at, verification_status, checkin_method, checkin_status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', 'qr', 'pending', ?, ?, ?)"
        )->execute([$apptId, qr_token_hash($token), $expires, $adminId, $stamp, $stamp]);

        qr_log($pdo, $apptId, 'qr_generated',
            'QR code issued, valid until ' . date('M j, g:i A', strtotime($expires)) . ($replaced ? '; replaced the previous code.' : '.'), $adminId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'ok'          => true,
        'payload'     => QR_PREFIX . $token,
        'expires_at'  => $expires,
        'expires_label' => date('M j, g:i A', strtotime($expires)),
        'replaced'    => $replaced,
        'pass'        => [
            'code'    => appt_code($apptId),
            'guest'   => (string)($a['customer_name'] ?? ''),
            'service' => (string)($a['service_name'] ?? ''),
            'date'    => appt_date_label((string)$a['appointment_date']),
            'time'    => appt_time_label((string)$a['start_time']) . ' – ' . appt_time_label((string)$a['end_time']),
        ],
    ];
}

/* =====================================================================
 * Verify (scan or reference) - never checks anyone in
 * ===================================================================== */

function qr_verdict(string $status): array
{
    // status => [label, tone]
    return [
        'verified'           => ['Verified', 'ok'],
        'manual'             => ['Awaiting Confirmation', 'warn'],
        'already_checked_in' => ['Already Checked In', 'muted'],
        'cancelled'          => ['Cancelled Appointment', 'bad'],
        'not_eligible'       => ['Not Eligible', 'bad'],
        'invalid'            => ['Invalid QR Code', 'bad'],
        'expired'            => ['Expired QR Code', 'bad'],
        'not_found'          => ['Appointment Not Found', 'bad'],
    ][$status] ?? [$status, 'muted'];
}

function qr_result(string $status, string $message, ?array $card = null, array $extra = []): array
{
    [$label, $tone] = qr_verdict($status);
    return array_merge([
        'status'     => $status,
        'label'      => $label,
        'tone'       => $tone,
        'message'    => $message,
        'mode'       => null,
        'can_confirm' => false,
        'can_manual' => false,
        'needs_ack'  => false,
        'card'       => $card,
    ], $extra);
}

function qr_fingerprint(string $raw): string
{
    return substr(hash('sha256', trim($raw)), 0, 8);
}

function qr_verify(PDO $pdo, string $raw, int $adminId): array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 300) {
        return qr_result('invalid', 'Enter or scan a QR code or booking reference.');
    }

    $s   = qr_settings($pdo);
    $now = qr_now();

    /* ---- Booking reference (manual lookup) ---- */
    $refId = qr_parse_reference($raw);
    if ($refId !== null && qr_extract_token($raw) === null) {
        $a = qr_appt($pdo, $refId);
        if (!$a) {
            qr_log($pdo, null, 'invalid_attempt', 'Booking reference not found (ref ' . qr_fingerprint($raw) . ').', $adminId);
            return qr_result('not_found', 'No appointment matches that booking reference.');
        }
        $rows  = qr_rows($pdo, [$refId]);
        $state = qr_state($a, $rows[$refId] ?? [], $s, $now);
        $card  = qr_card($a, $state);
        return qr_manual_result($card, $state);
    }

    /* ---- QR token ---- */
    $token = qr_extract_token($raw);
    if ($token === null) {
        qr_log($pdo, null, 'invalid_attempt', 'Unrecognised code (ref ' . qr_fingerprint($raw) . ').', $adminId);
        return qr_result('invalid', 'This is not a Bella Vista Oasis check-in QR code.');
    }

    $stmt = $pdo->prepare('SELECT * FROM appointment_checkins WHERE checkin_token_hash = ?');
    $stmt->execute([qr_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row) {
        qr_log($pdo, null, 'invalid_attempt', 'QR code not recognised (ref ' . qr_fingerprint($token) . ').', $adminId);
        return qr_result('invalid', 'This QR code is not recognised. Ask the guest for their booking reference or use manual check-in.');
    }

    $apptId = (int)$row['appointment_id'];
    $a      = qr_appt($pdo, $apptId);
    if (!$a) {
        qr_log($pdo, null, 'invalid_attempt', 'QR code points to a missing appointment (ref ' . qr_fingerprint($token) . ').', $adminId);
        return qr_result('not_found', 'The appointment for this QR code could not be found.');
    }
    $rows  = qr_rows($pdo, [$apptId]);
    $state = qr_state($a, $rows[$apptId] ?? [], $s, $now);
    $card  = qr_card($a, $state);
    $elig  = $state['eligibility'];
    $code  = appt_code($apptId);

    // Already checked in (by this or any other route).
    if ($state['arrival'] === 'checked_in') {
        qr_log($pdo, $apptId, 'blocked_attempt', $code . ': QR scanned but the guest is already checked in.', $adminId);
        return qr_result('already_checked_in', 'This guest was already checked in' . ($state['checked_in_at'] ? ' at ' . date('g:i A', strtotime($state['checked_in_at'])) : '') . '.', $card);
    }

    // Superseded / revoked token.
    if ((string)$row['verification_status'] === 'invalid') {
        qr_log($pdo, $apptId, 'invalid_attempt', $code . ': an outdated QR code was scanned.', $adminId);
        return qr_result('invalid', 'This QR code has been replaced by a newer one. Ask the guest for the latest pass or use manual check-in.', $card, ['can_manual' => $elig['ok']]);
    }

    // Expired token.
    if ($row['token_expires_at'] && strtotime((string)$row['token_expires_at']) < $now->getTimestamp()) {
        qr_log($pdo, $apptId, 'expired_attempt', $code . ': QR code expired ' . date('M j, g:i A', strtotime((string)$row['token_expires_at'])) . '.', $adminId);
        return qr_result('expired', 'This QR code expired on ' . date('M j, g:i A', strtotime((string)$row['token_expires_at'])) . '.', $card, ['can_manual' => $elig['ok']]);
    }

    // Appointment not eligible (cancelled, wrong day, unconfirmed, ...).
    if (!$elig['ok']) {
        $status = $elig['code'] === 'cancelled' ? 'cancelled' : 'not_eligible';
        qr_log($pdo, $apptId, 'blocked_attempt', $code . ': ' . $elig['message'], $adminId);
        return qr_result($status, $elig['message'], $card);
    }

    // Valid. Record the verification (this is NOT a check-in).
    $stamp = $now->format('Y-m-d H:i:s');
    $first = (string)$row['verification_status'] !== 'verified';
    if ($first) {
        $pdo->prepare("UPDATE appointment_checkins SET verification_status = 'verified', verified_at = ?, updated_at = ? WHERE id = ? AND checkin_status = 'pending'")
            ->execute([$stamp, $stamp, (int)$row['id']]);
    }
    qr_log($pdo, $apptId, 'guest_verified', $code . ': QR verified on the server; awaiting receptionist confirmation.', $adminId);

    $rows  = qr_rows($pdo, [$apptId]);
    $state = qr_state($a, $rows[$apptId] ?? [], $s, $now);
    $card  = qr_card($a, $state);
    $msg   = $elig['window']['state'] === 'ok'
        ? 'QR code verified. Review the details and confirm the guest\'s arrival.'
        : $elig['window']['message'];

    return qr_result('verified', $msg, $card, [
        'mode'        => 'qr',
        'can_confirm' => true,
        'needs_ack'   => $elig['window']['state'] !== 'ok',
    ]);
}

function qr_manual_result(array $card, array $state): array
{
    $elig = $state['eligibility'];
    if ($state['arrival'] === 'checked_in') {
        return qr_result('already_checked_in', 'This guest was already checked in' . ($card['checkin']['checked_in_at'] ? ' on ' . $card['checkin']['checked_in_at'] : '') . '.', $card);
    }
    if (!$elig['ok']) {
        return qr_result($elig['code'] === 'cancelled' ? 'cancelled' : 'not_eligible', $elig['message'], $card);
    }
    $msg = $elig['window']['state'] === 'ok'
        ? 'Appointment found. No QR was scanned, so check the guest\'s identity before confirming a manual check-in.'
        : $elig['window']['message'];
    return qr_result('manual', $msg, $card, [
        'mode'      => 'manual',
        'can_manual' => true,
        'needs_ack' => $elig['window']['state'] !== 'ok',
    ]);
}

/** Read-only look-up used by the arrival monitor's View action. */
function qr_detail(PDO $pdo, int $apptId, int $adminId): ?array
{
    $a = qr_appt($pdo, $apptId);
    if (!$a) {
        return null;
    }
    $s     = qr_settings($pdo);
    $now   = qr_now();
    $rows  = qr_rows($pdo, [$apptId]);
    $state = qr_state($a, $rows[$apptId] ?? [], $s, $now);
    return qr_manual_result(qr_card($a, $state), $state);
}

/* =====================================================================
 * Confirm / cancel (QR path) - server re-validates everything
 * ===================================================================== */

function qr_checkin_failure(string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'code' => $code, 'error' => $message], $extra);
}

function qr_is_duplicate(Throwable $e): bool
{
    return $e instanceof PDOException && ((string)$e->getCode() === '23000') && str_contains($e->getMessage(), '1062');
}

function qr_confirm(PDO $pdo, string $raw, int $adminId, bool $ackWindow): array
{
    $token = qr_extract_token($raw);
    if ($token === null) {
        qr_log($pdo, null, 'invalid_attempt', 'Confirm attempted with an unrecognised code (ref ' . qr_fingerprint($raw) . ').', $adminId);
        return qr_checkin_failure('invalid', 'This QR code is not valid.');
    }
    $s   = qr_settings($pdo);
    $now = qr_now();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM appointment_checkins WHERE checkin_token_hash = ? FOR UPDATE');
        $stmt->execute([qr_token_hash($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            qr_log($pdo, null, 'invalid_attempt', 'Confirm attempted with an unknown QR code (ref ' . qr_fingerprint($token) . ').', $adminId);
            return qr_checkin_failure('invalid', 'This QR code is not recognised.');
        }

        $apptId = (int)$row['appointment_id'];
        $a      = qr_appt($pdo, $apptId, true);
        if (!$a) {
            $pdo->rollBack();
            return qr_checkin_failure('not_found', 'The appointment for this QR code could not be found.');
        }
        $code = appt_code($apptId);

        // Re-read under the appointment lock so a concurrent check-in is seen.
        $stmt = $pdo->prepare('SELECT * FROM appointment_checkins WHERE id = ?');
        $stmt->execute([(int)$row['id']]);
        $row = $stmt->fetch();

        $dup = $pdo->prepare("SELECT checked_in_at FROM appointment_checkins WHERE appointment_id = ? AND checkin_status = 'checked_in' LIMIT 1");
        $dup->execute([$apptId]);
        if ($dup->fetchColumn() !== false) {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'blocked_attempt', $code . ': duplicate check-in prevented.', $adminId);
            return qr_checkin_failure('already_checked_in', 'This guest is already checked in.');
        }
        if ((string)$row['verification_status'] === 'invalid') {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'invalid_attempt', $code . ': an outdated QR code was submitted for check-in.', $adminId);
            return qr_checkin_failure('invalid', 'This QR code has been replaced by a newer one.');
        }
        if ($row['token_expires_at'] && strtotime((string)$row['token_expires_at']) < $now->getTimestamp()) {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'expired_attempt', $code . ': an expired QR code was submitted for check-in.', $adminId);
            return qr_checkin_failure('expired', 'This QR code has expired.');
        }
        $elig = qr_eligibility($a, $s, $now);
        if (!$elig['ok']) {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'blocked_attempt', $code . ': ' . $elig['message'], $adminId);
            return qr_checkin_failure($elig['code'], $elig['message']);
        }
        if ($elig['window']['state'] !== 'ok' && !$ackWindow) {
            $pdo->rollBack();
            return qr_checkin_failure('window', $elig['window']['message'], ['needs_ack' => true]);
        }

        $stamp = $now->format('Y-m-d H:i:s');
        $upd = $pdo->prepare(
            "UPDATE appointment_checkins
             SET verification_status = 'verified', verified_at = COALESCE(verified_at, ?), checkin_method = 'qr',
                 checkin_status = 'checked_in', checked_in_at = ?, checked_in_by = ?, checked_in_flag = 1, updated_at = ?
             WHERE id = ? AND checkin_status = 'pending'"
        );
        $upd->execute([$stamp, $stamp, $adminId, $stamp, (int)$row['id']]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return qr_checkin_failure('already_checked_in', 'This guest is already checked in.');
        }

        $note = $elig['window']['state'] === 'ok' ? '' : ' (' . strtolower($elig['window']['state']) . ' arrival acknowledged by staff)';
        $lateInfo = qr_late_info($a, $stamp, $now);
        if ($lateInfo) {
            $note .= ' — ' . $lateInfo['late_label'] . ' late; session shortened to ' . $lateInfo['session_label'] . ', still ending at ' . $lateInfo['end_label'];
        }
        qr_log($pdo, $apptId, 'arrival_confirmed', $code . ': guest checked in via QR scan' . $note . '.', $adminId);
        qr_notify($pdo, 'Guest checked in',
            ($a['customer_name'] ?? 'A guest') . ' checked in for ' . ($a['service_name'] ?? 'their treatment')
            . ' (' . ($a['room_name'] ?? 'suite') . ') via QR scan.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (qr_is_duplicate($e)) {
            return qr_checkin_failure('already_checked_in', 'This guest is already checked in.');
        }
        throw $e;
    }

    return qr_after_checkin($pdo, $apptId, $s);
}

function qr_after_checkin(PDO $pdo, int $apptId, array $s): array
{
    $now   = qr_now();
    $a     = qr_appt($pdo, $apptId);
    $rows  = qr_rows($pdo, [$apptId]);
    $state = qr_state($a, $rows[$apptId] ?? [], $s, $now);
    $late = qr_late_info($a, $state['checked_in_at'] ?: null, $now);
    return [
        'ok'      => true,
        'message' => ($a['customer_name'] ?? 'Guest') . ' is checked in.'
            . ($late ? ' Arrived ' . $late['late_label'] . ' late: session shortened to ' . $late['session_label'] . ', still ending at ' . $late['end_label'] . '.' : ''),
        'card'    => qr_card($a, $state),
    ];
}

/** Undo a scan-only verification (nothing was checked in). */
function qr_cancel_verification(PDO $pdo, string $raw, int $adminId): array
{
    $token = qr_extract_token($raw);
    if ($token === null) {
        return ['ok' => true];
    }
    $stamp = now();
    $stmt  = $pdo->prepare("SELECT id, appointment_id FROM appointment_checkins WHERE checkin_token_hash = ? AND checkin_status = 'pending' AND verification_status = 'verified'");
    $stmt->execute([qr_token_hash($token)]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare("UPDATE appointment_checkins SET verification_status = 'pending', verified_at = NULL, updated_at = ? WHERE id = ? AND checkin_status = 'pending'")
            ->execute([$stamp, (int)$row['id']]);
        qr_log($pdo, (int)$row['appointment_id'], 'verification_cancelled', appt_code((int)$row['appointment_id']) . ': receptionist cancelled the verification.', $adminId);
    }
    return ['ok' => true];
}

/* =====================================================================
 * Manual check-in
 * ===================================================================== */

function qr_like(string $v): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%';
}

function qr_manual_search(PDO $pdo, string $q): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2 && qr_parse_reference($q) === null) {
        return [];
    }
    $s   = qr_settings($pdo);
    $now = qr_now();

    $where  = [];
    $params = [];
    $refId  = qr_parse_reference($q);
    if ($refId !== null) {
        $where[] = 'a.id = ?';
        $params[] = $refId;
    } elseif (ctype_digit($q) && strlen($q) <= 6) {
        $where[] = 'a.id = ?';
        $params[] = (int)$q;
    }
    $where[]  = 'c.full_name LIKE ?';
    $params[] = qr_like($q);
    $digits   = preg_replace('/\D+/', '', $q) ?? '';
    if (strlen($digits) >= 3) {
        $where[]  = "REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', '') LIKE ?";
        $params[] = qr_like($digits);
    }

    $today = $now->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT a.id FROM appointments a
         LEFT JOIN customers c ON c.id = a.customer_id
         WHERE (' . implode(' OR ', $where) . ')
         ORDER BY (a.appointment_date = ?) DESC, ABS(DATEDIFF(a.appointment_date, ?)) ASC, a.start_time ASC
         LIMIT 15'
    );
    $params[] = $today;
    $params[] = $today;
    $stmt->execute($params);
    $ids = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());
    if (!$ids) {
        return [];
    }
    $rows = qr_rows($pdo, $ids);
    $out  = [];
    foreach ($ids as $id) {
        $a = qr_appt($pdo, $id);
        if (!$a) {
            continue;
        }
        $state = qr_state($a, $rows[$id] ?? [], $s, $now);
        $card  = qr_card($a, $state);
        $out[] = [
            'id'         => $id,
            'code'       => $card['appointment']['code'],
            'guest'      => $card['customer']['name'],
            'phone'      => $card['customer']['phone'],
            'service'    => $card['appointment']['service'],
            'date_label' => $card['appointment']['date_label'],
            'time'       => $card['appointment']['start_label'] . ' – ' . $card['appointment']['end_label'],
            'status_label' => $card['appointment']['status_label'],
            'status'     => $card['appointment']['status'],
            'arrival'    => $state['arrival'],
            'arrival_label' => qr_arrival_label($state['arrival']),
            'selectable' => $card['eligible'],
            'reason'     => $state['arrival'] === 'checked_in'
                ? 'Already checked in'
                : ($card['block']['message'] ?? ''),
        ];
    }
    return $out;
}

function qr_manual_confirm(PDO $pdo, int $apptId, int $adminId, bool $identityOk, bool $ackWindow): array
{
    if (!$identityOk) {
        return qr_checkin_failure('identity', 'Confirm that you have verified the guest\'s identity first.');
    }
    $s   = qr_settings($pdo);
    $now = qr_now();

    $pdo->beginTransaction();
    try {
        $a = qr_appt($pdo, $apptId, true);
        if (!$a) {
            $pdo->rollBack();
            return qr_checkin_failure('not_found', 'Appointment not found.');
        }
        $code = appt_code($apptId);

        $dup = $pdo->prepare("SELECT 1 FROM appointment_checkins WHERE appointment_id = ? AND checkin_status = 'checked_in' LIMIT 1");
        $dup->execute([$apptId]);
        if ($dup->fetchColumn()) {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'blocked_attempt', $code . ': duplicate manual check-in prevented.', $adminId);
            return qr_checkin_failure('already_checked_in', 'This guest is already checked in.');
        }
        $elig = qr_eligibility($a, $s, $now);
        if (!$elig['ok']) {
            $pdo->rollBack();
            qr_log($pdo, $apptId, 'blocked_attempt', $code . ': manual check-in refused. ' . $elig['message'], $adminId);
            return qr_checkin_failure($elig['code'], $elig['message']);
        }
        if ($elig['window']['state'] !== 'ok' && !$ackWindow) {
            $pdo->rollBack();
            return qr_checkin_failure('window', $elig['window']['message'], ['needs_ack' => true]);
        }

        $stamp = $now->format('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO appointment_checkins
                (appointment_id, checkin_token_hash, token_expires_at, verification_status, checkin_method, checkin_status,
                 checked_in_at, checked_in_by, checked_in_flag, created_by, created_at, updated_at)
             VALUES (?, NULL, NULL, 'not_used', 'manual', 'checked_in', ?, ?, 1, ?, ?, ?)"
        )->execute([$apptId, $stamp, $adminId, $adminId, $stamp, $stamp]);

        $note = $elig['window']['state'] === 'ok' ? '' : ' (' . strtolower($elig['window']['state']) . ' arrival acknowledged by staff)';
        $lateInfo = qr_late_info($a, $stamp, $now);
        if ($lateInfo) {
            $note .= ' — ' . $lateInfo['late_label'] . ' late; session shortened to ' . $lateInfo['session_label'] . ', still ending at ' . $lateInfo['end_label'];
        }
        qr_log($pdo, $apptId, 'manual_checkin', $code . ': guest checked in manually after identity check' . $note . '.', $adminId);
        qr_notify($pdo, 'Guest checked in (manual)',
            ($a['customer_name'] ?? 'A guest') . ' was checked in manually for ' . ($a['service_name'] ?? 'their treatment')
            . ' (' . ($a['room_name'] ?? 'suite') . ').');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (qr_is_duplicate($e)) {
            return qr_checkin_failure('already_checked_in', 'This guest is already checked in.');
        }
        throw $e;
    }

    return qr_after_checkin($pdo, $apptId, $s);
}

/* =====================================================================
 * Today's arrival monitor
 * ===================================================================== */

function qr_arrival_row(array $a, array $state, DateTimeImmutable $now): array
{
    $arrival = $state['arrival'];
    $sub     = '';
    if ($arrival === 'checked_in' && $state['checked_in_at']) {
        $sub = date('g:i A', strtotime($state['checked_in_at']));
    } elseif ($arrival === 'arrived' && $state['verified_at']) {
        $sub = 'Verified ' . date('g:i A', strtotime($state['verified_at']));
    } elseif ($arrival === 'expected' && (string)$a['appointment_date'] === $now->format('Y-m-d')) {
        $mins = intdiv(qr_dt((string)$a['appointment_date'], (string)$a['start_time'])->getTimestamp() - $now->getTimestamp(), 60);
        $sub  = $mins >= 0 ? 'Starts in ' . qr_dur($mins) : 'Started ' . qr_dur(-$mins) . ' ago';
        if ((string)$a['appointment_status'] === 'pending') {
            $sub .= ' · booking unconfirmed';
        }
    }
    $name = (string)($a['customer_name'] ?? '');
    return [
        'id'       => (int)$a['id'],
        'code'     => appt_code((int)$a['id']),
        'guest'    => $name !== '' ? $name : 'Unknown guest',
        'initials' => name_initials($name),
        'service'  => (string)($a['service_name'] ?? '—'),
        'room'     => (string)($a['room_name'] ?? ''),
        'therapist' => $a['therapist_name'] !== null ? (string)$a['therapist_name'] : 'Unassigned',
        'time'     => appt_time_label((string)$a['start_time']),
        'time_end' => appt_time_label((string)$a['end_time']),
        'verification' => ['key' => $state['verification'], 'label' => qr_verification_label($state['verification'])],
        'arrival'  => ['key' => $arrival, 'label' => qr_arrival_label($arrival), 'sub' => $sub],
        'method'   => ['key' => $state['method'], 'label' => qr_method_label($state['method'])],
    ];
}

function qr_arrivals(PDO $pdo, string $date, string $filter, string $q, int $page, int $per): array
{
    $s   = qr_settings($pdo);
    $now = qr_now();
    $per = max(1, min(50, $per));

    $stmt = $pdo->prepare(
        'SELECT a.id, a.customer_id, a.service_id, a.room_id, a.therapist_id,
                a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                c.full_name AS customer_name, c.phone AS customer_phone,
                s.service_name, r.room_name, t.full_name AS therapist_name
         FROM appointments a
         LEFT JOIN customers c  ON c.id = a.customer_id
         LEFT JOIN services s   ON s.id = a.service_id
         LEFT JOIN rooms r      ON r.id = a.room_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.appointment_date = ?
         ORDER BY a.start_time ASC, a.id ASC'
    );
    $stmt->execute([$date]);
    $appts = $stmt->fetchAll();
    $rowsBy = qr_rows($pdo, array_map(static fn($a) => (int)$a['id'], $appts));

    $needle = mb_strtolower(trim($q));
    $digits = preg_replace('/\D+/', '', $needle) ?? '';
    $list   = [];
    foreach ($appts as $a) {
        $id    = (int)$a['id'];
        $state = qr_state($a, $rowsBy[$id] ?? [], $s, $now);
        $row   = qr_arrival_row($a, $state, $now);
        if ($needle !== '') {
            $hay = mb_strtolower($row['code'] . ' ' . $id . ' ' . $row['guest'] . ' ' . $row['service'] . ' ' . $row['therapist']);
            $phone = preg_replace('/\D+/', '', (string)($a['customer_phone'] ?? '')) ?? '';
            if (!str_contains($hay, $needle) && !(strlen($digits) >= 3 && str_contains($phone, $digits))) {
                continue;
            }
        }
        $list[] = $row;
    }

    $match = [
        'all'                  => static fn($r) => true,
        'checked_in'           => static fn($r) => $r['arrival']['key'] === 'checked_in',
        'expected'             => static fn($r) => $r['arrival']['key'] === 'expected',
        'pending_verification' => static fn($r) => $r['arrival']['key'] === 'expected' && $r['verification']['key'] === 'pending',
        'manual'               => static fn($r) => $r['arrival']['key'] === 'checked_in' && $r['method']['key'] === 'manual',
    ];
    $counts = [];
    foreach ($match as $k => $fn) {
        $counts[$k] = count(array_filter($list, $fn));
    }
    if (!isset($match[$filter])) {
        $filter = 'all';
    }
    $filtered = array_values(array_filter($list, $match[$filter]));
    $total    = count($filtered);
    $pages    = max(1, (int)ceil($total / $per));
    $page     = max(1, min($page, $pages));
    $slice    = array_slice($filtered, ($page - 1) * $per, $per);

    $from = $total ? ($page - 1) * $per + 1 : 0;
    $to   = $total ? $from + count($slice) - 1 : 0;

    return [
        'date'       => $date,
        'date_label' => date('D, M j, Y', strtotime($date)),
        'is_today'   => $date === $now->format('Y-m-d'),
        'filter'     => $filter,
        'counts'     => $counts,
        'rows'       => $slice,
        'page'       => $page,
        'pages'      => $pages,
        'total'      => $total,
        'summary'    => $total ? 'Showing ' . $from . '–' . $to . ' of ' . $total . ' arrival' . ($total === 1 ? '' : 's') : 'No arrivals to show',
    ];
}

/* =====================================================================
 * Summary metrics (today)
 * ===================================================================== */

function qr_pct(int $part, int $whole): int
{
    return $whole > 0 ? (int)round(min(1, $part / $whole) * 100) : 0;
}

function qr_stats(PDO $pdo): array
{
    $now   = qr_now();
    $today = $now->format('Y-m-d');
    $from  = $today . ' 00:00:00';
    $to    = $today . ' 23:59:59';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM appointment_checkins WHERE checkin_status = 'checked_in' AND checkin_method = ? AND checked_in_at BETWEEN ? AND ?");
    $cnt->execute(['qr', $from, $to]);
    $qrIn = (int)$cnt->fetchColumn();
    $cnt->execute(['manual', $from, $to]);
    $manual = (int)$cnt->fetchColumn();
    $totalIn = $qrIn + $manual;

    $v = $pdo->prepare(
        "SELECT COUNT(DISTINCT k.appointment_id) FROM appointment_checkins k
         JOIN appointments a ON a.id = k.appointment_id
         WHERE a.appointment_date = ? AND (k.verification_status = 'verified' OR k.checkin_status = 'checked_in')"
    );
    $v->execute([$today]);
    $verified = (int)$v->fetchColumn();

    $e = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_status NOT IN ('cancelled','no_show')");
    $e->execute([$today]);
    $expected = (int)$e->fetchColumn();

    $l = $pdo->prepare(
        "SELECT SUM(activity_type = 'guest_verified') AS ok_n,
                SUM(activity_type IN ('invalid_attempt','expired_attempt','blocked_attempt')) AS bad_n
         FROM checkin_activity_logs WHERE created_at BETWEEN ? AND ?"
    );
    $l->execute([$from, $to]);
    $lr   = $l->fetch() ?: [];
    $okN  = (int)($lr['ok_n'] ?? 0);
    $badN = (int)($lr['bad_n'] ?? 0);
    $att  = $okN + $badN;

    return [
        'qr_checkins' => ['value' => $qrIn, 'note' => 'of ' . $expected . ' expected arrival' . ($expected === 1 ? '' : 's') . ' today', 'bar' => qr_pct($qrIn, $expected)],
        'verified'    => ['value' => $verified, 'note' => 'QR-verified or staff-confirmed · of ' . $expected . ' today', 'bar' => qr_pct($verified, $expected)],
        'success'     => [
            'value' => $att > 0 ? qr_pct($okN, $att) . '%' : '—',
            'note'  => $att > 0 ? $okN . ' of ' . $att . ' QR scan' . ($att === 1 ? '' : 's') . ' verified today' : 'No QR scans yet today',
            'bar'   => $att > 0 ? qr_pct($okN, $att) : 0,
        ],
        'manual'      => ['value' => $manual, 'note' => $totalIn > 0 ? 'of ' . $totalIn . ' check-in' . ($totalIn === 1 ? '' : 's') . ' today' : 'No check-ins yet today', 'bar' => qr_pct($manual, $totalIn)],
    ];
}