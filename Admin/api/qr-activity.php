<?php
declare(strict_types=1);

// GET limit -> recent check-in activity (real log records)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo] = qr_api('GET');

qr_guard_errors(static function () use ($pdo): array {
    return ['ok' => true, 'items' => qr_activity($pdo, (int)($_GET['limit'] ?? 8))];
});