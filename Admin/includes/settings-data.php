<?php
declare(strict_types=1);

/* Sanctuary Operations & System Configuration — data layer (uses the existing PDO connection). */

const SC_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const SC_BOOL_KEYS = ['cleaning_gap_enabled', 'hvac_automation', 'housekeeping_standard', 'cleaning_verification', 'equipment_readiness', 'block_maintenance_rooms'];

function sc_ready(PDO $pdo): bool
{
    try {
        foreach (['spa_operating_hours', 'spa_configuration', 'room_pricing', 'configuration_logs'] as $t) {
            if (!$pdo->query("SHOW TABLES LIKE '$t'")->fetch()) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sc_time(?string $t): string
{
    return $t ? date('g:i A', strtotime($t)) : '—';
}

function sc_money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

/** Every account in `administrators` may configure the sanctuary, except explicitly limited roles. */
function sc_allowed_admin(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    $role = strtolower((string)($_SESSION['admin_role'] ?? ''));
    foreach (['viewer', 'read', 'staff', 'reception', 'therapist', 'guest'] as $limited) {
        if ($role !== '' && str_contains($role, $limited)) {
            return false;
        }
    }
    return true;
}

/* ---------- Reads ---------- */

function sc_config(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM spa_configuration') as $r) {
        $out[$r['setting_key']] = (string)$r['setting_value'];
    }
    return $out;
}

function sc_hours(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT day_of_week, opening_time, closing_time, is_open FROM spa_operating_hours') as $r) {
        $rows[(int)$r['day_of_week']] = $r;
    }
    $out = [];
    foreach (SC_DAYS as $n => $name) {
        $r = $rows[$n] ?? null;
        $out[] = [
            'day'    => $n,
            'name'   => $name,
            'open'   => $r ? substr((string)$r['opening_time'], 0, 5) : '09:00',
            'close'  => $r ? substr((string)$r['closing_time'], 0, 5) : '21:00',
            'is_open' => $r ? (bool)$r['is_open'] : true,
        ];
    }
    return $out;
}

function sc_rooms(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT r.id, r.room_name, COALESCE(NULLIF(r.room_type, ''), 'Unclassified') AS room_type, r.room_status, p.rate, p.rate_type
           FROM rooms r LEFT JOIN room_pricing p ON p.room_id = r.id ORDER BY room_type, r.room_name"
    )->fetchAll();
    $cats = [];
    foreach ($rows as $r) {
        $k = (string)$r['room_type'];
        $cats[$k] ??= ['type' => $k, 'rooms' => [], 'rates' => []];
        $cats[$k]['rooms'][] = ['id' => (int)$r['id'], 'name' => (string)$r['room_name'], 'status' => (string)$r['room_status'], 'rate' => $r['rate'] !== null ? (float)$r['rate'] : null];
        if ($r['rate'] !== null) {
            $cats[$k]['rates'][] = (float)$r['rate'];
        }
    }
    foreach ($cats as &$c) {
        $set = count($c['rates']);
        $c['count'] = count($c['rooms']);
        $c['priced'] = $set;
        if ($set === 0) {
            $c['label'] = 'No rate set';
            $c['current'] = null;
        } else {
            $min = min($c['rates']);
            $max = max($c['rates']);
            $c['current'] = $min;
            $c['label'] = $min === $max ? sc_money($min) : sc_money($min) . ' – ' . sc_money($max);
            if ($set < $c['count']) {
                $c['label'] .= ' · ' . ($c['count'] - $set) . ' unset';
            }
        }
        unset($c['rates']);
    }
    unset($c);
    return array_values($cats);
}

function sc_therapists(PDO $pdo): array
{
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT t.id, t.full_name, t.specialization, t.availability_status,
                (SELECT CONCAT(r.room_name, ' · ', s.service_name)
                   FROM appointments a JOIN rooms r ON r.id = a.room_id JOIN services s ON s.id = a.service_id
                  WHERE a.therapist_id = t.id AND a.appointment_status IN ('pending','confirmed')
                    AND TIMESTAMP(a.appointment_date, a.end_time) >= ?
                  ORDER BY a.appointment_date, a.start_time LIMIT 1) AS next_assignment
           FROM therapists t ORDER BY t.full_name"
    );
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

