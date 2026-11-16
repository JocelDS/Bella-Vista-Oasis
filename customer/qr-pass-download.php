<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/qr-pass.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
$a = $id > 0 ? qrp_load($pdo, (int)$currentCustomer['id'], $id) : null;   /* ownership check: only the guest's own booking */
if (!$a || !in_array($type, ['pdf', 'ics'], true)) {
    cu_flash('error', 'That reservation could not be found.');
    cu_redirect('/qr-checkin.php');
}
$back = '/qr-checkin.php?id=' . $id;
$ref = qrp_ref($id);

if ($type === 'ics') {
    if (in_array((string)$a['appointment_status'], ['cancelled', 'no_show'], true)) {
        cu_flash('error', 'A cancelled reservation cannot be added to a calendar.');
        cu_redirect($back);
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $ref . '.ics"');
    header('Cache-Control: private, no-store');
    echo qrp_ics($a);
    exit;
}

/* PDF: only for a live pass, using the same token the page shows. */
$cached = $_SESSION['qr_pass'][$id] ?? null;
if (!is_array($cached) || !qrp_token_active($pdo, $id, (string)$cached['payload'])) {
    cu_flash('error', 'Open your QR pass first. The PDF is available once your pass is active.');
    cu_redirect($back);
}
$pdf = qrp_pdf($a, qrp_matrix((string)$cached['payload']), (string)$cached['expires_label']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $ref . '-pass.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;