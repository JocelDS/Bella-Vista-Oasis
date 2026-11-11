<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customers-data.php';

api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = db();
    if (!cm_ready($pdo)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Import database/customer-management-extensions.sql first.']);
        exit;
    }
    $f = cm_parse_filters($_GET);
    $from = cm_from($pdo);
    [$where, $params] = cm_where($f);
    $stmt = $pdo->prepare(
        "SELECT c.id, c.full_name, c.email, c.phone, c.registered_at, c.customer_status,
                m.membership_type AS tier, COALESCE(a.appts, 0) AS appts, COALESCE(p.spent, 0) AS spent
         $from $where ORDER BY " . cm_order($f['sort'])
    );
    $stmt->execute($params);

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bella-vista-guests-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Customer ID', 'Customer Name', 'Email', 'Contact Number', 'Registration Date', 'Status', 'Membership Tier', 'Total Appointments', 'Total Spending (PHP)']);
    // Cells starting with = + - @ are prefixed so spreadsheets never run them as formulas.
    $safe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    foreach ($stmt as $r) {
        fputcsv($out, [
            cm_code((int)$r['id']), $safe($r['full_name']), $safe($r['email']), $safe($r['phone']),
            substr((string)$r['registered_at'], 0, 10), ucfirst((string)$r['customer_status']),
            $r['tier'] ?: 'None', (int)$r['appts'], number_format((float)$r['spent'], 2, '.', ''),
        ]);
    }
    fclose($out);
} catch (Throwable $e) {
    error_log('[BVO customers] ' . $e->getMessage());
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to export right now.']);
}