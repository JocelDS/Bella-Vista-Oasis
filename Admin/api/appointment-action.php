<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/appointments-data.php';

api_guard();

function action_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    action_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    action_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$pdo    = db();

if ($action === 'create') {
    action_out(appt_save($pdo, $_POST, null));
}
if ($action === 'update') {
    if ($id < 1) {
        action_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    action_out(appt_save($pdo, $_POST, $id));
}
if ($action === 'cancel_preview') {
    if ($id < 1) {
        action_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    action_out(appt_cancel_preview($pdo, $id));
}
if ($action === 'delete') {
    if ($id < 1) {
        action_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    action_out(appt_delete($pdo, $id));
}
if (isset(appt_transitions()[$action])) {
    if ($id < 1) {
        action_out(['ok' => false, 'error' => 'Invalid appointment.']);
    }
    action_out(appt_transition($pdo, $id, $action));
}

action_out(['ok' => false, 'error' => 'Unknown action.']);