<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';

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

$st = $pdo->prepare("SELECT a.appointment_date, a.start_time, a.end_time, a.appointment_status, s.service_name FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.customer_id = ? AND a.appointment_date >= CURDATE() AND a.appointment_status IN ('pending', 'confirmed') ORDER BY a.appointment_date, a.start_time LIMIT 5");
$st->execute([$cid]);
$upcoming = $st->fetchAll();

$pageTitle = 'My Sanctuary';
$activeNav = 'loyalty';
$pageScripts = [];
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
            <ul class="cu-ledger">
                <?php foreach ($upcoming as $a): ?>
                <li><span><?= e($a['service_name']) ?><small><?= e(date('D, M j', strtotime((string)$a['appointment_date']))) ?> &middot; <?= e(date('g:i A', strtotime((string)$a['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime((string)$a['end_time']))) ?></small></span><b class="pos"><?= e(ucfirst((string)$a['appointment_status'])) ?></b></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><p class="cu-note">You have no upcoming bookings.</p><?php endif; ?>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php';
