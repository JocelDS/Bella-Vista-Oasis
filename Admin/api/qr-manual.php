<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

$method = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'POST' : 'GET';
[$pdo, $adminId] = qr_api($method);

qr_guard_errors(static function () use ($pdo, $adminId, $method): array {
    if ($method === 'GET') {
        return ['ok' => true, 'results' => qr_manual_search($pdo, (string)($_GET['q'] ?? ''))];
    }
    $id = (int)($_POST['appointment_id'] ?? 0);
    if ($id < 1) {
        http_response_code(422);
        return ['ok' => false, 'error' => 'Select an appointment first.'];
    }
    $res = qr_manual_confirm(
        $pdo,
        $id,
        $adminId,
        (string)($_POST['identity_verified'] ?? '') === '1',
        (string)($_POST['acknowledge_window'] ?? '') === '1'
    );
    if (empty($res['ok'])) {
        http_response_code(!empty($res['needs_ack']) ? 409 : 422);
    }
    return $res;
});