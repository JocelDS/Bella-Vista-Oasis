<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/calendar-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid appointment.']);
    exit;
}

try {
    echo json_encode(cal_check(db(), $id, $_GET));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to check availability right now.']);
}