<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customers-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$id     = (int)($_GET['id'] ?? 0);
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'upcoming', 'completed', 'cancelled', 'no_show'], true)) {
    $filter = 'all';
}

try {
    $d = $id > 0 ? cm_detail(db(), $id, $filter) : null;
    if (!$d) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Guest not found.']);
        exit;
    }
    echo json_encode(['ok' => true] + $d);
} catch (Throwable $e) {
    error_log('[BVO customers] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load this guest right now.']);
}