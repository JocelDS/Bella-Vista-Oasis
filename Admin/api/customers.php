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

try {
    $pdo = db();
    if (!cm_ready($pdo)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Import database/customer-management-extensions.sql first.']);
        exit;
    }
    $f = cm_parse_filters($_GET);
    $out = ['ok' => true] + cm_list($pdo, $f);
    if (($_GET['stats'] ?? '1') !== '0') {
        $out['stats'] = cm_stats($pdo, $f['period']);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[BVO customers] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load customers right now.']);
}