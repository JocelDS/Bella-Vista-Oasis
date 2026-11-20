<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/qr-pass.php';

$pdo = db();
$cid = (int)$currentCustomer['id'];

/* The guest's upcoming bookings, plus any one of their own bookings opened with ?id= */
$upcoming = cu_qr_bookings($pdo, $cid);
$wanted = (int)($_GET['id'] ?? 0);
if ($wanted < 1 && $upcoming) $wanted = (int)$upcoming[0]['id'];
$a = $wanted > 0 ? qrp_load($pdo, $cid, $wanted) : null;
if ($wanted > 0 && !$a) {
    cu_flash('error', 'That reservation could not be found.');
    cu_redirect('/qr-checkin.php');
}

$pageTitle = 'QR Check-In';
$activeNav = 'qr';
$pageStyles = ['qr-checkin.css', 'r-checkin-responsive.css'];
$pageScripts = ['qr-checkin.js'];

$phone = cu_setting('contact_phone', '');
$email = cu_setting('contact_email', '');
$notice = (int)cu_setting('cancellation_notice_hours', '6');
$hours = cu_hours_text();
$addr = qrp_address();
$valet = cu_setting('parking_note', '');
$clean = cu_setting('housekeeping_note', '');
$gap = spa_cleaning_gap($pdo);

$pass = null; $win = null; $matrix = null; $member = null;
if ($a) {
    $pass = qrp_pass($pdo, $a);
    $win = qrp_window($pdo, $a);
    $member = cu_booking_member($pdo, $cid);
    if ($pass['state'] === 'active') $matrix = qrp_matrix($pass['payload']);
}
$status = $a ? (string)$a['appointment_status'] : '';
$statusLabel = ucfirst(str_replace('_', ' ', $status));
$statusClass = in_array($status, ['confirmed', 'completed'], true) ? 'ok' : (in_array($status, ['cancelled', 'no_show'], true) ? 'bad' : 'wait');
$memberOk = $member && $member['active'];
$ref = $a ? qrp_ref((int)$a['id']) : '';

