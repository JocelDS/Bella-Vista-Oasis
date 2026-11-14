<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/booking-bridge.php';

$customerId = cu_api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cu_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
if (!csrf_verify()) {
    cu_json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}

try {
    $pdo = db();
    if (!qr_ready($pdo)) {
        cu_json(['ok' => false, 'error' => 'QR check-in is not set up yet. Please contact the spa.'], 503);
    }

    $id = (int)($_POST['appointment_id'] ?? 0);
    $st = $pdo->prepare('SELECT appointment_status FROM appointments WHERE id = ? AND customer_id = ?');
    $st->execute([$id, $customerId]);
    $status = $st->fetchColumn();
    if ($status === false) {
        cu_json(['ok' => false, 'error' => 'That booking could not be found.'], 404);
    }
    if ($status === 'pending' && !qr_settings($pdo)['allow_pending']) {
        cu_json(['ok' => false, 'error' => 'Your booking is still waiting for the spa to confirm it. Your QR pass will be available once it is confirmed.'], 409);
    }

    $res = qr_generate($pdo, $id, null);
    if (!$res['ok']) {
        cu_json(['ok' => false, 'error' => (string)$res['error']], 409);
    }
    cu_json([
        'ok'            => true,
        'payload'       => $res['payload'],
        'expires_label' => $res['expires_label'],
        'pass'          => $res['pass'],
    ]);
} catch (Throwable $e) {
    error_log('[BVO customer qr-pass] ' . $e->getMessage());
    cu_json(['ok' => false, 'error' => 'Unable to create your QR pass right now.'], 500);
}
