<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/membership-data.php';

api_guard();

function ml_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ml_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    ml_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId < 1) {
    ml_out(['ok' => false, 'error' => 'Not authorized.'], 401);
}

try {
    $pdo = db();
    if (!ml_ready($pdo)) {
        ml_out(['ok' => false, 'error' => 'Run database/membership-loyalty-extensions.sql first.'], 409);
    }
    switch ((string)($_POST['action'] ?? '')) {
        case 'create_tier':
            ml_out(ml_save_tier($pdo, $_POST, null));
        case 'update_tier':
            $id = (int)($_POST['id'] ?? 0);
            ml_out($id < 1 ? ml_fail('Invalid tier.') : ml_save_tier($pdo, $_POST, $id));
        case 'tier_status':
            ml_out(ml_set_tier_status($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? '')));
        case 'create_reward':
            ml_out(ml_save_reward($pdo, $_POST, $_FILES, null));
        case 'update_reward':
            $id = (int)($_POST['id'] ?? 0);
            ml_out($id < 1 ? ml_fail('Invalid reward.') : ml_save_reward($pdo, $_POST, $_FILES, $id));
        case 'reward_status':
            ml_out(ml_set_reward_status($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? '')));
        case 'adjust_points':
            ml_out(ml_adjust_points($pdo, $_POST, $adminId));
        case 'change_tier':
            ml_out(ml_change_tier($pdo, $_POST, $adminId));
        case 'renew':
            ml_out(ml_renew($pdo, (int)($_POST['customer_id'] ?? 0), $adminId));
        case 'redeem':
            ml_out(ml_redeem($pdo, $_POST, $adminId));
        case 'reverse':
            ml_out(ml_reverse($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''), $adminId));
        case 'save_settings':
            ml_out(ml_save_settings($pdo, $_POST));
        case 'sync':
            $r = ml_sync_earnings($pdo, $adminId);
            $r['message'] = $r['awarded'] > 0 ? 'Awarded ' . number_format($r['points']) . ' points across ' . $r['awarded'] . ' payment(s).' : 'No new qualifying payments to award.';
            ml_out($r);
        default:
            ml_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[BVO membership action] ' . $e->getMessage());
    $msg = stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), 'unique') !== false
        ? 'That record already exists or was already processed.'
        : 'The request could not be completed. Nothing was changed.';
    ml_out(['ok' => false, 'error' => $msg], 500);
}