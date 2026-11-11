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

$id = (int)($_GET['appointment_id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Select an appointment.']);
    exit;
}

try {
    $r = ta_recommend(db(), $id);
} catch (Throwable $e) {
    error_log('[BVO therapists api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to check therapists right now.']);
    exit;
}

if (!$r) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That appointment no longer exists.']);
    exit;
}

echo json_encode(['ok' => true] + $r);