<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/settings-data.php';

api_guard();

function sc_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

try {
    $pdo = db();
    if (!sc_ready($pdo)) {
        sc_out(['ok' => false, 'error' => 'Import database/settings-extensions.sql first.'], 409);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['view'] ?? '') === 'review') {
            sc_out(['ok' => true] + sc_review($pdo));
        }
        if (($_GET['view'] ?? '') === 'blocked_dates') {
            $rows = $pdo->query('SELECT blocked_date, reason FROM blocked_dates ORDER BY blocked_date ASC')->fetchAll();
            sc_out(['ok' => true, 'dates' => $rows]);
        }
        sc_out(['ok' => false, 'error' => 'Unknown request.'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sc_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if (!csrf_verify()) {
        sc_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
    if (!sc_allowed_admin()) {
        sc_out(['ok' => false, 'error' => 'Your account is not permitted to change configuration.'], 403);
    }
    $adminId = (int)($_SESSION['admin_id'] ?? 0);

    switch ((string)($_POST['action'] ?? '')) {
        case 'save_all':
            $payload = json_decode((string)($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) {
                sc_out(['ok' => false, 'error' => 'Invalid request.'], 400);
            }
            [$err, $clean] = sc_validate($pdo, $payload);
            if ($err) {
                sc_out(['ok' => false, 'error' => reset($err), 'fields' => $err]);
            }
            $res = sc_save_all($pdo, $adminId, $clean);
            if ($res['ok']) {
                $res['status'] = sc_status($pdo);
            }
            sc_out($res, 500);
        case 'save_rate':
            $res = sc_save_rate($pdo, $adminId, trim((string)($_POST['room_type'] ?? '')), ((int)($_POST['room_id'] ?? 0)) ?: null, (string)($_POST['rate'] ?? ''));
            if ($res['ok']) {
                $res['rooms'] = sc_rooms($pdo);
                $res['status'] = sc_status($pdo);
            }
            sc_out($res);
        case 'add_blocked_date':
            $date = trim((string)($_POST['blocked_date'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                sc_out(['ok' => false, 'error' => 'Select a valid date.']);
            }
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                sc_out(['ok' => false, 'error' => 'Select a valid date.']);
            }
            $stmt = $pdo->prepare('INSERT INTO blocked_dates (blocked_date, reason, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_at = NOW()');
            $stmt->execute([$date, $reason ?: 'Manual closure']);
            $rows = $pdo->query('SELECT blocked_date, reason FROM blocked_dates ORDER BY blocked_date ASC')->fetchAll();
            sc_out(['ok' => true, 'dates' => $rows, 'message' => 'Blocked date saved.']);
        case 'remove_blocked_date':
            $date = trim((string)($_POST['blocked_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                sc_out(['ok' => false, 'error' => 'Select a valid date.']);
            }
            $pdo->prepare('DELETE FROM blocked_dates WHERE blocked_date = ?')->execute([$date]);
            $rows = $pdo->query('SELECT blocked_date, reason FROM blocked_dates ORDER BY blocked_date ASC')->fetchAll();
            sc_out(['ok' => true, 'dates' => $rows, 'message' => 'Blocked date removed.']);
        default:
            sc_out(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[BVO settings] ' . $e->getMessage());
    sc_out(['ok' => false, 'error' => 'Unable to complete that right now.'], 500);
}