<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customers-data.php';

api_guard();

function cm_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cm_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    cm_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

try {
    $pdo = db();
    if (!cm_ready($pdo)) {
        cm_out(['ok' => false, 'error' => 'Import database/customer-management-extensions.sql first.'], 409);
    }
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    switch ($action) {
        case 'create':
            cm_out(cm_save($pdo, $_POST, $_FILES['profile_photo'] ?? null, null));
        case 'update':
            if ($id < 1) { cm_out(['ok' => false, 'error' => 'Select a guest to edit.']); }
            cm_out(cm_save($pdo, $_POST, $_FILES['profile_photo'] ?? null, $id));
        case 'status':
            if ($id < 1) { cm_out(['ok' => false, 'error' => 'Select a guest.']); }
            cm_out(cm_set_status($pdo, $id, (string)($_POST['status'] ?? '')));
        case 'walkin':
            cm_out(cm_walkin($pdo, $_POST));
        default:
            cm_out(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[BVO customers] ' . $e->getMessage());
    cm_out(['ok' => false, 'error' => 'Unable to complete that right now.'], 500);
}