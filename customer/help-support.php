<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

$pdo = db();

$phone = cu_setting('contact_phone');
$mail = cu_setting('contact_email');
$arrive = (int)cu_setting('arrival_minutes_before', '15');
$notice = (int)cu_setting('cancellation_notice_hours', '6');
$extraFee = (float)cu_setting('extra_person_fee', '0');
$hours = cu_hours_text();
$addr = array_filter([cu_setting('address_line1'), cu_setting('address_line2'), cu_setting('address_line3')]);
$parking = cu_setting('parking_note');

$groupLines = [];
$methods = [];
try {
    foreach ($pdo->query('SELECT service_name, base_capacity, max_capacity FROM services WHERE max_capacity > 1 ORDER BY id') as $s) {
        $b = (int)$s['base_capacity']; $m = (int)$s['max_capacity'];
        $groupLines[] = $s['service_name'] . ' (' . ($b === $m ? $b . ' guests' : $b . ' guests, up to ' . $m) . ')';
    }
    $methods = $pdo->query("SELECT DISTINCT payment_method FROM payments WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {
}
$contactLine = trim(($phone !== '' ? 'call ' . $phone : '') . ($phone !== '' && $mail !== '' ? ' or ' : '') . ($mail !== '' ? 'email ' . $mail : ''));
$contactLine = $contactLine !== '' ? 'Please ' . $contactLine . '.' : 'Please contact the concierge.';

$faq = [
    'reservations' => [
        ['How can I book a sanctuary treatment?', 'Browse the treatments and rates on the Services & Rates page and choose the one you would like. Online scheduling is not available yet, so bookings are currently completed with the concierge. ' . $contactLine],
        ['How do I reschedule my appointment?', 'Please contact the concierge to move a reservation. Rescheduling with at least ' . $notice . ' hours\' notice is free of penalty. If the appointment starts in less than ' . $notice . ' hours, moving it forfeits any downpayment on file. ' . $contactLine],
        ['How can I cancel my reservation?', 'Please contact the concierge to cancel. If you cancel at least ' . $notice . ' hours before your appointment, any payment received is marked as refunded. A later cancellation forfeits the downpayment on file.'],
        ['Can I book for multiple guests?', $groupLines ? 'Yes. Group sessions available: ' . implode('; ', $groupLines) . '.' . ($extraFee > 0 ? ' Each extra guest beyond the base group size is charged ' . '₱' . number_format($extraFee) . '.' : '') : 'Please ask the concierge about group bookings.'],
    ],
    'payments' => [
        ['What payment methods are accepted?', $methods ? 'Payments recorded by the spa so far were made by ' . implode(' and ', $methods) . '. If you would like to use another method, please confirm with the concierge first.' : 'Please confirm available payment methods with the concierge.'],
        ['How do advance booking deposits work?', 'A downpayment may be recorded when a booking is made. Its amount is set per booking by the spa and the remaining balance is settled with the spa. A downpayment is refunded as marked on cancellations with ' . $notice . ' hours\' notice or more, and forfeited on later cancellations.'],
        ['Where can I view my payment history?', 'Online payment history is not available yet. The concierge can confirm your payments and balances on request. Your tranquility points history is available on the Loyalty & Rewards page.'],
        ['How can I update my personal information?', 'Profile editing is not available online yet. Please contact the concierge to update your name, email or phone number. ' . $contactLine],
    ],
    'wellness' => [
        ['What should I bring to my appointment?', 'Nothing special is required. Please be ready to share any health conditions, injuries or pregnancy so your therapist can adjust the treatment, and mention allergies and treatment preferences.'],
        ['What time should I arrive?', $arrive > 0 ? 'Please arrive about ' . $arrive . ' minutes before your scheduled treatment to allow time for check-in.' : 'Please arrive a little early to allow time for check-in.'],
        ['What is the cancellation policy?', 'Cancel at least ' . $notice . ' hours before your appointment and any payment received is marked as refunded. Cancelling or rescheduling inside ' . $notice . ' hours forfeits the downpayment on file.'],
        ['How can I contact the sanctuary concierge?', ($phone !== '' ? 'Call ' . $phone : '') . ($phone !== '' && $mail !== '' ? ' or email ' : ($mail !== '' ? 'Email ' : '')) . $mail . ($hours !== '' ? '. Spa hours: ' . $hours : '') . '.'],
    ],
];
$tabs = ['reservations' => 'Reservations', 'payments' => 'Payment & Profile', 'wellness' => 'Wellness & Policies'];

function hs_icon(string $n): string
{
    $p = [
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/>',
        'star' => '<path d="m12 3 2.700 5.500 6 .9-4.400 4.200 1 6-5.300-2.800L6.700 19.600l1-6L3.300 9.400l6-.9L12 3Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin' => '<path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.500"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4V5Z"/>',
        'check' => '<path d="m5 12.500 4.500 4.500L19 7.500"/>',
        'card' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>',
        'lotus' => '<path d="M12 20c-4 0-7-3-7-7 3 0 5.500 1 7 3 1.500-2 4-3 7-3 0 4-3 7-7 7Z"/><path d="M12 16c-2-2-2.500-6 0-10 2.500 4 2 8 0 10Z"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

$pageTitle = 'Help & Support';
$activeNav = 'help';
$pageStyles = ['landing-page.css', 'help-support.css'];
$pageScripts = ['help-support.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="lp hs">
    <section class="hs-hero">
        <div class="lp-wrap">
            <p class="lp-eyebrow pink">Contactless Concierge Gateway</p>
            <h1 class="serif">How May We Nurture Your <span class="grad-text">Sanctuary</span> Experience?</h1>
            <p class="lp-lead">Our dedicated concierge and wellness liaison team is here to ensure a seamless experience. Explore insightful guidance or connect directly with our sanctuary concierge.</p>
            <ul class="hs-pills">
                <li><span class="d purple"></span>Reservations &amp; Booking Support</li>
                <li><span class="d pink"></span>Payment Assistance</li>
                <li><span class="d purple"></span>Membership &amp; Wellness Guidance</li>
            </ul>
        </div>
    </section>

    <?php if ($flash): ?><div class="lp-wrap"><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div></div><?php endif; ?>

    <section class="hs-cards">
        <div class="lp-wrap hs-cards-grid">
            <article class="hs-card">
                <span class="i purple"><?= hs_icon('mail') ?></span>
                <p class="hs-cat purple">Contact &amp; Inquiries</p>
                <h2 class="serif">Email Concierge</h2>
                <p>Connect with our sanctuary concierge for personalized assistance and general inquiries.</p>
                <?php if ($mail !== ''): ?><div class="hs-info"><small>Email Address</small><b><?= e($mail) ?></b></div><?php endif; ?>
                <?php if ($mail !== ''): ?><a class="lp-btn lp-btn-purple" href="mailto:<?= e($mail) ?>">Reach Out</a><?php endif; ?>
            </article>
            <article class="hs-card">
                <span class="i pink"><?= hs_icon('calendar') ?></span>
                <p class="hs-cat pink">Booking &amp; Appointments</p>
                <h2 class="serif">Reservation Assistance</h2>
                <p>Need help with your upcoming treatments, appointment changes, or booking concerns?</p>
                <ul class="hs-list"><li>Booking assistance</li><li>Rescheduling</li><li>Cancellation guidance</li></ul>
                <a class="lp-btn lp-btn-pink" href="<?= e(cu_url('/dashboard.php')) ?>">View My Reservations</a>
            </article>
            <article class="hs-card">
                <span class="i gold"><?= hs_icon('star') ?></span>
                <p class="hs-cat gold">Membership &amp; Rewards</p>
                <h2 class="serif">Membership Assistance</h2>
                <p>Get assistance with your membership tier, tranquility points, and exclusive sanctuary privileges.</p>
                <ul class="hs-list"><li>Membership inquiries</li><li>Loyalty points</li><li>Reward redemption</li></ul>
                <a class="lp-btn lp-btn-purple" href="<?= e(cu_url('/loyalty-rewards.php')) ?>">Explore Membership Help</a>
            </article>
        </div>
    </section>

    <section class="lp-section" id="guidance">
        <div class="lp-wrap hs-guidance">
            <div class="hs-faq-col">
                <p class="lp-eyebrow">Knowledge &amp; Protocols</p>
                <h2 class="serif">Frequently Consulted Guidance</h2>
                <p class="hs-sub">Find answers to common questions and explore our sanctuary protocols before reaching out to our concierge team.</p>
                <div class="hs-tabs" role="tablist" aria-label="Question categories">
                    <?php $first = true; foreach ($tabs as $key => $label): ?>
                    <button type="button" role="tab" class="hs-tab<?= $first ? ' active' : '' ?>" data-tab="<?= e($key) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= e($label) ?></button>
                    <?php $first = false; endforeach; ?>
                </div>
                <div class="hs-faq">
                    <?php $n = 0; $firstGroup = true; foreach ($faq as $key => $items): foreach ($items as [$q, $a]): $n++; ?>
                    <div class="hs-item" data-group="<?= e($key) ?>"<?= $key !== 'reservations' ? ' hidden' : '' ?>>
                        <button type="button" class="hs-q" aria-expanded="false" aria-controls="hsA<?= $n ?>" id="hsQ<?= $n ?>"><span><?= e($q) ?></span><i aria-hidden="true"></i></button>
                        <div class="hs-a" id="hsA<?= $n ?>" role="region" aria-labelledby="hsQ<?= $n ?>"><div><p><?= e($a) ?></p></div></div>
                    </div>
                    <?php endforeach; endforeach; ?>
                </div>
            </div>
            <aside class="hs-feature">
                <img src="<?= e(cu_url('/assets/images/help-support.jpg')) ?>" alt="Relaxing spa treatment at Bella Vista Oasis" loading="lazy" onerror="this.onerror=null;this.src='<?= e(cu_url('/assets/images/hero-spa.jpg')) ?>'">
                <div class="hs-feature-body">
                    <p class="lp-eyebrow">Sanctuary Wellness</p>
                    <h3 class="serif">The Art of Personalized Relaxation</h3>
                    <p>Discover a more intentional wellness experience through thoughtfully curated sanctuary treatments.</p>
                    <ul class="hs-list tick"><li>Personalized spa treatments</li><li>Premium sanctuary experience</li><li>Relaxation and rejuvenation</li></ul>
                    <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/services.php')) ?>">Explore Our Services</a>
                </div>
            </aside>
        </div>
    </section>

    <section class="hs-strip">
        <div class="lp-wrap hs-strip-grid">
            <div><span class="i purple"><?= hs_icon('clock') ?></span><div><h3>Support Operating Hours</h3><p><strong>Concierge support</strong></p><?php $hrows = cu_hours_rows(); if ($hrows): ?><dl class="hs-hours"><?php foreach ($hrows as $hr): ?><div><dt><?= e($hr['days']) ?></dt><dd><?= e($hr['hours']) ?></dd></div><?php endforeach; ?></dl><?php else: ?><p>Please contact the concierge for hours.</p><?php endif; ?><?php if ($phone !== ''): ?><p><?= e($phone) ?></p><?php endif; ?></div></div>
            <div><span class="i pink"><?= hs_icon('chat') ?></span><div><h3>Reservation Guidance</h3><p>Booking assistance</p><p>Appointment-related inquiries</p><p>Rescheduling and cancellation guidance</p></div></div>
            <div><span class="i gold"><?= hs_icon('pin') ?></span><div><h3>Sanctuary Location</h3><?php foreach ($addr as $l): ?><p><?= e($l) ?></p><?php endforeach; ?><?php if ($mail !== ''): ?><p><?= e($mail) ?></p><?php endif; ?><?php if ($parking !== ''): ?><p class="muted"><?= e($parking) ?></p><?php endif; ?></div></div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php';