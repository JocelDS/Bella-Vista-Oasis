<?php
declare(strict_types=1);

require_once __DIR__ . '/dashboard-data.php';
require_once __DIR__ . '/booking-rules.php';

function sv_fail(string $message): array
{
    return ['ok' => false, 'error' => $message];
}

function sv_duration_label(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $text = $h . ($h === 1 ? ' hour' : ' hours');
    return $m > 0 ? $text . ' ' . $m . ' min' : $text;
}

function sv_peso(float $amount): string
{
    return '₱' . number_format($amount, floor($amount) == $amount ? 0 : 2);
}

function sv_list(PDO $pdo): array
{
    $sql = "SELECT s.id, s.service_name, s.room_code, s.description, s.duration, s.price, s.base_capacity, s.max_capacity,
                   (SELECT COUNT(*) FROM rooms r WHERE r.room_name LIKE CONCAT(s.room_code, '-%')) AS room_total,
                   (SELECT COUNT(*) FROM rooms r WHERE r.room_name LIKE CONCAT(s.room_code, '-%') AND LOWER(r.room_status) NOT IN ('maintenance', 'cleaning')) AS room_open,
                   (SELECT COUNT(*) FROM appointments a WHERE a.service_id = s.id) AS bookings
            FROM services s
            ORDER BY s.id";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['id']            = (int)$r['id'];
        $r['duration']      = (int)$r['duration'];
        $r['price']         = (float)$r['price'];
        $r['base_capacity'] = (int)$r['base_capacity'];
        $r['max_capacity']  = (int)$r['max_capacity'];
        $r['room_total']    = (int)$r['room_total'];
        $r['room_open']     = (int)$r['room_open'];
        $r['bookings']      = (int)$r['bookings'];
        $r['is_family']     = strtoupper((string)$r['room_code']) === BK_FAMILY_CODE;
    }
    unset($r);
    return $rows;
}

function sv_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, service_name, room_code, duration, price, base_capacity, max_capacity, (SELECT COUNT(*) FROM appointments a WHERE a.service_id = services.id) AS bookings FROM services WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sv_input(PDO $pdo, array $in, ?int $id): array
{
    $name = trim((string)preg_replace('/\s+/', ' ', (string)($in['service_name'] ?? '')));
    $code = strtoupper(trim((string)($in['room_code'] ?? '')));
    $desc = trim((string)($in['description'] ?? ''));
    $dur  = trim((string)($in['duration'] ?? ''));
    $price = trim((string)($in['price'] ?? ''));
    $base = trim((string)($in['base_capacity'] ?? ''));
    $max  = trim((string)($in['max_capacity'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        return sv_fail('Treatment name must be 2 to 150 characters.');
    }
    if (!preg_match('/^[A-Z]{2,4}$/', $code)) {
        return sv_fail('Room code must be 2 to 4 letters, for example SR, CS or FL.');
    }
    if (mb_strlen($desc) > 1000) {
        return sv_fail('Description is too long (max 1000 characters).');
    }
    if ($dur === '' || !ctype_digit($dur) || (int)$dur < 15 || (int)$dur > 480 || (int)$dur % 5 !== 0) {
        return sv_fail('Duration must be between 15 and 480 minutes, in steps of 5.');
    }
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $price)) {
        return sv_fail('Enter a valid price, for example 800 or 1800.50.');
    }
    if ($base === '' || !ctype_digit($base) || (int)$base < 1 || (int)$base > 20) {
        return sv_fail('Base guests must be a whole number from 1 to 20.');
    }
    if ($max === '' || !ctype_digit($max) || (int)$max < 1 || (int)$max > 20) {
        return sv_fail('Maximum guests must be a whole number from 1 to 20.');
    }
    if ((int)$max < (int)$base) {
        return sv_fail('Maximum guests cannot be lower than base guests.');
    }

    $dupName = $pdo->prepare('SELECT id FROM services WHERE LOWER(service_name) = LOWER(?) AND id <> ? LIMIT 1');
    $dupName->execute([$name, (int)$id]);
    if ($dupName->fetch()) {
        return sv_fail('Another treatment already uses that name.');
    }
    $dupCode = $pdo->prepare('SELECT service_name FROM services WHERE room_code = ? AND id <> ? LIMIT 1');
    $dupCode->execute([$code, (int)$id]);
    $used = $dupCode->fetchColumn();
    if ($used !== false) {
        return sv_fail('Room code ' . $code . ' is already used by ' . $used . '. Each treatment needs its own code.');
    }

    return ['ok' => true, 'v' => [
        'name'  => $name,
        'code'  => $code,
        'desc'  => $desc,
        'dur'   => (int)$dur,
        'price' => number_format((float)$price, 2, '.', ''),
        'base'  => (int)$base,
        'max'   => (int)$max,
    ]];
}

