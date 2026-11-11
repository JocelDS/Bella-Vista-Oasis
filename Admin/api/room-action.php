<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rooms-data.php';

api_guard();

function rm_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rm_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    rm_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId < 1) {
    rm_out(['ok' => false, 'error' => 'Not authorized.'], 401);
}

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

try {
    $pdo = db();
    if (!rm_ready($pdo)) {
        rm_out(['ok' => false, 'error' => 'Run database/room-management-extensions.sql first.'], 409);
    }
    switch ($action) {
        case 'create_room':
            rm_out(rm_create_room($pdo, $_POST));
        case 'update_room':
            rm_out(rm_update_room($pdo, $id, $_POST));
        case 'set_status':
            rm_out(rm_set_status($pdo, $id, (string)($_POST['room_status'] ?? '')));
        case 'save_climate':
            rm_out(rm_save_climate($pdo, $_POST, $adminId));
        case 'save_settings':
            rm_out(rm_save_settings($pdo, $_POST));
        case 'save_maintenance':
            rm_out(rm_save_maintenance($pdo, $_POST, $adminId, $id > 0 ? $id : null));
    }
    rm_out(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[BVO rooms action] ' . $e->getMessage());
    rm_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}