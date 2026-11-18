<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-auth-check.php';
require_once __DIR__ . '/includes/dashboard-data.php';

$pdo = db();
$cid = (int)$currentCustomer['id'];
$first = explode(' ', trim((string)$currentCustomer['full_name']))[0];

$member = cl_member($pdo, $cid);
$tiers = cl_tiers($pdo);
$tier = ($member && $member['tier_id'] && isset($tiers[$member['tier_id']])) ? $tiers[$member['tier_id']] : null;
$points = cl_balance($pdo, $cid);
$progress = cd_progress($pdo, $member, $cid);

$upcoming = cd_upcoming($pdo, $cid);
$next = $upcoming[0] ?? null;
$nextView = $next ? cd_view($next) : null;
$more = max(0, count($upcoming) - 1);
$cancelPol = $next ? bk_cancel_policy($pdo, $next) : null;
$reschedPol = $next ? bk_resched_policy($pdo, $next) : null;

$history = cd_history($pdo, $cid);
$historyViews = array_map('cd_view', $history);
$featured = cd_featured_service($pdo, $cid);
$phone = cu_setting('contact_phone');
$photoFile = basename((string)($currentCustomer['profile_photo'] ?? ''));
$photoUrl = $photoFile !== '' && is_file(__DIR__ . '/../Admin/assets/uploads/customers/' . $photoFile)
    ? ADMIN_URL . '/assets/uploads/customers/' . rawurlencode($photoFile) : '';

$images = ['SR' => 'treatment-single-room.jpg', 'CS' => 'treatment-couples-suite.jpg', 'FL' => 'treatment-family-lounge.jpg'];
$wide = ['SR' => 'facility-single-rooms.jpg', 'CS' => 'facility-couples-suites.jpg', 'FL' => 'facility-family-lounge.jpg'];

