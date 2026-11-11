<?php
declare(strict_types=1);

// GET id -> check-in details for one appointment (read-only; used by the monitor's View action)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo, $adminId] = qr_api('GET');

qr_guard_errors(static function () use ($pdo, $adminId): array {
    $id = (int)($_GET['id'] ?? 0);
    $res = $id > 0 ? qr_detail($pdo, $id, $adminId) : null;
    if ($res === null) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Appointment not found.'];
    }
    return ['ok' => true, 'result' => $res];
});