function sc_status(PDO $pdo): array
{
    $hours = sc_hours($pdo);
    $today = $hours[(int)date('N') - 1];
    $now   = date('H:i');
    $open  = $today['is_open'] && $now >= $today['open'] && $now < $today['close'];
    if ($open) {
        $text = 'Open · closes ' . sc_time($today['close']);
    } elseif ($today['is_open'] && $now < $today['open']) {
        $text = 'Closed · opens ' . sc_time($today['open']);
    } else {
        $text = 'Closed today';
    }
    $last = $pdo->query(
        'SELECT l.changed_at, a.full_name FROM configuration_logs l LEFT JOIN administrators a ON a.id = l.admin_id ORDER BY l.changed_at DESC, l.id DESC LIMIT 1'
    )->fetch();
    return [
        'open'      => $open,
        'text'      => $text,
        'last'      => $last ? date('M j, Y · g:i A', strtotime((string)$last['changed_at'])) : null,
        'last_by'   => $last ? (string)($last['full_name'] ?? 'Administrator') : null,
    ];
}

function sc_review(PDO $pdo): array
{
    $cfg    = sc_config($pdo);
    $hours  = sc_hours($pdo);
    $cats   = sc_rooms($pdo);
    $thers  = sc_therapists($pdo);
    $gap    = (int)($cfg['cleaning_gap_minutes'] ?? 0);
    $closed = array_values(array_map(static fn($h) => $h['name'], array_filter($hours, static fn($h) => !$h['is_open'])));
    $unpriced = 0;
    foreach ($cats as $c) { $unpriced += $c['count'] - $c['priced']; }
    $onLeave = count(array_filter($thers, static fn($t) => $t['availability_status'] === 'on_leave'));
    $maint = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE room_status = 'maintenance'")->fetchColumn();

    $items = [
        ['Operating days', (7 - count($closed)) . ' of 7 open' . ($closed ? ' (closed ' . implode(', ', $closed) . ')' : '')],
        ['Standard hours', sc_time($hours[0]['open']) . ' – ' . sc_time($hours[0]['close']) . ' (Monday)'],
        ['Cleaning gap', ($cfg['cleaning_gap_enabled'] ?? '1') === '1' ? $gap . ' minutes between sessions' : 'Disabled'],
        ['Cancellation notice', (int)($cfg['cancellation_notice_hours'] ?? 0) . ' hours prior'],
        ['Automated HVAC circulation', ($cfg['hvac_automation'] ?? '0') === '1' ? 'On' : 'Off'],
        ['Housekeeping standard before reuse', ($cfg['housekeeping_standard'] ?? '0') === '1' ? 'Required' : 'Not required'],
        ['Cleaning verification', ($cfg['cleaning_verification'] ?? '0') === '1' ? 'Required' : 'Not required'],
        ['Equipment readiness check', ($cfg['equipment_readiness'] ?? '0') === '1' ? 'Required' : 'Not required'],
        ['Maintenance rooms blocked from booking', ($cfg['block_maintenance_rooms'] ?? '0') === '1' ? 'Yes' : 'No'],
        ['Therapists on leave', $onLeave . ' of ' . count($thers)],
    ];
    $warn = [];
    if ($unpriced > 0) { $warn[] = $unpriced . ' room' . ($unpriced === 1 ? ' has' : 's have') . ' no rate set yet.'; }
    if (count($closed) === 7) { $warn[] = 'Every day is marked closed.'; }
    if (($cfg['cleaning_gap_enabled'] ?? '1') !== '1') { $warn[] = 'The mandatory cleaning gap is off, so back-to-back room bookings are allowed.'; }
    if ($maint > 0) { $warn[] = $maint . ' room' . ($maint === 1 ? ' is' : 's are') . ' currently under maintenance.'; }
    if ($onLeave > 0) { $warn[] = $onLeave . ' therapist' . ($onLeave === 1 ? ' is' : 's are') . ' on leave and cannot be assigned.'; }
    return ['items' => $items, 'warnings' => $warn];
}

/* ---------- Booking integration (used by appt_save) ---------- */

function spa_cleaning_gap(PDO $pdo): int
{
    $fallback = defined('ROOM_GAP_MINUTES') ? ROOM_GAP_MINUTES : 30;
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM spa_configuration WHERE setting_key IN ('cleaning_gap_enabled', 'cleaning_gap_minutes')");
        $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return $fallback;
    }
    if (!$cfg) {
        return $fallback;
    }
    if (($cfg['cleaning_gap_enabled'] ?? '1') !== '1') {
        return 0;
    }
    return max(0, min(120, (int)($cfg['cleaning_gap_minutes'] ?? $fallback)));
}