function sv_chamber_warning(PDO $pdo, string $code): string
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_name LIKE CONCAT(?, '-%')");
    $stmt->execute([$code]);
    if ((int)$stmt->fetchColumn() > 0) {
        return '';
    }
    return ' No chamber named ' . $code . '-1 exists yet. Add one in Room Management so guests can book this treatment.';
}

function sv_create(PDO $pdo, array $in): array
{
    $c = sv_input($pdo, $in, null);
    if (empty($c['ok'])) {
        return $c;
    }
    $v = $c['v'];
    try {
        $pdo->prepare('INSERT INTO services (service_name, room_code, description, duration, price, base_capacity, max_capacity) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$v['name'], $v['code'], $v['desc'] !== '' ? $v['desc'] : null, $v['dur'], $v['price'], $v['base'], $v['max']]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[BVO services create] ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            return sv_fail('That treatment name or room code is already in use.');
        }
        return sv_fail('Unable to save the treatment right now.');
    }
    return ['ok' => true, 'id' => $id, 'message' => $v['name'] . ' was added.' . sv_chamber_warning($pdo, $v['code'])];
}

function sv_update(PDO $pdo, int $id, array $in): array
{
    $cur = sv_find($pdo, $id);
    if (!$cur) {
        return sv_fail('That treatment no longer exists.');
    }
    $c = sv_input($pdo, $in, $id);
    if (empty($c['ok'])) {
        return $c;
    }
    $v = $c['v'];
    if ($v['code'] !== strtoupper((string)$cur['room_code']) && (int)$cur['bookings'] > 0) {
        return sv_fail('The room code cannot change because this treatment already has ' . (int)$cur['bookings'] . ' booking' . ((int)$cur['bookings'] === 1 ? '' : 's') . ' on record.');
    }
    try {
        $pdo->prepare('UPDATE services SET service_name = ?, room_code = ?, description = ?, duration = ?, price = ?, base_capacity = ?, max_capacity = ? WHERE id = ?')
            ->execute([$v['name'], $v['code'], $v['desc'] !== '' ? $v['desc'] : null, $v['dur'], $v['price'], $v['base'], $v['max'], $id]);
    } catch (PDOException $e) {
        error_log('[BVO services update] ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            return sv_fail('That treatment name or room code is already in use.');
        }
        return sv_fail('Unable to save the treatment right now.');
    }
    return ['ok' => true, 'id' => $id, 'message' => $v['name'] . ' was updated.' . sv_chamber_warning($pdo, $v['code'])];
}

function sv_delete(PDO $pdo, int $id): array
{
    $cur = sv_find($pdo, $id);
    if (!$cur) {
        return sv_fail('That treatment no longer exists.');
    }
    $n = (int)$cur['bookings'];
    if ($n > 0) {
        return sv_fail($cur['service_name'] . ' has ' . $n . ' booking' . ($n === 1 ? '' : 's') . ' on record, so it cannot be deleted. Rename it or change its details instead.');
    }
    try {
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    } catch (PDOException $e) {
        error_log('[BVO services delete] ' . $e->getMessage());
        return sv_fail('Unable to delete the treatment right now.');
    }
    return ['ok' => true, 'message' => $cur['service_name'] . ' was deleted.'];
}