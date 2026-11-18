<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/services-data.php';

api_guard();

function sv_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sv_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    sv_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}
if ((int)($_SESSION['admin_id'] ?? 0) < 1) {
    sv_out(['ok' => false, 'error' => 'Not authorized.'], 401);
}

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

try {
    $pdo = db();
    switch ($action) {
        case 'create_service':
            sv_out(sv_create($pdo, $_POST));
        case 'update_service':
            sv_out(sv_update($pdo, $id, $_POST));
        case 'delete_service':
            sv_out(sv_delete($pdo, $id));
    }
    sv_out(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[BVO services action] ' . $e->getMessage());
    sv_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}