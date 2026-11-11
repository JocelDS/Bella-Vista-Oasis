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

$date = appt_parse_date((string)($_GET['date'] ?? '')) ?? date('Y-m-d');
$tid  = max(0, (int)($_GET['therapist'] ?? 0));

try {
    echo json_encode(['ok' => true] + ta_week(db(), $date, $tid));
} catch (Throwable $e) {
    error_log('[BVO therapists api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load the weekly schedule right now.']);
}