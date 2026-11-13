<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/availability-data.php';

api_guard();

function ud_out(array $res, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ud_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $start = trim((string)($_GET['start'] ?? ''));
    if ($start === '') {
        $start = date('Y-m-d');
    } elseif (!av_valid_date($start)) {
        ud_out(['ok' => false, 'error' => 'Invalid start date.'], 422);
    }

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 90;
    $days = $days < 1 ? 90 : min(AV_MAX_DAYS, $days);

    $serviceId = (int)($_GET['service_id'] ?? 0);

    $res = av_unavailable(db(), $start, $days, $serviceId > 0 ? $serviceId : null);
    ud_out(['ok' => true] + $res);
} catch (InvalidArgumentException $e) {
    ud_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[BVO availability] ' . $e->getMessage());
    ud_out(['ok' => false, 'error' => 'Unable to load availability right now.'], 500);
}