require __DIR__ . '/includes/header.php';
?>
<main class="qp">
    <section class="qp-intro">
        <div>
            <p class="qp-eyebrow"><span>Contactless Concierge Gateway</span></p>
            <h1 class="qp-title">Expedited Sanctuary Check-in</h1>
            <p class="qp-lead">Your verified digital appointment pass. Present your QR code to our reception upon arrival for seamless sanctuary recognition.</p>
        </div>
        <?php if ($a): ?>
        <div class="qp-chips">
            <span class="qp-chip"><i aria-hidden="true">&#9719;</i> Arrival: <b><?= e($win['opens']->format('g:i A')) ?> – <?= e($win['closes']->format('g:i A')) ?></b></span>
            <span class="qp-chip qp-<?= e($statusClass) ?>"><i aria-hidden="true"><?= $statusClass === 'ok' ? '&#10003;' : ($statusClass === 'bad' ? '&times;' : '&#8226;') ?></i> <?= $status === 'confirmed' ? 'Verified Reservation' : 'Reservation' ?> &middot; <b><?= e($statusLabel) ?></b></span>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?> qp-alert" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if (!$a): ?>
    <section class="qp-card qp-empty">
        <h2 class="qp-h2">No upcoming reservation</h2>
        <p>You have no upcoming bookings, so there is no QR pass to show yet.</p>
        <a class="qp-btn qp-btn-primary" href="<?= e(cu_url('/book-appointment.php')) ?>">Book an Appointment</a>
    </section>
    <?php else: ?>

    <?php if (count($upcoming) > 1 || ($upcoming && (int)$upcoming[0]['id'] !== (int)$a['id'])): ?>
    <nav class="qp-picker" aria-label="Your reservations">
        <?php foreach ($upcoming as $u): ?>
        <a href="<?= e(cu_url('/qr-checkin.php?id=' . (int)$u['id'])) ?>" class="<?= (int)$u['id'] === (int)$a['id'] ? 'on' : '' ?>"><b><?= e(qrp_ref((int)$u['id'])) ?></b><span><?= e(date('D, M j', strtotime((string)$u['appointment_date']))) ?> &middot; <?= e(date('g:i A', strtotime((string)$u['start_time']))) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="qp-layout">
        <div class="qp-left">
            <article class="qp-card qp-pass">
                <div class="qp-pass-top">
                    <span class="qp-badge"><?= $pass['state'] === 'active' ? 'Verified Appointment' . ($memberOk ? ' &amp; Membership' : '') : ($status === 'cancelled' ? 'Reservation Cancelled' : 'Appointment ' . e($statusLabel)) ?></span>
                    <button type="button" class="qp-ref" id="qpCopy" data-ref="<?= e($ref) ?>" title="Copy reference">Booking Ref: <b><?= e($ref) ?></b> <span aria-hidden="true">&#10064;</span></button>
                </div>
                <div class="qp-pass-body">
                    <div class="qp-qr">
                        <?php if ($pass['state'] === 'active'): ?>
                            <?= qrp_svg($matrix, 'QR code for reservation ' . $ref) ?>
                            <small>Valid until <?= e($pass['expires_label']) ?></small>
                        <?php else: ?>
                            <div class="qp-qr-off" data-state="<?= e($pass['state']) ?>"><span aria-hidden="true">&#9638;</span><p><?= e($pass['message']) ?></p></div>
                        <?php endif; ?>
                    </div>
                    <div class="qp-holder">
                        <p class="qp-label">Appointment Holder</p>
                        <h2 class="qp-name"><?= e($a['customer']) ?></h2>
                        <?php if ($memberOk): ?><span class="qp-member"><?= e(strtoupper((string)$member['tier'])) ?> MEMBER</span><?php endif; ?>
                        <p class="qp-label qp-gap">Reserved Ritual</p>
                        <p class="qp-svc"><?= e($a['service_name']) ?></p>
                        <ul class="qp-facts">
                            <li><?= e(cu_duration_label((int)$a['duration'])) ?></li>
                            <li><?= (int)$a['guest_count'] ?> <?= (int)$a['guest_count'] === 1 ? 'Guest' : 'Guests' ?></li>
                            <?php if (!empty($a['room_name'])): ?><li><?= e($a['room_name']) ?></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
                <p class="qp-present">Present your QR code to our reception upon arrival. It must be shown for reservation verification.</p>
                <div class="qp-trio">
                    <div><p class="qp-label">Schedule</p><b><?= e(date('D, M j, Y', strtotime((string)$a['appointment_date']))) ?></b><span><?= e(date('g:i A', strtotime((string)$a['start_time']))) ?> – <?= e(date('g:i A', strtotime((string)$a['end_time']))) ?></span></div>
                    <div><p class="qp-label">Reserved Suite</p><b><?= e((string)($a['room_name'] ?? 'To be assigned')) ?></b><span class="qp-st qp-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
                    <div><p class="qp-label">Lead Therapist</p><b><?= e((string)($a['therapist'] ?? 'Any Master Therapist')) ?></b><span><?= e((string)($a['specialization'] ?? 'Assigned by the spa')) ?></span></div>
                </div>
                <div class="qp-remind">Please arrive <?= (int)$win['arrive'] ?> minutes before your scheduled appointment (by <b><?= e($win['by']->format('g:i A')) ?></b>). Present this digital pass to our reception for contactless verification.</div>
                <div class="qp-actions">
                    <?php if ($pass['state'] === 'active'): ?>
                    <a class="qp-btn qp-btn-line" data-busy href="<?= e(cu_url('/qr-pass-download.php?type=pdf&id=' . (int)$a['id'])) ?>">Download PDF Pass</a>
                    <?php else: ?>
                    <span class="qp-btn qp-btn-line is-off" aria-disabled="true" title="Available once your QR pass is active">Download PDF Pass</span>
                    <?php endif; ?>
                    <?php if (!in_array($status, ['cancelled', 'no_show'], true)): ?>
                    <a class="qp-btn qp-btn-line" data-busy href="<?= e(cu_url('/qr-pass-download.php?type=ics&id=' . (int)$a['id'])) ?>">Add to Calendar</a>
                    <?php endif; ?>
                </div>
                <?php if ($pass['state'] === 'active'): ?><p class="qp-fine">Opening this page on another device or after signing in again issues a new pass and replaces the old one.</p><?php endif; ?>
            </article>

            <article class="qp-card qp-banner">
                <img src="<?= e(cu_url('/assets/images/wellness-collage-1.png')) ?>" alt="" loading="lazy">
                <div>
                    <p class="qp-label"><?= $memberOk && $member['perks'] ? 'Your member privileges' : 'Sanctuary care' ?></p>
                    <?php if ($memberOk && $member['perks']): ?>
                        <h3 class="qp-h3"><?= e((string)$member['tier']) ?> Member Benefits</h3>
                        <ul class="qp-perks"><?php foreach ($member['perks'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
                    <?php else: ?>
                        <h3 class="qp-h3">Your Sanctuary Awaits</h3>
                        <p><?= e($clean !== '' ? $clean : 'Your suite is prepared and sanitized before every guest.') ?></p>
                    <?php endif; ?>
                </div>
            </article>

            <section class="qp-card">
                <h2 class="qp-h3">Amendment &amp; Cancellation Policy</h2>
                <ul class="qp-policy">
                    <li><b>Cancellation deadline.</b> Cancel at least <?= $notice ?> hours before your start time.</li>
                    <li><b>With enough notice.</b> Any payment received is marked as refunded.</li>
                    <li><b>Late cancellation.</b> Under <?= $notice ?> hours, any downpayment on file is forfeited.</li>
                    <li><b>Changes.</b> To change your date or time, contact the concierge or cancel and book again. Changes inside <?= $notice ?> hours count as late.</li>
                </ul>
                <p class="qp-fine">Questions about deposits or refunds? Contact the concierge<?= $phone !== '' ? ' at ' . e($phone) : '' ?>.</p>
            </section>
        </div>

        <aside class="qp-right">
            <section class="qp-card">
                <div class="qp-card-head"><h2 class="qp-h3">Reservation Dossier &amp; Summary</h2><span class="qp-st qp-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
                <dl class="qp-dl">
                    <div><dt>Status</dt><dd><?= e($statusLabel) ?></dd></div>
                    <div><dt>Treatment</dt><dd><?= e($a['service_name']) ?> (<?= e(cu_duration_label((int)$a['duration'])) ?>)</dd></div>
                    <div><dt>Party</dt><dd><?= e($a['customer']) ?> &amp; <?= (int)$a['guest_count'] ?> <?= (int)$a['guest_count'] === 1 ? 'guest' : 'guests' ?></dd></div>
                    <?php if ((float)$a['membership_discount'] > 0): ?><div><dt>Member discount</dt><dd>−<?= cu_peso((float)$a['membership_discount']) ?></dd></div><?php endif; ?>
                    <div><dt>Total treatment fee</dt><dd class="qp-gold"><?= cu_peso((float)$a['total_amount']) ?></dd></div>
                    <div><dt>Payment</dt><dd><?= e(ucfirst((string)$a['payment_status'])) ?></dd></div>
                    <div><dt>Accessible suite</dt><dd><?= e((string)($a['room_name'] ?? 'To be assigned')) ?><?= !empty($a['therapist']) ? ' &middot; ' . e($a['therapist']) : '' ?></dd></div>
                </dl>
            </section>

            <section class="qp-card">
                <h2 class="qp-h3">Sanctuary Arrival Protocol</h2>
                <p class="qp-p">Please arrive <?= (int)$win['arrive'] ?> minutes before your appointment. Present your QR reservation pass at reception for verification and follow the concierge's instructions.</p>
                <div class="qp-window">
                    <p class="qp-label">Check-in window</p>
                    <b>Opens <?= e($win['opens']->format('g:i A')) ?> &middot; Arrive by <?= e($win['by']->format('g:i A')) ?> &middot; Closes <?= e($win['closes']->format('g:i A')) ?></b>
                    <span>On <?= e($win['start']->format('M j')) ?>. Arriving outside this window may need assistance from reception.</span>
                </div>
                <p class="qp-label qp-gap">Check-in sequence</p>
                <ol class="qp-seq">
                    <li><span>1</span>Arrive at the concierge<?= !empty($addr[0]) ? ', ' . e($addr[0]) : '' ?></li>
                    <li><span>2</span>Present your QR code so reception can verify your reservation</li>
                    <li><span>3</span>Follow the concierge to <?= !empty($a['room_name']) ? 'suite ' . e($a['room_name']) : 'your suite' ?></li>
                </ol>
            </section>

            <section class="qp-card">
                <h2 class="qp-h3">Sanctuary Concierge &amp; Location</h2>
                <dl class="qp-loc">
                    <?php if ($addr): ?><div><dt>Address</dt><dd><?= e(implode(', ', $addr)) ?></dd></div><?php endif; ?>
                    <?php if ($phone !== ''): ?><div><dt>Concierge hotline</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></dd></div><?php endif; ?>
                    <?php if ($valet !== ''): ?><div><dt>Valet &amp; arrival</dt><dd><?= e($valet) ?></dd></div><?php endif; ?>
                </dl>
            </section>
        </aside>
    </div>

    <section class="qp-strip" aria-label="Good to know">
        <?php if ($valet !== ''): ?><div class="qp-card"><span aria-hidden="true">&#9962;</span><div><b>Valet &amp; Arrival</b><p><?= e($valet) ?></p></div></div><?php endif; ?>
        <?php if ($gap > 0 || $clean !== ''): ?><div class="qp-card"><span aria-hidden="true">&#10024;</span><div><b>Chamber Sanitation</b><p><?= $gap > 0 ? (int)$gap . '-minute preparation between guests. ' : '' ?><?= e($clean) ?></p></div></div><?php endif; ?>
        <?php if ($phone !== '' || $email !== ''): ?><div class="qp-card"><span aria-hidden="true">&#9743;</span><div><b>Concierge</b><p><?= e($phone) ?><?= $email !== '' ? '<br>' . e($email) : '' ?></p></div></div><?php endif; ?>
    </section>
    <?php endif; ?>

</main>
<?php require __DIR__ . '/includes/footer.php';