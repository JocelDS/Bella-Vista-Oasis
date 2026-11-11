<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointments-data.php';
require_once __DIR__ . '/../assets/icons/icons.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $payload = appt_payload(db(), appt_parse_filters($_GET));
    echo json_encode(['ok' => true] + $payload);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load appointments right now.']);
}