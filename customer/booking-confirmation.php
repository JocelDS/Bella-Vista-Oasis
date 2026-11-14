<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/booking-bridge.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
cu_booking_ready($pdo);

/* Only the owner of the appointment can open its confirmation. */
$st = $pdo->prepare(
    'SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount, a.guest_count,
            a.extra_person_fee, a.membership_discount, s.service_name, r.room_name, t.full_name AS therapist, c.full_name AS customer
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN customers c ON c.id = a.customer_id
     LEFT JOIN rooms r ON r.id = a.room_id
     LEFT JOIN therapists t ON t.id = a.therapist_id
     WHERE a.id = ? AND a.customer_id = ?'
);
$st->execute([$id, (int)$currentCustomer['id']]);
$a = $st->fetch();
if (!$a) {
    cu_flash('error', 'That booking could not be found.');
    cu_redirect('/dashboard.php');
}

$ref = 'BVO-' . str_pad((string)$a['id'], 4, '0', STR_PAD_LEFT);
$arrive = (int)cu_setting('arrival_minutes_before', '15');

$pageTitle = 'Booking Confirmed';
$activeNav = 'book';
$pageStyles = ['booking.css', 'booking-responsive.css'];
$pageScripts = ['qrcode.min.js', 'booking-qr.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="bk bk-done">
    <div class="bk-done-head">
        <div class="bk-check" aria-hidden="true">&#10003;</div>
        <p class="bk-eyebrow"><span>Appointment Request Received</span></p>
        <h1 class="bk-title">Your Sanctuary Session Is Booked</h1>
        <span class="bk-ref"><?= e($ref) ?></span>
    </div>
    <div class="bk-done-grid">
        <section class="bk-card">
            <p class="bk-label-sm">Reservation</p>
            <h2 class="bk-h2"><?= e($a['service_name']) ?></h2>
            <dl class="bk-dl">
                <div><dt>Guest</dt><dd><?= e($a['customer']) ?></dd></div>
                <div><dt>Date</dt><dd><?= e(date('D, M j, Y', strtotime((string)$a['appointment_date']))) ?></dd></div>
                <div><dt>Time</dt><dd><?= e(date('g:i A', strtotime((string)$a['start_time']))) ?> – <?= e(date('g:i A', strtotime((string)$a['end_time']))) ?></dd></div>
                <div><dt>Chamber</dt><dd><?= e((string)($a['room_name'] ?? 'To be assigned')) ?></dd></div>
                <div><dt>Master Practitioner</dt><dd><?= e((string)($a['therapist'] ?? 'Any Master Therapist')) ?></dd></div>
                <div><dt>Guests</dt><dd><?= (int)$a['guest_count'] ?></dd></div>
                <div><dt>Status</dt><dd><span class="bk-status"><?= e((string)$a['appointment_status']) ?></span></dd></div>
                <div><dt>Payment</dt><dd><?= e(ucfirst((string)$a['payment_status'])) ?></dd></div>
                <?php if ((float)$a['membership_discount'] > 0): ?><div><dt>Member discount</dt><dd>−<?= cu_peso((float)$a['membership_discount']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="bk-total"><span>Total Payable:</span><b><?= cu_peso((float)$a['total_amount']) ?></b></div>
            <p class="bk-fine">Please arrive <?= $arrive ?> minutes early. The spa will confirm your booking shortly.</p>
        </section>
        <section class="bk-card bk-qr" id="qrBox" data-endpoint="<?= e(cu_url('/api/qr-pass.php')) ?>" data-id="<?= (int)$a['id'] ?>" data-csrf="<?= e(csrf_token()) ?>">
            <p class="bk-label-sm">Check-In Pass</p>
            <h2 class="bk-h2">QR Pass</h2>
            <canvas id="qrCanvas" width="200" height="200" hidden></canvas>
            <p class="bk-hint" id="qrMeta">Preparing your QR pass…</p>
            <button type="button" class="bk-btn bk-btn-line" id="qrRetry" hidden>Try again</button>
        </section>
    </div>
    <div class="bk-done-actions">
        <a class="bk-btn bk-btn-primary" href="<?= e(cu_url('/dashboard.php')) ?>">Go to My Sanctuary</a>
        <a class="bk-btn bk-btn-line" href="<?= e(cu_url('/qr-checkin.php')) ?>">QR Check-In</a>
        <a class="bk-btn bk-btn-line" href="<?= e(cu_url('/book-appointment.php')) ?>">Book Another</a>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php';