$pageTitle = 'My Sanctuary';
$activeNav = 'sanctuary';
$pageStyles = ['landing-page.css', 'dashboard.css'];
$pageScripts = ['dashboard.js'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
require __DIR__ . '/includes/header.php';
?>
<main class="lp cd">
    <?php if ($flash): ?><div class="lp-wrap cd-flash"><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div></div><?php endif; ?>

    <section class="cd-top">
        <div class="lp-wrap cd-top-grid">
            <article class="cd-card cd-welcome">
                <div class="cd-who">
                    <span class="cd-avatar" aria-hidden="true"><?php if ($photoUrl !== ''): ?><img src="<?= e($photoUrl) ?>" alt=""><?php else: ?><?= e(cd_initials((string)$currentCustomer['full_name'])) ?><?php endif; ?></span>
                    <div>
                        <p class="lp-eyebrow pink">Your Sanctuary</p>
                        <h1 class="serif">Welcome back to Serenity, <em><?= e($first) ?></em></h1>
                    </div>
                </div>
                <p class="cd-sub">Your personal sanctuary awaits. Explore your reservations, membership privileges, and wellness journey.</p>
                <div class="cd-chips">
                    <span class="cd-chip tier"><?= e($tier['tier_name'] ?? ($member ? ($member['membership_type'] ?: 'Member') : 'Guest')) ?><?= $member ? ' Member' : '' ?></span>
                    <span class="cd-chip">ID <b><?= e($currentCustomer['membership_code'] ?: 'Pending') ?></b></span>
                    <span class="cd-chip pts"><b><?= number_format($points) ?></b> points</span>
                </div>
                <div class="cd-progress-wrap">
                    <div class="cd-progress" role="progressbar" aria-valuenow="<?= (int)$progress['percent'] ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= (int)$progress['percent'] ?>%"></i></div>
                    <small><?= e($progress['text']) ?></small>
                </div>
                <div class="cd-actions">
                    <a class="lp-btn lp-btn-purple" href="<?= e(cu_url('/loyalty-rewards.php')) ?>">Explore Membership</a>
                    <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/services.php')) ?>">Book Another Treatment</a>
                    <button type="button" class="lp-btn lp-btn-line" data-profile>Update Profile</button>
                    <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/qr-checkin.php')) ?>">View Digital Pass</a>
                </div>
            </article>

            <article class="cd-card cd-next">
                <p class="lp-eyebrow">Upcoming Reservation</p>
                <?php if ($nextView): ?>
                    <div class="cd-next-head"><h2 class="serif"><?= e($nextView['service']) ?></h2><span class="cd-status <?= e($nextView['statusKey']) ?>"><?= e($nextView['status']) ?></span></div>
                    <dl class="cd-dl">
                        <div><dt>Date</dt><dd><?= e($nextView['date']) ?></dd></div>
                        <div><dt>Time</dt><dd><?= e($nextView['time']) ?></dd></div>
                        <div><dt>Therapist</dt><dd><?= e($nextView['therapist']) ?></dd></div>
                        <div><dt>Room</dt><dd><?= e($nextView['room']) ?></dd></div>
                        <div><dt>Payment</dt><dd><?= e($nextView['payment']) ?></dd></div>
                        <div><dt>Remaining Balance</dt><dd class="gold"><?= e($nextView['balance']) ?></dd></div>
                    </dl>
                    <div class="cd-actions tight">
                        <button type="button" class="lp-btn lp-btn-pink" data-view="<?= (int)$next['id'] ?>">View Reservation</button>
                        <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/qr-checkin.php?id=' . (int)$next['id'])) ?>">View QR Pass</a>
                    </div>
                <?php else: ?>
                    <h2 class="serif">No upcoming reservation</h2>
                    <p class="cd-empty">You have nothing booked right now. Browse our treatments and reserve your next moment of calm.</p>
                    <div class="cd-actions tight"><a class="lp-btn lp-btn-pink" href="<?= e(cu_url('/services.php')) ?>">Explore Treatments</a></div>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <?php if ($next): $img = $wide[$next['room_code']] ?? 'facility-single-rooms.jpg'; ?>
    <section class="cd-section" id="appointment">
        <div class="lp-wrap">
            <article class="cd-card cd-feature">
                <div class="cd-feature-img"><img src="<?= e(cu_url('/assets/images/' . $img)) ?>" alt="<?= e($next['service_name']) ?>"><span><?= e($next['service_name']) ?></span></div>
                <div class="cd-feature-body">
                    <div class="cd-next-head"><div><p class="lp-eyebrow pink">Featured Upcoming Appointment</p><h2 class="serif"><?= e($next['service_name']) ?></h2></div><span class="cd-status <?= e($nextView['statusKey']) ?>"><?= e($nextView['status']) ?></span></div>
                    <p class="cd-desc"><?= e($next['service_description']) ?></p>
                    <dl class="cd-dl cols">
                        <div><dt>Date &amp; Time</dt><dd><?= e($nextView['date']) ?> &middot; <?= e($nextView['time']) ?></dd></div>
                        <div><dt>Therapist</dt><dd><?= e($nextView['therapist']) ?></dd></div>
                        <div><dt>Room</dt><dd><?= e($nextView['room']) ?></dd></div>
                        <div><dt>Payment</dt><dd><?= e($nextView['payment']) ?> &middot; balance <?= e($nextView['balance']) ?></dd></div>
                    </dl>
                    <?php if ($more > 0): ?><p class="cd-more"><?= $more ?> more upcoming reservation<?= $more > 1 ? 's' : '' ?> after this one.</p><?php endif; ?>
                    <div class="cd-actions tight">
                        <button type="button" class="lp-btn lp-btn-purple" data-view="<?= (int)$next['id'] ?>">View Reservation Details</button>
                        <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/qr-checkin.php?id=' . (int)$next['id'])) ?>">View QR Check-in Pass</a>
                        <?php if ($nextView['canManage']): ?>
                        <button type="button" class="lp-btn lp-btn-line" data-reschedule="<?= (int)$next['id'] ?>">Reschedule Appointment</button>
                        <button type="button" class="lp-btn lp-btn-danger" data-cancel="<?= (int)$next['id'] ?>">Cancel Reservation</button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        </div>
    </section>
    <?php endif; ?>

    <section class="cd-section" id="history">
        <div class="lp-wrap">
            <article class="cd-card">
                <div class="cd-history-head">
                    <div><p class="lp-eyebrow">Your Journey</p><h2 class="serif">Recent Visits &amp; Experience History</h2></div>
                    <div class="cd-filters" role="group" aria-label="Filter visits">
                        <button type="button" class="active" data-filter="all">All Visits</button>
                        <button type="button" data-filter="completed">Completed</button>
                        <button type="button" data-filter="cancelled">Cancelled</button>
                    </div>
                </div>
                <?php if ($history): ?>
                <div class="cd-table-wrap">
                    <table class="cd-table">
                        <thead><tr><th>Treatment &amp; Date</th><th>Therapist</th><th>Amount Paid</th><th>Guest Rating</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $i => $h): $v = $historyViews[$i]; ?>
                            <tr data-status="<?= e($h['appointment_status']) ?>">
                                <td data-label="Treatment"><strong><?= e($h['service_name']) ?></strong><small><?= e($v['date']) ?> &middot; <span class="cd-status sm <?= e($v['statusKey']) ?>"><?= e($v['status']) ?></span></small></td>
                                <td data-label="Therapist"><?= e($v['therapist']) ?></td>
                                <td data-label="Paid" class="gold"><?= e($v['paid']) ?></td>
                                <td data-label="Rating" class="muted">Not rated</td>
                                <td data-label=""><button type="button" class="lp-btn lp-btn-line sm" data-view="<?= (int)$h['id'] ?>">View Details</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="cd-none" hidden>No visits match this filter.</p>
                </div>
                <?php else: ?><p class="cd-empty">Your visit history will appear here after your first appointment.</p><?php endif; ?>
            </article>
        </div>
    </section>

    <?php if ($featured): ?>
    <section class="cd-section" id="featured">
        <div class="lp-wrap">
            <article class="cd-card cd-reco">
                <div class="cd-reco-img"><img src="<?= e(cu_url('/assets/images/' . ($images[$featured['room_code']] ?? 'hero-spa.jpg'))) ?>" alt="<?= e($featured['service_name']) ?>" loading="lazy"></div>
                <div class="cd-reco-body">
                    <span class="cd-badge">Featured Treatment</span>
                    <h2 class="serif"><?= e($featured['service_name']) ?></h2>
                    <p><?= e($featured['description']) ?></p>
                    <div class="cd-reco-meta"><b><?= cu_peso((float)$featured['price']) ?></b><span><?= e(cu_duration_label((int)$featured['duration'])) ?></span></div>
                    <div class="cd-actions tight">
                        <a class="lp-btn lp-btn-purple" href="<?= e(cu_url('/book-appointment.php?service_id=' . (int)$featured['id'])) ?>">Book This Treatment</a>
                        <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/services.php')) ?>">View Treatment Details</a>
                    </div>
                </div>
            </article>
        </div>
    </section>
    <?php endif; ?>
