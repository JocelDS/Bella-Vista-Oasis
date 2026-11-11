<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rooms-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    echo json_encode(['ok' => true, 'logs' => rm_logs(db(), (int)($_GET['room'] ?? 0))]);
} catch (Throwable $e) {
    error_log('[BVO rooms api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load maintenance records right now.']);
}