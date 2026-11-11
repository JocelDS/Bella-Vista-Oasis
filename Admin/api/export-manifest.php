<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

if (!restore_from_cookie()) {
    http_response_code(401);
    exit('Session expired. Please sign in again.');
}
$check = db()->prepare("SELECT id FROM administrators WHERE id = ? AND status = 'active'");
$check->execute([(int)$_SESSION['admin_id']]);
if (!$check->fetch()) {
    http_response_code(401);
    exit('Session expired. Please sign in again.');
}

function csv_safe($v): string
{
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        return "'" . $s;
    }
    return $s;
}

try {
    $today = date('Y-m-d');
    $res   = fetch_schedule(db(), $today, 'all', '', 1, 1000);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to generate the manifest right now.');
}

$smap = dash_status_map();
$pmap = dash_payment_map();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bella-vista-manifest-' . $today . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Guest Name', 'Treatment', 'Therapist', 'Start', 'End', 'Suite', 'Status', 'Payment', 'Total Amount (PHP)', 'Paid Amount (PHP)']);

foreach ($res['rows'] as $r) {
    $st = (string)$r['appointment_status'];
    $ps = (string)$r['payment_status'];
    fputcsv($out, [
        csv_safe($r['guest_name']),
        csv_safe($r['service_name']),
        csv_safe($r['therapist_name']),
        date('g:i A', strtotime((string)$r['start_time'])),
        date('g:i A', strtotime((string)$r['end_time'])),
        csv_safe($r['room_name']),
        $smap[$st] ?? ucwords(str_replace('_', ' ', $st)),
        $pmap[$ps] ?? ucwords(str_replace('_', ' ', $ps)),
        number_format((float)$r['total_amount'], 2, '.', ''),
        number_format((float)$r['paid_amount'], 2, '.', ''),
    ]);
}
fclose($out);