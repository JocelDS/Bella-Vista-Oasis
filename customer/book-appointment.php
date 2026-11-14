<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';
require_once __DIR__ . '/includes/booking-bridge.php';

$serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

if (!cu_logged_in()) {
    $_SESSION['cu_return'] = $serviceId > 0 ? '/book-appointment.php?service_id=' . $serviceId : '/book-appointment.php';
    cu_flash('success', 'Please sign in to book an appointment.');
    cu_redirect('/login.php');
}

$pdo = db();
cu_booking_ready($pdo);

$st = $pdo->prepare('SELECT id, full_name, email, phone, membership_code FROM customers WHERE id = ?');
$st->execute([(int)$_SESSION['customer_id']]);
$me = $st->fetch();
if (!$me) cu_redirect('/login.php');

$member   = cu_booking_member($pdo, (int)$me['id']);
$services = cu_service_options($pdo);

$roomRows = [];
foreach ($pdo->query('SELECT id, room_name, room_type, room_status FROM rooms ORDER BY id')->fetchAll() as $r) {
    $r['code'] = bk_room_code((string)$r['room_name']);
    $r['bookable'] = !in_array(strtolower((string)$r['room_status']), ['maintenance', 'cleaning'], true);
    $roomRows[] = $r;
}
$therapists = $pdo->query("SELECT id, full_name, specialization FROM therapists WHERE availability_status = 'available' ORDER BY full_name")->fetchAll();

$hours  = cu_hours_text();
$gap    = spa_cleaning_gap($pdo);
$notice = (int)cu_setting('cancellation_notice_hours', '6');
$arrive = (int)cu_setting('arrival_minutes_before', '15');

$oldService = (int)cu_old('service_id');
$initialService = isset($services[$oldService]) ? $oldService : (isset($services[$serviceId]) ? $serviceId : 0);
$initial = [
    'service'   => $initialService,
    'guests'    => cu_old('guests'),
    'room'      => cu_old('room_id'),
    'therapist' => cu_old('therapist_id'),
    'date'      => cu_old('date'),
    'start'     => cu_old('start'),
];

$_SESSION['bk_token'] = bin2hex(random_bytes(16));

$pageTitle = 'Book Appointment';
$activeNav = 'book';
$pageStyles = ['booking.css', 'booking-responsive.css'];
$pageScripts = ['booking-validation.js', 'booking-summary.js', 'booking-steps.js', 'booking.js'];
require __DIR__ . '/includes/header.php';

