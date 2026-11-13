<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/settings-data.php';
require_once __DIR__ . '/../includes/availability-data.php';

api_guard();

function bd_out(array $res, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bd_bookings(PDO $pdo, string $date): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_status IN ('pending', 'confirmed')");
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn();
}

function bd_list(PDO $pdo): array
{
    $today = date('Y-m-d');
    $rows = $pdo->query('SELECT id, blocked_date, reason FROM blocked_dates ORDER BY blocked_date ASC')->fetchAll();

    $counts = [];
    $stmt = $pdo->prepare(
        "SELECT appointment_date, COUNT(*) AS n FROM appointments
         WHERE appointment_date >= ? AND appointment_status IN ('pending', 'confirmed')
         GROUP BY appointment_date"
    );
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $c) {
        $counts[(string)$c['appointment_date']] = (int)$c['n'];
    }

    $upcoming = [];
    $past = [];
    foreach ($rows as $r) {
        $date = (string)$r['blocked_date'];
        $isPast = $date < $today;
        $item = [
            'id'       => (int)$r['id'],
            'date'     => $date,
            'label'    => date('D, M j, Y', strtotime($date)),
            'reason'   => (string)($r['reason'] ?? ''),
            'past'     => $isPast,
            'bookings' => $isPast ? 0 : ($counts[$date] ?? 0),
        ];
        if ($isPast) {
            array_unshift($past, $item);
        } else {
            $upcoming[] = $item;
        }
    }
    return array_merge($upcoming, $past);
}

function bd_plural(int $n): string
{
    return $n . ' pending or confirmed booking' . ($n === 1 ? ' already exists' : 's already exist');
}

try {
    $pdo = db();
    $today = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        bd_out(['ok' => true, 'today' => $today, 'dates' => bd_list($pdo)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bd_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if (!csrf_verify()) {
        bd_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
    if (!sc_allowed_admin()) {
        bd_out(['ok' => false, 'error' => 'Your account is not permitted to change configuration.'], 403);
    }
    $adminId = (int)($_SESSION['admin_id'] ?? 0);

    switch ((string)($_POST['action'] ?? '')) {
        case 'add':
            $date = trim((string)($_POST['blocked_date'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!av_valid_date($date)) {
                bd_out(['ok' => false, 'error' => 'Select a valid date.'], 422);
            }
            if ($date < $today) {
                bd_out(['ok' => false, 'error' => 'Choose today or a later date. Past dates cannot be blocked.'], 422);
            }
            if ($reason === '') {
                bd_out(['ok' => false, 'error' => 'Enter a reason for the closure.'], 422);
            }
            if (mb_strlen($reason) > 255) {
                bd_out(['ok' => false, 'error' => 'The reason must be 255 characters or fewer.'], 422);
            }

            $stmt = $pdo->prepare('SELECT reason FROM blocked_dates WHERE blocked_date = ?');
            $stmt->execute([$date]);
            $existing = $stmt->fetch();
            if ($existing) {
                $why = trim((string)($existing['reason'] ?? ''));
                bd_out(['ok' => false, 'error' => date('M j, Y', strtotime($date)) . ' is already blocked' . ($why !== '' ? ' (' . $why . ')' : '') . '.'], 409);
            }

            try {
                $pdo->prepare('INSERT INTO blocked_dates (blocked_date, reason, created_at) VALUES (?, ?, NOW())')->execute([$date, $reason]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    bd_out(['ok' => false, 'error' => date('M j, Y', strtotime($date)) . ' is already blocked.'], 409);
                }
                throw $e;
            }

            try {
                sc_log($pdo, $adminId, 'Blocked date · ' . $date, null, $reason);
            } catch (Throwable $e) {
                error_log('[BVO blocked-dates] ' . $e->getMessage());
            }

            $n = bd_bookings($pdo, $date);
            $res = ['ok' => true, 'today' => $today, 'message' => date('M j, Y', strtotime($date)) . ' is now blocked.', 'dates' => bd_list($pdo), 'bookings' => $n];
            if ($n > 0) {
                $res['warning'] = bd_plural($n) . ' on ' . date('M j, Y', strtotime($date)) . '. They are not cancelled automatically. Reschedule or cancel them from Appointments.';
            }
            bd_out($res);

        case 'remove':
            $date = trim((string)($_POST['blocked_date'] ?? ''));
            if (!av_valid_date($date)) {
                bd_out(['ok' => false, 'error' => 'Select a valid date.'], 422);
            }
            $stmt = $pdo->prepare('DELETE FROM blocked_dates WHERE blocked_date = ?');
            $stmt->execute([$date]);
            if ($stmt->rowCount() === 0) {
                bd_out(['ok' => false, 'error' => 'That date is no longer blocked.', 'today' => $today, 'dates' => bd_list($pdo)], 404);
            }
            try {
                sc_log($pdo, $adminId, 'Blocked date · ' . $date, 'blocked', 'removed');
            } catch (Throwable $e) {
                error_log('[BVO blocked-dates] ' . $e->getMessage());
            }
            bd_out(['ok' => true, 'today' => $today, 'message' => date('M j, Y', strtotime($date)) . ' is open for bookings again.', 'dates' => bd_list($pdo)]);

        default:
            bd_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[BVO blocked-dates] ' . $e->getMessage());
    bd_out(['ok' => false, 'error' => 'Unable to complete that right now.'], 500);
}