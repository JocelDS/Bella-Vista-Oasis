<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/dashboard.php');
if (!cu_logged_in()) {
    cu_flash('error', 'Please sign in to manage your reservations.');
    cu_redirect('/login.php');
}
function ap_done(bool $ok, string $msg): never
{
    cu_flash($ok ? 'success' : 'error', $msg);
    cu_redirect('/dashboard.php');
}
if (!csrf_verify()) ap_done(false, 'Your session expired. Please try again.');

$cid = (int)$_SESSION['customer_id'];
$id = (int)($_POST['appointment_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['cancel', 'reschedule'], true) || $id < 1) ap_done(false, 'That request could not be processed.');
if (cu_rate_count('appt', (string)$cid, 60) >= 10) ap_done(false, 'Too many requests. Please try again later.');
cu_rate_hit('appt', (string)$cid);

$pdo = db();
try {
    $pdo->beginTransaction();
    $a = cd_find($pdo, $id, $cid, true);
    if (!$a) {
        $pdo->rollBack();
        ap_done(false, 'That reservation could not be found on your account.');
    }
    if (!cd_can_manage($a)) {
        $pdo->rollBack();
        ap_done(false, 'This reservation can no longer be changed online. Please contact the concierge.');
    }

    if ($action === 'cancel') {
        $pol = bk_cancel_policy($pdo, $a);
        $pdo->prepare('UPDATE appointments SET appointment_status = ?, cancelled_at = ?, cancellation_forfeited = ? WHERE id = ?')
            ->execute(['cancelled', now(), $pol['late'] ? 1 : 0, $id]);
        if ($pol['refund']) {
            $pdo->prepare("UPDATE appointments SET payment_status = 'refunded' WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE payments SET payment_status = 'refunded' WHERE appointment_id = ? AND payment_status = 'paid'")->execute([$id]);
        }
        $pdo->commit();
        ap_done(true, 'Reservation ' . cd_code($id) . ' cancelled. ' . $pol['message']);
    }

    $date = (string)($_POST['date'] ?? '');
    $start = (string)($_POST['start'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) {
        $pdo->rollBack();
        ap_done(false, 'Please choose a new date and time.');
    }
    $v = bk_validate($pdo, [
        'service_id' => $a['service_id'], 'room_id' => $a['room_id'], 'therapist_id' => $a['therapist_id'] ?? 0,
        'date' => $date, 'start' => substr($start, 0, 5), 'guests' => $a['guest_count'],
    ], $id, true, true);
    if (!$v['ok']) {
        $pdo->rollBack();
        ap_done(false, $v['error']);
    }
    $pol = bk_resched_policy($pdo, $a);
    $pdo->prepare('UPDATE appointments SET appointment_date = ?, start_time = ?, end_time = ? WHERE id = ?')->execute([$v['date'], $v['start'], $v['end'], $id]);
    if ($pol['forfeit']) $pdo->prepare('UPDATE appointments SET cancellation_forfeited = 1 WHERE id = ?')->execute([$id]);
    $pdo->commit();
    ap_done(true, 'Reservation ' . cd_code($id) . ' moved to ' . cd_date($v['date']) . ' at ' . cd_time($v['start']) . '.' . ($pol['forfeit'] ? ' The downpayment is forfeited because of the late reschedule.' : ''));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ap_done(false, 'We could not update your reservation right now. Please contact the concierge.');
}