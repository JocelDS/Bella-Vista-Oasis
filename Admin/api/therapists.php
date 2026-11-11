<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/therapists-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = db();
    if (!ta_ready($pdo)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Run database/therapist-assignment-extensions.sql first.']);
        exit;
    }
    echo json_encode(['ok' => true] + ta_load($pdo));
} catch (Throwable $e) {
    error_log('[BVO therapists api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load therapist data right now.']);
}