<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/booking-bridge.php';

$pdo = db();
$cid = (int)$currentCustomer['id'];

$st = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed'");
$st->execute([$cid]);
$balance = (int)$st->fetchColumn();

$st = $pdo->prepare('SELECT transaction_type, points, description, created_at FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
$st->execute([$cid]);
$ledger = $st->fetchAll();

$st = $pdo->prepare("SELECT membership_type, expiry_date FROM memberships WHERE customer_id = ? AND membership_status = 'active' ORDER BY id DESC LIMIT 1");
$st->execute([$cid]);
$membership = $st->fetch();

$upcoming = cu_my_bookings($pdo, $cid, true);
$history = cu_my_bookings($pdo, $cid, false);

$pageTitle = 'My Sanctuary';
$activeNav = 'loyalty';
$pageScripts = [];
$pageStyles = ['customer-booking.css'];
require __DIR__ . '/includes/header.php';
?>
<main class="cu-dash">
    <section class="cu-card cu-dash-hero">
        <div>
            <p class="cu-eyebrow pink">Welcome back</p>
            <h1 class="serif"><?= e($currentCustomer['full_name']) ?></h1>
            <p class="cu-sub">Membership ID <strong><?= e($currentCustomer['membership_code'] ?: 'Pending') ?></strong><?php if ($membership): ?> &middot; <?= e($membership['membership_type']) ?> member<?php endif; ?></p>
        </div>
        <form method="post" action="<?= e(cu_url('/auth/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="cu-btn cu-btn-soft">Sign Out</button>
        </form>
    </section>

    <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="cu-dash-grid">
        <section class="cu-card" id="loyalty">
            <p class="cu-eyebrow">Tranquility Points</p>
            <p class="cu-points"><?= number_format($balance) ?></p>
            <?php if ($ledger): ?>
            <ul class="cu-ledger">
                <?php foreach ($ledger as $l): ?>
                <li><span><?= e($l['description'] ?: ucfirst((string)$l['transaction_type'])) ?><small><?= e(date('M j, Y', strtotime((string)$l['created_at']))) ?></small></span><b class="<?= (int)$l['points'] < 0 ? 'neg' : 'pos' ?>"><?= (int)$l['points'] > 0 ? '+' : '' ?><?= (int)$l['points'] ?></b></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><p class="cu-note">No ledger activity yet.</p><?php endif; ?>
        </section>
        <section class="cu-card">
            <p class="cu-eyebrow">Upcoming Bookings</p>
            <?php if ($upcoming): ?>
            <ul class="bk-list">
                <?php foreach ($upcoming as $a): ?>
                <li>
                    <span><?= e($a['service_name']) ?><?= $a['room_name'] ? ' &middot; ' . e($a['room_name']) : '' ?><small><?= e(date('D, M j', strtotime((string)$a['appointment_date']))) ?> &middot; <?= e(date('g:i A', strtotime((string)$a['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime((string)$a['end_time']))) ?> &middot; <?= e(cu_peso((float)$a['total_amount'])) ?></small></span>
                    <span class="bk-actions">
                        <span class="bk-state <?= e((string)$a['appointment_status']) ?>"><?= e(str_replace('_', ' ', (string)$a['appointment_status'])) ?></span>
                        <form method="post" action="<?= e(cu_url('/auth/cancel-booking.php')) ?>" onsubmit="return confirm('Cancel this booking? Late cancellations forfeit any downpayment.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="bk-cancel">Cancel</button>
                        </form>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><p class="cu-note">You have no upcoming bookings.</p><?php endif; ?>
            <p><a class="cu-btn cu-btn-primary" href="<?= e(cu_url('/book-appointment.php')) ?>"><span class="btn-label">Book Appointment</span></a> <a class="cu-btn cu-btn-soft" href="<?= e(cu_url('/qr-checkin.php')) ?>"><span class="btn-label">QR Check-In</span></a></p>
        </section>
        <?php if ($history): ?>
        <section class="cu-card">
            <p class="cu-eyebrow">Booking History</p>
            <ul class="bk-list">
                <?php foreach ($history as $a): ?>
                <li><span><?= e($a['service_name']) ?><small><?= e(date('D, M j, Y', strtotime((string)$a['appointment_date']))) ?> &middot; <?= e(date('g:i A', strtotime((string)$a['start_time']))) ?></small></span><span class="bk-state <?= e((string)$a['appointment_status']) ?>"><?= e(str_replace('_', ' ', (string)$a['appointment_status'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php';
