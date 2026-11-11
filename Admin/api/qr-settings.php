<?php
declare(strict_types=1);

// GET  -> current check-in window
// POST early_minutes, late_minutes, allow_pending -> update it
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

$method = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'POST' : 'GET';
[$pdo, $adminId] = qr_api($method);

qr_guard_errors(static function () use ($pdo, $adminId, $method): array {
    if ($method === 'GET') {
        return ['ok' => true, 'settings' => qr_settings($pdo)];
    }
    $early = filter_var($_POST['early_minutes'] ?? null, FILTER_VALIDATE_INT);
    $late  = filter_var($_POST['late_minutes'] ?? null, FILTER_VALIDATE_INT);
    if ($early === false || $late === false) {
        http_response_code(422);
        return ['ok' => false, 'error' => 'Enter whole numbers of minutes.'];
    }
    $res = qr_save_settings($pdo, $early, $late, (string)($_POST['allow_pending'] ?? '') === '1', $adminId);
    if (empty($res['ok'])) {
        http_response_code(422);
    }
    return $res;
});