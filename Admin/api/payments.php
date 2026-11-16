<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/billing-data.php';

api_guard();

function pay_out(array $res, int $code = 200): never
{
    if (empty($res['ok']) && $code === 200) {
        $code = 422;
    }
    http_response_code($code);
    echo json_encode($res);
    exit;
}

function pay_csv(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

$pdo = db();

if (!bill_ready($pdo)) {
    pay_out(['ok' => false, 'error' => 'Run database/payment_management_migration.sql in phpMyAdmin first.'], 503);
}

$adminId = (int)$_SESSION['admin_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            pay_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'settle') {
            pay_out(bill_settle($pdo, $adminId, $_POST));
        }
        if ($action === 'refund' || $action === 'void') {
            pay_out(bill_reverse($pdo, $adminId, (int)($_POST['id'] ?? 0), $action, (string)($_POST['reason'] ?? '')));
        }
        pay_out(['ok' => false, 'error' => 'Unknown action.']);
    }

    $action = (string)($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $p   = bill_params($_GET);
        $res = bill_list($pdo, $p);
        pay_out(['ok' => true] + $res + ['summary' => bill_summary($pdo), 'escrow' => bill_escrow($pdo), 'commission' => bill_commissions($pdo)]);
    }
    if ($action === 'folios') {
        pay_out(['ok' => true, 'folios' => bill_folio_list($pdo)]);
    }
    if ($action === 'folio') {
        $f = bill_folio($pdo, (int)($_GET['id'] ?? 0));
        pay_out($f ? ['ok' => true, 'folio' => $f] : ['ok' => false, 'error' => 'That appointment no longer exists.']);
    }
    if ($action === 'detail') {
        $d = bill_detail($pdo, (int)($_GET['id'] ?? 0));
        pay_out($d ? ['ok' => true, 'transaction' => $d] : ['ok' => false, 'error' => 'That transaction no longer exists.']);
    }
    if ($action === 'ledger') {
        pay_out(['ok' => true, 'ledger' => bill_ledger($pdo), 'escrow' => bill_escrow($pdo)]);
    }
    if ($action === 'drawer') {
        pay_out(['ok' => true, 'drawer' => bill_drawer($pdo)]);
    }
    if ($action === 'export') {
        $p   = bill_params($_GET);
        $res = bill_list($pdo, $p, true);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bella-vista-transactions-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Invoice', 'Date', 'Time', 'Guest', 'Treatment', 'Reservation', 'Channel', 'Reference', 'Gross (PHP)', 'Deposit (PHP)', 'Settled (PHP)', 'Balance (PHP)', 'Status']);
        foreach ($res['rows'] as $r) {
            fputcsv($out, [
                $r['invoice'], $r['date'], $r['time'], pay_csv($r['guest']), pay_csv($r['service']), $r['ref'], $r['channel'], pay_csv($r['ext_ref']),
                number_format($r['gross'], 2, '.', ''), number_format($r['deposit'], 2, '.', ''), number_format($r['settled'], 2, '.', ''), number_format($r['balance'], 2, '.', ''), $r['status'],
            ]);
        }
        fclose($out);
        exit;
    }
    pay_out(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[BVO payments api] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    pay_out(['ok' => false, 'error' => 'Unable to complete the request right now.'], 500);
}