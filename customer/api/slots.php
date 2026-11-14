<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/booking-bridge.php';

cu_api_guard();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        cu_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $pdo = db();
    $serviceId = (int)($_GET['service_id'] ?? 0);
    if ($serviceId < 1) {
        cu_json(['ok' => false, 'error' => 'Choose a treatment first.'], 422);
    }

    if (($_GET['mode'] ?? '') === 'dates') {
        $res = av_unavailable($pdo, date('Y-m-d'), CU_BOOKING_WINDOW_DAYS, $serviceId);
        cu_json(['ok' => true, 'today' => $res['today'], 'days' => CU_BOOKING_WINDOW_DAYS, 'dates' => $res['dates']]);
    }

    $date = trim((string)($_GET['date'] ?? ''));
    if (!av_valid_date($date)) {
        cu_json(['ok' => false, 'error' => 'Choose a valid date.'], 422);
    }
    cu_json(['ok' => true] + cu_merged_slots($pdo, $date, $serviceId));
} catch (InvalidArgumentException $e) {
    cu_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[BVO customer slots] ' . $e->getMessage());
    cu_json(['ok' => false, 'error' => 'Unable to load times right now.'], 500);
}
