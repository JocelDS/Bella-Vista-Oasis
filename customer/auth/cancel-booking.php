<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-auth-check.php';
require_once __DIR__ . '/../includes/booking-bridge.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/dashboard.php');
if (!csrf_verify()) {
    cu_flash('error', 'Your session expired. Please try again.');
    cu_redirect('/dashboard.php');
}

$id = (int)($_POST['appointment_id'] ?? 0);
$pdo = db();
$st = $pdo->prepare("SELECT a.*, s.service_name, r.room_name FROM appointments a JOIN services s ON s.id = a.service_id LEFT JOIN rooms r ON r.id = a.room_id WHERE a.id = ? AND a.customer_id = ? AND a.appointment_status IN ('pending', 'confirmed')");
$st->execute([$id, (int)$currentCustomer['id']]);
$a = $st->fetch();
if (!$a) {
    cu_flash('error', 'That booking could not be found or is already closed.');
    cu_redirect('/dashboard.php');
}

$policy = bk_cancel_policy($pdo, $a);
$forfeit = $policy['late'] ? 1 : 0;

$pdo->beginTransaction();
$pdo->prepare("UPDATE appointments SET appointment_status = 'cancelled', cancelled_at = ?, cancellation_forfeited = ? WHERE id = ?")
    ->execute([now(), $forfeit, $id]);
if ($policy['refund']) {
    $pdo->prepare("UPDATE appointments SET payment_status = 'refunded' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE payments SET payment_status = 'refunded' WHERE appointment_id = ? AND payment_status = 'paid'")->execute([$id]);
}
$pdo->commit();

$pdo->prepare('INSERT INTO notifications (admin_id, title, message, created_at) VALUES (NULL, ?, ?, ?)')->execute([
    $policy['late'] ? 'Late cancellation' : 'Booking cancelled',
    $currentCustomer['full_name'] . ' cancelled ' . $a['service_name'] . ' (' . ($a['room_name'] ?? '') . ') on ' . date('M j, Y', strtotime((string)$a['appointment_date'])) . '.' . ($forfeit && (float)$a['downpayment_amount'] > 0 ? ' Downpayment forfeited.' : ''),
    now(),
]);

cu_flash('success', 'Booking cancelled. ' . $policy['message']);
cu_redirect('/dashboard.php');
