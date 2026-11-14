<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/calendar-data.php';

api_guard();

function cal_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cal_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    cal_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

$action = (string)($_POST['action'] ?? '');
$pdo    = db();

if ($action === 'create') {
    cal_out(cal_create($pdo, $_POST));
}

if ($action === 'reschedule') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) {
        cal_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    cal_out(cal_reschedule($pdo, $id, $_POST));
}

if ($action === 'record_payment') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) {
        cal_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    cal_out(appt_record_payment($pdo, $id, $_POST));
}

cal_out(['ok' => false, 'error' => 'Unknown action.']);