<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

$method = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'POST' : 'GET';
[$pdo, $adminId] = qr_api($method);

qr_guard_errors(static function () use ($pdo, $adminId, $method): array {
    if ($method === 'GET') {
        return ['ok' => true, 'appointments' => qr_generatable($pdo, qr_settings($pdo), qr_now())];
    }
    $id = (int)($_POST['appointment_id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Select an appointment first.'];
    }
    $res = qr_generate($pdo, $id, $adminId);
    if (empty($res['ok'])) {
        http_response_code(422);
    }
    return $res;
});