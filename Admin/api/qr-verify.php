<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo, $adminId] = qr_api('POST');

qr_guard_errors(static function () use ($pdo, $adminId): array {
    $code = (string)($_POST['code'] ?? '');
    return ['ok' => true, 'result' => qr_verify($pdo, $code, $adminId)];
});