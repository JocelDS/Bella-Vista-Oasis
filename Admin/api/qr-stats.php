<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo] = qr_api('GET');

qr_guard_errors(static function () use ($pdo): array {
    return [
        'ok'       => true,
        'stats'    => qr_stats($pdo),
        'settings' => qr_settings($pdo),
        'server'   => ['time' => date('c'), 'timezone' => date_default_timezone_get()],
    ];
});