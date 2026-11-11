<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$status = (string)($_GET['status'] ?? 'all');
if ($status !== 'all' && !isset(dash_status_map()[$status])) {
    $status = 'all';
}
$q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));

try {
    $res = fetch_schedule(db(), date('Y-m-d'), $status, $q, $page);
    echo json_encode([
        'ok'      => true,
        'html'    => render_schedule_rows($res['rows'], $status !== 'all' || $q !== ''),
        'total'   => $res['total'],
        'page'    => $res['page'],
        'pages'   => $res['pages'],
        'summary' => schedule_summary($res['total'], $res['page'], $res['per_page'], count($res['rows'])),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load the schedule right now.']);
}