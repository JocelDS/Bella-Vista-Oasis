<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/booking-bridge.php';

$pdo = db();
$cid = (int)$currentCustomer['id'];
$bookings = cu_qr_bookings($pdo, $cid);
$qrReady = qr_ready($pdo);
$win = $qrReady ? qr_settings($pdo) : ['early' => 60, 'late' => 30, 'allow_pending' => false];
$arrive = (int)cu_setting('arrival_minutes_before', '15');

$pageTitle = 'QR Check-In';
$activeNav = 'qr';
$pageStyles = ['customer-booking.css'];
$pageScripts = ['qrcode.min.js', 'customer-qr.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="cu-dash">
    <section class="cu-card">
        <p class="cu-eyebrow pink">QR Check-In</p>
        <h1 class="serif">Your Arrival Passes</h1>
        <p class="cu-sub">Show your QR pass at the front desk. It works for confirmed bookings, from <?= (int)$win['early'] ?> minutes before until <?= (int)$win['late'] ?> minutes after your start time. Please arrive <?= $arrive ?> minutes early.</p>
    </section>

    <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <section class="cu-card" id="qrList" data-endpoint="<?= e(cu_url('/api/qr-pass.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
        <?php if (!$bookings): ?>
            <p class="cu-note">You have no upcoming bookings. <a class="cu-link" href="<?= e(cu_url('/book-appointment.php')) ?>">Book an appointment</a> to get a QR pass.</p>
        <?php else: ?>
        <ul class="bk-list">
            <?php foreach ($bookings as $a):
                $confirmed = $a['appointment_status'] === 'confirmed' || ($a['appointment_status'] === 'pending' && $win['allow_pending']);
                ?>
            <li>
                <span><?= e($a['service_name']) ?><?= $a['room_name'] ? ' &middot; ' . e($a['room_name']) : '' ?><small>BVO-<?= str_pad((string)(int)$a['id'], 4, '0', STR_PAD_LEFT) ?> &middot; <?= e(date('D, M j', strtotime((string)$a['appointment_date']))) ?> &middot; <?= e(date('g:i A', strtotime((string)$a['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime((string)$a['end_time']))) ?></small></span>
                <span class="bk-actions">
                    <?php if ((int)$a['checked_in'] > 0): ?>
                        <span class="bk-state confirmed">Checked in</span>
                    <?php elseif ($confirmed && $qrReady): ?>
                        <button type="button" class="cu-btn cu-btn-primary" data-qr="<?= (int)$a['id'] ?>"><span class="btn-label">Show QR Pass</span></button>
                    <?php else: ?>
                        <span class="bk-state">Awaiting confirmation</span>
                    <?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="cu-card qr-pass" id="qrPass" hidden>
        <p class="cu-eyebrow">Arrival Pass</p>
        <canvas id="qrCanvas" width="256" height="256" aria-label="QR code for check-in"></canvas>
        <p class="qr-pass-title" id="qrTitle"></p>
        <p class="cu-note" id="qrMeta"></p>
        <p class="cu-note">Generating a new pass replaces the previous one.</p>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php';