$tierName = $member ? (string)$member['tier'] : '';
$memberOk = $member && $member['active'];
$pct = $memberOk ? (float)$member['discount_pct'] : 0.0;
?>
<main class="bk">
    <section class="bk-intro">
        <div>
            <p class="bk-eyebrow"><span>Rituals &amp; Haven Booking</span></p>
            <h1 class="bk-title">Schedule Your Sanctuary Session</h1>
            <p class="bk-lead">Immerse in exceptional botanical rituals and bespoke restorative therapies. Experience your sanctuary with personalized care and exceptional wellness.<?php if ($hours !== ''): ?> Operating <?= e($hours) ?><?php if ($gap > 0): ?>, with a thorough <?= (int)$gap ?>-minute medical-grade chamber sanitation between appointments<?php endif; ?>.<?php endif; ?></p>
        </div>
        <aside class="bk-sanit" aria-label="Sanctuary sanitation standard">
            <span class="bk-sanit-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.4-3 8.2-7 10-4-1.8-7-5.6-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg></span>
            <div>
                <p class="bk-label-sm">Sanctuary Sanitation Standard</p>
                <p class="bk-sanit-text"><?php if ($gap > 0): ?><?= (int)$gap ?>-minute chamber sanitation and preparation between every guest.<?php else: ?>Every chamber is prepared and sanitized between guests.<?php endif; ?> Please arrive <?= $arrive ?> minutes early.</p>
            </div>
        </aside>
    </section>

    <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?> bk-alert" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <ol class="bk-steps" id="bkSteps" aria-label="Booking progress">
        <li><button type="button" data-go="1"><span class="bk-dot">1</span><span class="bk-step-txt"><small>Step 01</small><b>Guest Identification</b></span></button></li>
        <li><button type="button" data-go="2"><span class="bk-dot">2</span><span class="bk-step-txt"><small>Step 02</small><b>Chamber &amp; Guests</b></span></button></li>
        <li><button type="button" data-go="3"><span class="bk-dot">3</span><span class="bk-step-txt"><small>Step 03</small><b>Slot Serialization</b></span></button></li>
        <li><button type="button" data-go="4"><span class="bk-dot">4</span><span class="bk-step-txt"><small>Step 04</small><b>Declaration &amp; Waiver</b></span></button></li>
        <span class="bk-progress" id="bkProgress" aria-hidden="true"></span>
    </ol>

    <form id="bookingForm" method="post" action="<?= e(cu_url('/auth/booking-process.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="request_token" value="<?= e($_SESSION['bk_token']) ?>">
        <input type="hidden" name="date" id="bkDate" value="<?= e(cu_old('date')) ?>">
        <input type="hidden" name="start" id="bkStart" value="<?= e(cu_old('start')) ?>">
        <script type="application/json" id="bkInit"><?= json_encode([
            'endpoint' => cu_url('/api/slots.php'),
            'initial'  => $initial,
            'discount' => $pct,
            'tier'     => $memberOk ? $tierName : '',
            'windowDays' => CU_BOOKING_WINDOW_DAYS,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

        <div class="bk-layout">
            <div class="bk-main">

                <!-- STEP 1 -->
                <section class="bk-card" data-step="1" aria-labelledby="st1">
                    <p class="bk-label-sm">Guest Details</p>
                    <h2 class="bk-h2" id="st1">Guest &amp; Contact Identification</h2>
                    <?php if ($member): ?>
                    <div class="bk-member <?= $memberOk ? 'ok' : 'off' ?>">
                        <span class="bk-gem" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M6 4h12l3 5-9 11L3 9z"/><path d="M3 9h18M9 4l3 16M15 4l-3 16"/></svg></span>
                        <div>
                            <b><?= e($tierName) ?> Membership <?= $memberOk ? 'Verified' : 'Expired' ?> <?php if ($memberOk && $pct > 0): ?><span class="bk-vip"><?= e(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% off</span><?php endif; ?></b>
                            <p><?php if ($memberOk && $pct > 0): ?>Enjoy <?= e(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% loyalty privileges, automatically calculated at final checkout.<?php elseif ($memberOk): ?>Your membership is active. Privileges are applied where they are configured.<?php else: ?>Your membership has expired, so no member discount is applied. Visit the spa to renew.<?php endif; ?></p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bk-member off">
                        <span class="bk-gem" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg></span>
                        <div><b>Regular Guest</b><p>No membership was found for your account, so you are booking as a regular guest. No member discount is applied.</p></div>
                    </div>
                    <?php endif; ?>
                    <div class="bk-grid2">
                        <div class="bk-field"><label for="gName">Full Legal Name</label><input id="gName" type="text" value="<?= e($me['full_name']) ?>" readonly></div>
                        <div class="bk-field"><label for="gPhone">Contact Telephone</label><input id="gPhone" type="tel" value="<?= e($me['phone']) ?>" readonly></div>
                        <div class="bk-field"><label for="gEmail">Electronic Mail Address</label><input id="gEmail" type="email" value="<?= e($me['email']) ?>" readonly></div>
                        <div class="bk-field"><label for="gMember">Membership ID</label><input id="gMember" type="text" value="<?= e((string)($me['membership_code'] ?? '')) ?>" readonly><?php if ($member && !empty($me['membership_code'])): ?><span class="bk-verified" title="Found in the membership records"><?= $memberOk ? '&#10003;' : '!' ?></span><?php endif; ?></div>
                    </div>
                    <p class="bk-note">These details come from your account. To change them, please contact the spa.</p>
                    <p class="bk-msg" data-msg="1" aria-live="polite"></p>
                    <div class="bk-actions"><button type="button" class="bk-btn bk-btn-primary" data-next>Continue to Service Selection &rarr;</button></div>
                </section>

                <!-- STEP 2 -->
                <section class="bk-card" data-step="2" aria-labelledby="st2" hidden>
                    <p class="bk-label-sm">Treatment &amp; Chamber</p>
                    <h2 class="bk-h2" id="st2">Chamber &amp; Guest Selection</h2>
                    <div class="bk-svcs" role="radiogroup" aria-label="Treatment">
                        <?php foreach ($services as $s): ?>
                        <label class="bk-svc">
                            <input type="radio" name="service_id" value="<?= (int)$s['id'] ?>" required
                                   data-name="<?= e($s['service_name']) ?>" data-price="<?= e((string)$s['price']) ?>" data-extra="<?= e((string)$s['extra_fee']) ?>"
                                   data-base="<?= (int)$s['base_guests'] ?>" data-min="<?= (int)$s['min_guests'] ?>" data-max="<?= (int)$s['max_guests'] ?>"
                                   data-duration="<?= (int)$s['duration'] ?>" data-code="<?= e(strtoupper((string)$s['room_code'])) ?>" data-durlabel="<?= e(cu_duration_label((int)$s['duration'])) ?>">
                            <span class="bk-svc-in">
                                <span class="bk-svc-top"><b><?= e($s['service_name']) ?></b><em><?= cu_peso((float)$s['price']) ?></em></span>
                                <small><?= e($s['description']) ?></small>
                                <span class="bk-svc-meta"><?= e(cu_duration_label((int)$s['duration'])) ?> &middot; <?= (int)$s['min_guests'] === (int)$s['max_guests'] ? (int)$s['min_guests'] . ' guest' . ((int)$s['min_guests'] === 1 ? '' : 's') : (int)$s['min_guests'] . '–' . (int)$s['max_guests'] . ' guests' ?><?php if ((float)$s['extra_fee'] > 0): ?> &middot; <?= cu_peso((float)$s['extra_fee']) ?> per extra guest<?php endif; ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="bk-grid2">
                        <div class="bk-field"><label for="bkGuests">Number of Guests</label><select id="bkGuests" name="guests"></select></div>
                        <div class="bk-field"><label for="bkRoom">Preferred Chamber</label>
                            <select id="bkRoom" name="room_id">
                                <option value="">Best available chamber</option>
                                <?php foreach ($roomRows as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" data-code="<?= e($r['code']) ?>" <?= $r['bookable'] ? '' : 'disabled' ?>><?= e($r['room_name']) ?> &middot; <?= e((string)$r['room_type']) ?><?= $r['bookable'] ? '' : ' (unavailable)' ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="bk-field bk-span2"><label for="bkTherapist">Preferred Therapist</label>
                            <select id="bkTherapist" name="therapist_id">
                                <option value="">Any Master Therapist</option>
                                <?php foreach ($therapists as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?> &middot; <?= e((string)$t['specialization']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                    </div>
                    <p class="bk-note">A preferred chamber or therapist is assigned when free for your slot. Otherwise the next open one is used, and a therapist who is busy or on leave is declined when you confirm.</p>
                    <p class="bk-msg" data-msg="2" aria-live="polite"></p>
                    <div class="bk-actions"><button type="button" class="bk-btn bk-btn-line" data-prev>&larr; Back</button><button type="button" class="bk-btn bk-btn-primary" data-next>Continue to Scheduling &rarr;</button></div>
                </section>

                <!-- STEP 3 -->
                <section class="bk-card" data-step="3" aria-labelledby="st3" hidden>
                    <p class="bk-label-sm">Availability</p>
                    <h2 class="bk-h2" id="st3">Select Your Sanctuary Schedule</h2>
                    <div class="bk-dates" id="bkDates" aria-label="Quick dates"></div>
                    <div class="bk-field bk-datepick"><label for="bkDatePick">Or pick any date</label><input type="date" id="bkDatePick" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+' . CU_BOOKING_WINDOW_DAYS . ' days'))) ?>"></div>
                    <p class="bk-msg" id="bkDateMsg" aria-live="polite"></p>
                    <div class="bk-slots" id="bkSlots" aria-live="polite"><p class="bk-hint">Choose a treatment, then a date to see the available times.</p></div>
                    <ul class="bk-legend" aria-hidden="true"><li><i class="a"></i>Available</li><li><i class="s"></i>Selected</li><li><i class="u"></i>Unavailable</li><li><i class="f"></i>Fully booked</li></ul>
                    <p class="bk-msg" data-msg="3" aria-live="polite"></p>
                    <div class="bk-actions"><button type="button" class="bk-btn bk-btn-line" data-prev>&larr; Back</button><button type="button" class="bk-btn bk-btn-primary" data-next>Continue to Declaration &rarr;</button></div>
                </section>

                <!-- STEP 4 -->
                <section class="bk-card" data-step="4" aria-labelledby="st4" hidden>
                    <p class="bk-label-sm">Consent</p>
                    <h2 class="bk-h2" id="st4">Guest Declaration &amp; Wellness Waiver</h2>
                    <div class="bk-grid2">
                        <div class="bk-field"><label for="hc">Medical conditions (type None if none)</label><input id="hc" type="text" name="health_conditions" maxlength="500" value="<?= e(cu_old('health_conditions')) ?>"></div>
                        <div class="bk-field"><label for="hi">Injuries (type None if none)</label><input id="hi" type="text" name="health_injuries" maxlength="500" value="<?= e(cu_old('health_injuries')) ?>"></div>
                    </div>
                    <fieldset class="bk-radio"><legend>Is any guest pregnant?</legend>
                        <label><input type="radio" name="is_pregnant" value="0" <?= cu_old('is_pregnant') === '0' ? 'checked' : '' ?>> No</label>
                        <label><input type="radio" name="is_pregnant" value="1" <?= cu_old('is_pregnant') === '1' ? 'checked' : '' ?>> Yes</label>
                    </fieldset>
                    <p class="bk-note">Waiver version <?= e(CU_WAIVER_VERSION) ?>. Your acceptance is recorded with the date and time of your booking.</p>
                    <div class="bk-checks">
                        <label><input type="checkbox" name="c1" value="1"> <span>I confirm that the information I provided is accurate.</span></label>
                        <label><input type="checkbox" name="c2" value="1"> <span>I understand the spa appointment and cancellation policies (free cancellation up to <?= $notice ?> hours before the session).</span></label>
                        <label><input type="checkbox" name="c3" value="1"> <span>I acknowledge that I must disclose relevant allergies and wellness considerations.</span></label>
                        <label><input type="checkbox" name="c4" value="1"> <span>I agree to the Bella Vista Oasis Terms &amp; Conditions.</span></label>
                        <label><input type="checkbox" name="c5" value="1"> <span>I consent to the applicable wellness and treatment declaration.</span></label>
                    </div>
                    <p class="bk-msg" data-msg="4" aria-live="polite"></p>
                    <div class="bk-actions"><button type="button" class="bk-btn bk-btn-line" data-prev>&larr; Back</button></div>
                </section>

                <div class="bk-promos">
                    <article class="bk-promo"><img src="<?= e(cu_url('/assets/images/wellness-collage-1.jpg')) ?>" alt="" loading="lazy"><div><small>Hydrotherapy Chamber</small><h3>Mineral Immersion Bath Included</h3><p>Discover a relaxing sanctuary experience designed to complement your wellness journey.</p></div></article>
                    <article class="bk-promo"><img src="<?= e(cu_url('/assets/images/wellness-collage-2.jpg')) ?>" alt="" loading="lazy"><div><small>Botanical Ritual</small><h3>Complimentary Herbal Welcoming</h3><p>Enjoy a thoughtful welcome ritual that creates a relaxing beginning to your sanctuary visit.</p></div></article>
                </div>
            </div>

            <aside class="bk-side">
                <section class="bk-card bk-summary" aria-labelledby="sumTitle">
                    <p class="bk-label-sm">Reservation Overview</p>
                    <h2 class="bk-h2" id="sumTitle">Live Booking Summary</h2>
                    <dl class="bk-dl">
                        <div><dt>Reserved Date</dt><dd id="sumDate">—</dd></div>
                        <div><dt>Window Slot</dt><dd id="sumTime">—</dd></div>
                        <div><dt>Master Practitioner</dt><dd id="sumTherapist">Any Master Therapist</dd></div>
                        <div><dt>Chamber</dt><dd id="sumRoom">Best available</dd></div>
                        <div><dt>Guests</dt><dd id="sumGuests">—</dd></div>
                    </dl>
                    <div class="bk-price">
                        <div class="bk-row"><span id="sumSvc">Select a treatment</span><b id="sumBase">—</b></div>
                        <div class="bk-row bk-sub" id="sumExtraRow" hidden><span id="sumExtraTxt">Additional guest surcharge</span><b id="sumExtra"></b></div>
                        <div class="bk-row bk-disc" id="sumDiscRow" hidden><span id="sumDiscTxt">Membership privilege</span><b id="sumDisc"></b></div>
                        <div class="bk-row bk-subtotal"><span>Subtotal</span><b id="sumSubtotal">—</b></div>
                    </div>
                    <div class="bk-total"><span>Total Payable:</span><b id="sumTotal">₱0</b></div>
                    <p class="bk-fine">Final amount is calculated again by the spa when you confirm.</p>
                    <button type="submit" class="bk-btn bk-btn-primary bk-confirm" id="bkConfirm" form="bookingForm">Confirm Appointment &amp; Generate QR Pass</button>
                    <p class="bk-fine">Saved as <strong>pending</strong> until the spa confirms. No payment is taken online.</p>
                </section>
                <aside class="bk-remind"><b>Retreat Etiquette Reminder</b><p>Please arrive at your scheduled appointment time. Guests are encouraged to communicate allergies, wellness considerations, and treatment preferences before their session.</p></aside>
            </aside>
        </div>
    </form>

    <div class="bk-modal" id="bkModal" role="dialog" aria-modal="true" aria-labelledby="bkModalTitle" hidden>
        <div class="bk-modal-card">
            <h3 class="bk-h2" id="bkModalTitle">Confirm your appointment?</h3>
            <p id="bkModalText"></p>
            <div class="bk-actions"><button type="button" class="bk-btn bk-btn-line" id="bkModalNo">Review again</button><button type="button" class="bk-btn bk-btn-primary" id="bkModalYes">Yes, confirm</button></div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php';