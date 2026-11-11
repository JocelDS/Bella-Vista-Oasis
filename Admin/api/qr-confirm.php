<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo, $adminId] = qr_api('POST');

qr_guard_errors(static function () use ($pdo, $adminId): array {
    $action = (string)($_POST['action'] ?? 'confirm');
    $code   = (string)($_POST['code'] ?? '');

    if ($action === 'cancel_verification') {
        return qr_cancel_verification($pdo, $code, $adminId);
    }
    if ($action !== 'confirm') {
        http_response_code(422);
        return ['ok' => false, 'error' => 'Unknown action.'];
    }
    $res = qr_confirm($pdo, $code, $adminId, (string)($_POST['acknowledge_window'] ?? '') === '1');
    if (empty($res['ok'])) {
        http_response_code(!empty($res['needs_ack']) ? 409 : 422);
    }
    return $res;
});