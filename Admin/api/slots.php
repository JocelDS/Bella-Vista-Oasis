<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/availability-data.php';

api_guard();

function sl_out(array $res, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sl_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $date = trim((string)($_GET['date'] ?? ''));
    if (!av_valid_date($date)) {
        sl_out(['ok' => false, 'error' => 'Select a valid date.'], 422);
    }

    $serviceId = (int)($_GET['service_id'] ?? 0);
    if ($serviceId < 1) {
        sl_out(['ok' => false, 'error' => 'Select a service first.'], 422);
    }

    $roomId    = max(0, (int)($_GET['room_id'] ?? 0));
    $excludeId = max(0, (int)($_GET['exclude_id'] ?? 0));

    sl_out(['ok' => true] + av_slots(db(), $date, $serviceId, $roomId, $excludeId));
} catch (InvalidArgumentException $e) {
    sl_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[BVO slots] ' . $e->getMessage());
    sl_out(['ok' => false, 'error' => 'Unable to load time slots right now.'], 500);
}