/** Returns an error message when the slot is outside configured operating hours, otherwise null. */
function spa_check_hours(PDO $pdo, string $date, string $start, string $end): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT opening_time, closing_time, is_open FROM spa_operating_hours WHERE day_of_week = ?');
        $stmt->execute([(int)date('N', strtotime($date))]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $day = SC_DAYS[(int)date('N', strtotime($date))];
    if (!(int)$row['is_open']) {
        return 'The sanctuary is closed on ' . $day . 's.';
    }
    if ($start < $row['opening_time'] || $end > $row['closing_time']) {
        return 'That session falls outside ' . $day . ' operating hours (' . sc_time($row['opening_time']) . ' – ' . sc_time($row['closing_time']) . ').';
    }
    return null;
}

/* ---------- Validation + saving ---------- */

function sc_validate(PDO $pdo, array $in): array
{
    $err = [];
    $clean = ['hours' => [], 'config' => [], 'therapists' => []];

    foreach ((array)($in['hours'] ?? []) as $day => $h) {
        $day = (int)$day;
        if (!isset(SC_DAYS[$day]) || !is_array($h)) { continue; }
        $open = (string)($h['open'] ?? ''); $close = (string)($h['close'] ?? '');
        $isOpen = !empty($h['is_open']) && $h['is_open'] !== '0' && $h['is_open'] !== 'false';
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $open) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $close)) {
            $err["hours.$day"] = SC_DAYS[$day] . ': enter valid opening and closing times.';
            continue;
        }
        if ($isOpen && $close <= $open) {
            $err["hours.$day"] = SC_DAYS[$day] . ': closing time must be later than opening time.';
            continue;
        }
        $clean['hours'][$day] = ['open' => $open, 'close' => $close, 'is_open' => $isOpen ? 1 : 0];
    }

    $cfg = (array)($in['config'] ?? []);
    foreach ($cfg as $k => $v) {
        $k = (string)$k; $v = trim((string)$v);
        if (in_array($k, SC_BOOL_KEYS, true)) {
            $clean['config'][$k] = ($v === '1' || $v === 'true') ? '1' : '0';
        } elseif ($k === 'cleaning_gap_minutes') {
            if (!preg_match('/^\d{1,3}$/', $v) || (int)$v > 120) { $err[$k] = 'Cleaning gap must be between 0 and 120 minutes.'; continue; }
            $clean['config'][$k] = (string)(int)$v;
        } elseif ($k === 'cancellation_notice_hours') {
            if (!preg_match('/^\d{1,3}$/', $v) || (int)$v > 720) { $err[$k] = 'Cancellation notice must be between 0 and 720 hours.'; continue; }
            $clean['config'][$k] = (string)(int)$v;
        } elseif ($k === 'housekeeping_note') {
            $clean['config'][$k] = mb_substr($v, 0, 300);
        }
    }

    $ids = [];
    foreach ((array)($in['therapists'] ?? []) as $id => $status) { $ids[(int)$id] = (string)$status; }
    if ($ids) {
        $in2 = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, availability_status FROM therapists WHERE id IN ($in2)");
        $stmt->execute(array_keys($ids));
        $current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($ids as $id => $status) {
            if (!isset($current[$id])) { continue; }
            $allowed = ['available', 'on_leave', (string)$current[$id]];
            if (!in_array($status, $allowed, true)) { $err["therapist.$id"] = 'Invalid availability status.'; continue; }
            $clean['therapists'][$id] = $status;
        }
    }
    return [$err, $clean];
}

function sc_log(PDO $pdo, int $adminId, string $name, ?string $old, ?string $new): void
{
    $pdo->prepare('INSERT INTO configuration_logs (admin_id, setting_name, old_value, new_value, changed_at) VALUES (?,?,?,?,?)')
        ->execute([$adminId, $name, $old, $new, date('Y-m-d H:i:s')]);
}