</main>

<div class="cd-modal" id="cdModal" role="dialog" aria-modal="true" aria-labelledby="cdModalTitle" hidden>
    <div class="cd-modal-box">
        <button type="button" class="cd-x" data-close aria-label="Close">&times;</button>
        <h3 class="serif" id="cdModalTitle"></h3>
        <div id="cdModalBody"></div>
    </div>
</div>

<script type="application/json" id="cdData"><?= json_encode([
    'next' => $nextView, 'history' => $historyViews,
    'cancelMsg' => $cancelPol['message'] ?? '', 'reschedMsg' => $reschedPol['message'] ?? '',
    'csrf' => csrf_token(), 'action' => cu_url('/auth/appointment-process.php'), 'slots' => cu_url('/auth/slots.php'),
    'today' => date('Y-m-d'),
    'profileAction' => cu_url('/auth/profile-process.php'),
    'profile' => [
        'full_name' => (string)$currentCustomer['full_name'], 'email' => (string)$currentCustomer['email'],
        'phone' => (string)$currentCustomer['phone'], 'date_of_birth' => (string)($currentCustomer['date_of_birth'] ?? ''),
        'gender' => (string)($currentCustomer['gender'] ?? ''), 'address' => (string)($currentCustomer['address'] ?? ''),
        'photo' => $photoUrl, 'initials' => cd_initials((string)$currentCustomer['full_name']),
    ],
], $jsonFlags) ?></script>
<?php require __DIR__ . '/includes/footer.php';