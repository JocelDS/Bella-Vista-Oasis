<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/membership-data.php';

api_guard();

function ml_json(array $res, int $code = 200): never
{
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ml_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    if (!ml_ready($pdo)) {
        ml_json(['ok' => false, 'error' => 'Run database/membership-loyalty-extensions.sql first.'], 409);
    }
    $view = (string)($_GET['view'] ?? 'overview');
    switch ($view) {
        case 'overview':
            $set = ml_settings($pdo);
            if ($set['auto_award']) {
                ml_sync_earnings($pdo, null);
            }
            ml_json(ml_overview($pdo, $_GET));
        case 'members':
            ml_json(ml_members($pdo, $_GET));
        case 'member':
            $res = ml_member_detail($pdo, (int)($_GET['id'] ?? 0));
            ml_json($res, empty($res['ok']) ? 404 : 200);
        case 'ledger':
            ml_json(ml_ledger($pdo, $_GET));
        case 'tx':
            $res = ml_tx_detail($pdo, (int)($_GET['id'] ?? 0));
            ml_json($res, empty($res['ok']) ? 404 : 200);
        case 'customers':
            ml_json(ml_customers($pdo, (string)($_GET['q'] ?? '')));
        case 'eligible':
            $res = ml_eligible_rewards($pdo, (int)($_GET['customer'] ?? 0));
            ml_json($res, empty($res['ok']) ? 422 : 200);
        default:
            ml_json(['ok' => false, 'error' => 'Unknown request.'], 400);
    }
} catch (Throwable $e) {
    error_log('[BVO membership api] ' . $e->getMessage());
    ml_json(['ok' => false, 'error' => 'Unable to load membership data right now.'], 500);
}