function sc_save_all(PDO $pdo, int $adminId, array $clean): array
{
    $now = date('Y-m-d H:i:s');
    $changes = 0;
    try {
        $pdo->beginTransaction();

        $cur = [];
        foreach ($pdo->query('SELECT day_of_week, opening_time, closing_time, is_open FROM spa_operating_hours') as $r) { $cur[(int)$r['day_of_week']] = $r; }
        foreach ($clean['hours'] as $day => $h) {
            $old = $cur[$day] ?? null;
            $oldTxt = $old ? (substr((string)$old['opening_time'], 0, 5) . '–' . substr((string)$old['closing_time'], 0, 5) . ((int)$old['is_open'] ? ' open' : ' closed')) : null;
            $newTxt = $h['open'] . '–' . $h['close'] . ($h['is_open'] ? ' open' : ' closed');
            if ($oldTxt === $newTxt) { continue; }
            $pdo->prepare(
                'INSERT INTO spa_operating_hours (day_of_week, opening_time, closing_time, is_open, updated_at) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE opening_time = VALUES(opening_time), closing_time = VALUES(closing_time), is_open = VALUES(is_open), updated_at = VALUES(updated_at)'
            )->execute([$day, $h['open'] . ':00', $h['close'] . ':00', $h['is_open'], $now]);
            sc_log($pdo, $adminId, 'Hours · ' . SC_DAYS[$day], $oldTxt, $newTxt);
            $changes++;
        }

        $cfgNow = sc_config($pdo);
        foreach ($clean['config'] as $k => $v) {
            if (($cfgNow[$k] ?? null) === $v) { continue; }
            $pdo->prepare(
                'INSERT INTO spa_configuration (setting_key, setting_value, updated_at) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
            )->execute([$k, $v, $now]);
            sc_log($pdo, $adminId, 'Setting · ' . $k, $cfgNow[$k] ?? null, $v);
            $changes++;
        }

        foreach ($clean['therapists'] as $id => $status) {
            $stmt = $pdo->prepare('SELECT full_name, availability_status FROM therapists WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t || $t['availability_status'] === $status) { continue; }
            $pdo->prepare('UPDATE therapists SET availability_status = ? WHERE id = ?')->execute([$status, $id]);
            sc_log($pdo, $adminId, 'Therapist · ' . $t['full_name'], (string)$t['availability_status'], $status);
            $changes++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[BVO settings] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to save the configuration right now.'];
    }
    return ['ok' => true, 'changes' => $changes, 'message' => $changes === 0 ? 'No changes to save.' : 'Configuration saved (' . $changes . ' change' . ($changes === 1 ? '' : 's') . ').'];
}

function sc_save_rate(PDO $pdo, int $adminId, string $type, ?int $roomId, string $rateRaw): array
{
    $rateRaw = trim(str_replace([',', '₱', ' '], '', $rateRaw));
    if ($rateRaw === '' || !is_numeric($rateRaw) || (float)$rateRaw <= 0 || (float)$rateRaw > 9999999.99) {
        return ['ok' => false, 'error' => 'Enter a valid rate greater than zero.'];
    }
    $rate = round((float)$rateRaw, 2);

    if ($roomId) {
        $stmt = $pdo->prepare('SELECT id, room_name FROM rooms WHERE id = ?');
        $stmt->execute([$roomId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, room_name FROM rooms WHERE COALESCE(NULLIF(room_type, ''), 'Unclassified') = ?");
        $stmt->execute([$type]);
    }
    $rooms = $stmt->fetchAll();
    if (!$rooms) {
        return ['ok' => false, 'error' => 'No matching rooms found.'];
    }
    try {
        $pdo->beginTransaction();
        $now = date('Y-m-d H:i:s');
        foreach ($rooms as $r) {
            $old = $pdo->prepare('SELECT rate FROM room_pricing WHERE room_id = ? FOR UPDATE');
            $old->execute([(int)$r['id']]);
            $oldRate = $old->fetchColumn();
            if ($oldRate !== false && (float)$oldRate === $rate) { continue; }
            $pdo->prepare(
                "INSERT INTO room_pricing (room_id, rate, rate_type, updated_at) VALUES (?,?, 'per_session', ?)
                 ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_at = VALUES(updated_at)"
            )->execute([(int)$r['id'], $rate, $now]);
            sc_log($pdo, $adminId, 'Room rate · ' . $r['room_name'], $oldRate === false ? null : number_format((float)$oldRate, 2, '.', ''), number_format($rate, 2, '.', ''));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[BVO settings] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to save the rate right now.'];
    }
    return ['ok' => true, 'message' => 'Rate updated to ' . sc_money($rate) . ' for ' . (count($rooms) === 1 ? $rooms[0]['room_name'] : $type . ' (' . count($rooms) . ' rooms)') . '.'];
}