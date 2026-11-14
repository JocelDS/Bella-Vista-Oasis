<?php
declare(strict_types=1);
require_once __DIR__ . '/customer-session.php';
require_once __DIR__ . '/../../admin/includes/dashboard-data.php';
require_once __DIR__ . '/../../admin/includes/availability-data.php';
require_once __DIR__ . '/../../admin/includes/qr-checkin-data.php';

const CU_BOOKING_WINDOW_DAYS = 60;

function cu_json(array $res, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cu_api_guard(): int
{
    if (!cu_logged_in()) {
        cu_json(['ok' => false, 'error' => 'Please sign in again to continue.'], 401);
    }
    $st = db()->prepare("SELECT id FROM customers WHERE id = ? AND customer_status = 'active'");
    $st->execute([(int)$_SESSION['customer_id']]);
    $id = $st->fetchColumn();
    if (!$id) {
        cu_json(['ok' => false, 'error' => 'Your account is not active.'], 403);
    }
    return (int)$id;
}

function cu_service_options(PDO $pdo): array
{
    $cfg = bk_config($pdo);
    $out = [];
    foreach ($pdo->query('SELECT id, service_name, room_code, description, duration, price, base_capacity, max_capacity FROM services ORDER BY id')->fetchAll() as $s) {
        $isFamily = strtoupper((string)$s['room_code']) === BK_FAMILY_CODE;
        $s['id'] = (int)$s['id'];
        $s['duration'] = (int)$s['duration'];
        $s['price'] = (float)$s['price'];
        $s['min_guests'] = $isFamily ? $cfg['base_pax'] : (int)$s['base_capacity'];
        $s['max_guests'] = $isFamily ? min((int)$s['max_capacity'], $cfg['max_pax']) : (int)$s['max_capacity'];
        $s['extra_fee'] = $isFamily ? $cfg['extra_fee'] : 0.0;
        $s['base_guests'] = $isFamily ? $cfg['base_pax'] : (int)$s['base_capacity'];
        $out[$s['id']] = $s;
    }
    return $out;
}

function cu_merged_slots(PDO $pdo, string $date, int $serviceId): array
{
    $res = av_slots($pdo, $date, $serviceId);
    $merged = [];
    foreach ($res['rooms'] as $room) {
        if (!$room['usable']) continue;
        foreach ($room['slots'] as $slot) {
            $k = $slot['start'];
            if (!isset($merged[$k])) {
                $merged[$k] = ['start' => $slot['start'], 'end' => $slot['end'], 'label' => $slot['label'], 'available' => false, 'reason' => $slot['reason']];
            }
            if ($slot['available']) {
                $merged[$k]['available'] = true;
                $merged[$k]['reason'] = '';
            }
        }
    }
    ksort($merged);
    foreach ($merged as &$m) {
        if (!$m['available'] && $m['reason'] === 'Booked') $m['reason'] = 'Fully booked';
    }
    unset($m);
    return [
        'date'        => $res['date'],
        'date_status' => $res['date_status'],
        'slots'       => array_values($merged),
    ];
}

const CU_WAIVER_VERSION = '2026.1';

/** Creates the booking extras on first use (waiver table + discount column). Runs before any transaction. */
function cu_booking_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS appointment_waivers (
            id INT NOT NULL AUTO_INCREMENT,
            appointment_id INT NOT NULL,
            customer_id INT NOT NULL,
            waiver_version VARCHAR(20) NOT NULL,
            consent_status VARCHAR(20) NOT NULL DEFAULT 'accepted',
            accepted_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_waiver_appt (appointment_id),
            KEY idx_waiver_customer (customer_id),
            CONSTRAINT fk_waiver_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'membership_discount'")->fetch()) {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN membership_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[BVO booking ready] ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/** The customer's real membership from the database. Discount only applies to an active, unexpired membership. */
function cu_booking_member(PDO $pdo, int $customerId): ?array
{
    try {
        $st = $pdo->prepare('SELECT m.membership_type, m.membership_status, m.expiry_date, m.tier_id FROM memberships m WHERE m.customer_id = ? ORDER BY m.id DESC LIMIT 1');
        $st->execute([$customerId]);
        $m = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$m) return null;
    $active = strtolower((string)($m['membership_status'] ?: 'active')) === 'active'
        && (empty($m['expiry_date']) || $m['expiry_date'] >= date('Y-m-d'));
    $tier = (string)$m['membership_type'];
    $pct = 0.0;
    $perks = [];
    if ($m['tier_id'] !== null) {
        try {
            $t = $pdo->prepare("SELECT tier_name, discount_percentage, benefits FROM membership_tiers WHERE id = ? AND status = 'active'");
            $t->execute([(int)$m['tier_id']]);
            if ($row = $t->fetch()) {
                $tier = (string)$row['tier_name'];
                $pct = (float)$row['discount_percentage'];
                foreach (preg_split('/\r?\n/', (string)$row['benefits']) as $line) {
                    if (trim($line) !== '') $perks[] = trim($line);
                }
            }
        } catch (Throwable $e) {
        }
    }
    return ['tier' => $tier, 'active' => $active, 'discount_pct' => $active ? $pct : 0.0, 'expiry' => $m['expiry_date'], 'perks' => array_slice($perks, 0, 3)];
}

function cu_pick_room_and_book(PDO $pdo, int $customerId, array $in, array $health, array $opts = []): array
{
    $serviceId = (int)$in['service_id'];
    $st = $pdo->prepare('SELECT room_code FROM services WHERE id = ?');
    $st->execute([$serviceId]);
    $code = strtoupper((string)$st->fetchColumn());
    if ($code === '') {
        return ['ok' => false, 'error' => 'The selected treatment is no longer available.'];
    }

    $rooms = $pdo->query("SELECT id, room_name FROM rooms WHERE room_status <> 'maintenance' ORDER BY id")->fetchAll();
    $candidates = array_values(array_filter($rooms, static fn(array $r): bool => bk_room_code((string)$r['room_name']) === $code));
    if (!$candidates) {
        return ['ok' => false, 'error' => 'No room is available for this treatment right now.'];
    }
    $prefer = (int)($opts['room_id'] ?? 0);
    if ($prefer > 0) {
        usort($candidates, static fn(array $a, array $b): int => ((int)$b['id'] === $prefer) <=> ((int)$a['id'] === $prefer));
    }
    $therapistId = max(0, (int)($opts['therapist_id'] ?? 0));
    $member = cu_booking_member($pdo, $customerId);
    $pct = $member ? (float)$member['discount_pct'] : 0.0;
    $ready = cu_booking_ready($pdo);

    $lastError = 'That time is no longer available. Please choose another time.';
    foreach ($candidates as $room) {
        $pdo->beginTransaction();
        try {
            $v = bk_validate($pdo, [
                'service_id'   => $serviceId,
                'room_id'      => (int)$room['id'],
                'therapist_id' => $therapistId,
                'date'         => (string)$in['date'],
                'start'        => (string)$in['start'],
                'guests'       => $in['guests'],
            ], 0, true, true);

            if (!$v['ok']) {
                $pdo->rollBack();
                $msg = (string)$v['error'];
                if (!str_contains($msg, 'That room is booked')) {
                    return ['ok' => false, 'error' => $msg];
                }
                continue;
            }

            /* The server decides every amount: price, extra-guest fee and the member discount. */
            $gross    = (float)$v['total'];
            $discount = $pct > 0 ? round($gross * $pct / 100, 2) : 0.0;
            $total    = round($gross - $discount, 2);

            $cols = 'customer_id, service_id, room_id, therapist_id, appointment_date, start_time, end_time, appointment_status, payment_status, total_amount, guest_count, extra_person_fee, health_conditions, health_injuries, is_pregnant';
            $vals = [$customerId, $serviceId, (int)$room['id'], $therapistId > 0 ? $therapistId : null, $v['date'], $v['start'], $v['end'], 'pending', 'pending', $total, $v['guests'], $v['extra'], $health['conditions'], $health['injuries'], $health['pregnant']];
            if ($ready) {
                $cols .= ', membership_discount';
                $vals[] = $discount;
            }
            $pdo->prepare('INSERT INTO appointments (' . $cols . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();

            if ($ready && !empty($opts['waiver'])) {
                $pdo->prepare('INSERT INTO appointment_waivers (appointment_id, customer_id, waiver_version, consent_status, accepted_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $customerId, CU_WAIVER_VERSION, 'accepted', date('Y-m-d H:i:s')]);
            }

            $name = (string)$pdo->query('SELECT full_name FROM customers WHERE id = ' . $customerId)->fetchColumn();
            $pdo->prepare('INSERT INTO notifications (admin_id, title, message, created_at) VALUES (NULL, ?, ?, ?)')->execute([
                'New online booking',
                $name . ' booked ' . $v['service']['service_name'] . ' (' . $room['room_name'] . ') on ' . date('M j, Y', strtotime($v['date'])) . ' at ' . bk_label($v['start_min']) . '. Waiting for confirmation.',
                date('Y-m-d H:i:s'),
            ]);

            $pdo->commit();
            return [
                'ok'       => true,
                'id'       => $id,
                'room'     => (string)$room['room_name'],
                'service'  => (string)$v['service']['service_name'],
                'date'     => $v['date'],
                'start'    => $v['start'],
                'end'      => $v['end'],
                'total'    => $total,
                'discount' => $discount,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[BVO customer booking] ' . get_class($e) . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Unable to save your booking right now. Please try again.'];
        }
    }
    return ['ok' => false, 'error' => $lastError];
}

function cu_my_bookings(PDO $pdo, int $customerId, bool $upcomingOnly): array
{
    $sql = "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                   a.guest_count, a.downpayment_amount, s.service_name, r.room_name
            FROM appointments a
            JOIN services s ON s.id = a.service_id
            LEFT JOIN rooms r ON r.id = a.room_id
            WHERE a.customer_id = ?";
    if ($upcomingOnly) {
        $sql .= " AND a.appointment_status IN ('pending', 'confirmed') AND CONCAT(a.appointment_date, ' ', a.start_time) >= '" . date('Y-m-d H:i:s') . "'";
        $sql .= ' ORDER BY a.appointment_date, a.start_time LIMIT 20';
    } else {
        $sql .= " AND (a.appointment_status NOT IN ('pending', 'confirmed') OR CONCAT(a.appointment_date, ' ', a.start_time) < '" . date('Y-m-d H:i:s') . "')";
        $sql .= ' ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 10';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$customerId]);
    return $st->fetchAll();
}

function cu_qr_bookings(PDO $pdo, int $customerId): array
{
    $st = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, s.service_name, r.room_name,
                (SELECT COUNT(*) FROM appointment_checkins k WHERE k.appointment_id = a.id AND k.checkin_status = 'checked_in') AS checked_in
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         LEFT JOIN rooms r ON r.id = a.room_id
         WHERE a.customer_id = ? AND a.appointment_status IN ('pending', 'confirmed')
           AND CONCAT(a.appointment_date, ' ', a.end_time) > ?
         ORDER BY a.appointment_date, a.start_time LIMIT 20"
    );
    $st->execute([$customerId, date('Y-m-d H:i:s')]);
    return $st->fetchAll();
}