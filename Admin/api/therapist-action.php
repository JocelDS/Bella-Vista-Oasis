<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/therapists-data.php';

api_guard();

function ta_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ta_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    ta_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId < 1) {
    ta_out(['ok' => false, 'error' => 'Not authorized.'], 401);
}

$action = (string)($_POST['action'] ?? '');

try {
    $pdo = db();
    if (!ta_ready($pdo)) {
        ta_out(['ok' => false, 'error' => 'Run database/therapist-assignment-extensions.sql first.'], 409);
    }
    switch ($action) {
        case 'assign':
            ta_out(ta_assign($pdo, (int)($_POST['appointment_id'] ?? 0), (int)($_POST['therapist_id'] ?? 0), $adminId, !empty($_POST['override']), (string)($_POST['notes'] ?? '')));
        case 'save_availability':
            ta_out(ta_save_availability($pdo, $_POST, $adminId));
        case 'create_therapist':
            ta_out(ta_save_therapist($pdo, $_POST, $_FILES, null));
        case 'update_therapist':
            $tid = (int)($_POST['id'] ?? 0);
            if ($tid < 1) {
                ta_out(['ok' => false, 'error' => 'Invalid therapist.']);
            }
            ta_out(ta_save_therapist($pdo, $_POST, $_FILES, $tid));
        case 'save_shifts':
            ta_out(ta_save_shifts($pdo, $_POST));
    }
    ta_out(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[BVO therapists action] ' . $e->getMessage());
    ta_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}