<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

$pdo = db();
$loggedIn = cu_logged_in();
$firstName = $loggedIn ? explode(' ', trim((string)($_SESSION['customer_name'] ?? '')))[0] : '';

$services = [];
$facilities = [];
$tiers = [];
try {
    /* Featured treatments = the 3 most booked services (cancelled and no-show bookings do not count). */
    $services = $pdo->query("SELECT s.id, s.service_name, s.room_code, s.description, s.duration, s.price, s.base_capacity, s.max_capacity, COUNT(a.id) AS bookings
        FROM services s
        LEFT JOIN appointments a ON a.service_id = s.id AND a.appointment_status NOT IN ('cancelled', 'no_show')
        GROUP BY s.id, s.service_name, s.room_code, s.description, s.duration, s.price, s.base_capacity, s.max_capacity
        ORDER BY bookings DESC, s.id ASC LIMIT 3")->fetchAll();
    $facilities = $pdo->query("SELECT room_type, COUNT(*) AS total, MIN(description) AS description FROM rooms WHERE room_type IS NOT NULL GROUP BY room_type ORDER BY MIN(id)")->fetchAll();
    $tiers = $pdo->query("SELECT tier_name, discount_percentage, points_multiplier FROM membership_tiers WHERE status = 'active' ORDER BY sort_order, id")->fetchAll();
} catch (Throwable $ex) {
}

$phone = cu_setting('contact_phone');
$hoursText = cu_hours_text();
$gap = (int)cu_setting('cleaning_gap_minutes', '30');

$serviceImages = ['SR' => 'treatment-single-room.jpg', 'CS' => 'treatment-couples-suite.jpg', 'FL' => 'treatment-family-lounge.jpg'];
/* Photos uploaded in admin Room Management; the bundled images are only a fallback. */
$roomImages = room_images_by_code($pdo);
$typeImages = [];
try {
    foreach ($pdo->query("SELECT r.room_type, d.image_path FROM rooms r JOIN room_details d ON d.room_id = r.id WHERE d.image_path IS NOT NULL AND d.image_path <> '' AND r.room_type IS NOT NULL ORDER BY r.id")->fetchAll() as $ti) {
        if (!isset($typeImages[$ti['room_type']]) && is_file(ROOM_UPLOAD_DIR . '/' . basename((string)$ti['image_path']))) {
            $typeImages[$ti['room_type']] = (string)$ti['image_path'];
        }
    }
} catch (Throwable $ex) {
}
function facility_image(string $type): string
{
    $t = strtolower($type);
    if (str_contains($t, 'couple')) return 'facility-couples-suites.jpg';
    if (str_contains($t, 'barkada') || str_contains($t, 'family') || str_contains($t, 'lounge')) return 'facility-family-lounge.jpg';
    return 'facility-single-rooms.jpg';
}
function lp_icon(string $name): string
{
    $paths = [
        'leaf' => '<path d="M5 19c0-8 5-14 14-14 0 9-6 14-14 14Z"/><path d="M5 19 13 11"/>',
        'door' => '<rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="15" cy="12" r="1"/>',
        'hand' => '<path d="M8 13V6a1.5 1.5 0 0 1 3 0v5m0-6a1.5 1.5 0 0 1 3 0v6m0-4a1.5 1.5 0 0 1 3 0v7a6 6 0 0 1-6 6h-1a6 6 0 0 1-5-3l-2-3a1.500 1.500 0 0 1 2.500-1.500L8 14"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.500 3 7.500 7 9 4-1.500 7-4.500 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'star' => '<path d="m12 3 2.700 5.500 6 .9-4.400 4.200 1 6-5.300-2.800L6.700 19.600l1-6L3.300 9.400l6-.9L12 3Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'lotus' => '<path d="M12 20c-4 0-7-3-7-7 3 0 5.500 1 7 3 1.500-2 4-3 7-3 0 4-3 7-7 7Z"/><path d="M12 16c-2-2-2.500-6 0-10 2.500 4 2 8 0 10Z"/>',
        'pin' => '<path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.500"/>',
        'quote' => '<path d="M7 17c-2 0-3-1.500-3-4 0-3 2-5.500 5-6v2c-1.500.5-2.500 1.500-2.500 3H9v5H7Zm9 0c-2 0-3-1.500-3-4 0-3 2-5.500 5-6v2c-1.500.5-2.500 1.500-2.500 3H18v5h-2Z"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

$pageTitle = 'Discover Your Moment of Serenity';
$activeNav = 'home';
$pageStyles = ['landing-page.css'];
$pageScripts = ['landing-page.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="lp">
    <?php if ($flash): ?><div class="lp-wrap"><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div></div><?php endif; ?>

    <section class="lp-hero" id="home">
        <div class="lp-wrap lp-hero-grid">
            <div class="lp-hero-copy">
                <?php if ($loggedIn && $firstName !== ''): ?><p class="lp-welcome">Welcome back, <?= e($firstName) ?> &middot; <a href="<?= e(cu_url('/dashboard.php')) ?>">Open My Sanctuary</a></p><?php endif; ?>
                <p class="lp-eyebrow">Bella Vista Oasis Spa &amp; Sanctuary</p>
                <h1 class="serif">Discover Your Moment of <span class="grad-text">Serenity</span></h1>
                <p class="lp-lead">Experience a sanctuary of relaxation where personalized treatments, restorative rituals, and exceptional hospitality come together.</p>
                <div class="lp-actions">
                    <a class="lp-btn lp-btn-purple" href="<?= e(cu_url('/services.php')) ?>">Explore Our Treatments</a>
                    <a class="lp-btn lp-btn-pink" href="<?= e(cu_url('/book-appointment.php')) ?>">Book Your Experience</a>
                </div>
                <ul class="lp-trust">
                    <li><span class="i purple"><?= lp_icon('leaf') ?></span>Premium Wellness Treatments</li>
                    <li><span class="i pink"><?= lp_icon('hand') ?></span>Expert Therapists</li>
                    <li><span class="i purple"><?= lp_icon('door') ?></span>Private Sanctuary Suites</li>
                    <li><span class="i pink"><?= lp_icon('star') ?></span>Personalized Experiences</li>
                </ul>
            </div>
            <div class="lp-hero-media">
                <img src="<?= e(cu_url('/assets/images/hero-spa.jpg')) ?>" alt="Therapist giving a relaxing facial massage beside an aroma diffuser" width="736" height="1000">
                <div class="lp-float">
                    <span class="i pink"><?= lp_icon('lotus') ?></span>
                    <div>
                        <strong>Your private retreat</strong>
                        <p>Suites for one, two or a group<?= $hoursText !== '' ? ' &middot; Open ' . e($hoursText) : '' ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="lp-strip" aria-label="Highlights">
        <div class="lp-wrap lp-strip-grid">
            <div><span class="i purple"><?= lp_icon('leaf') ?></span><h3>Personalized Wellness Treatments</h3><p>Sessions matched to how you want to feel.</p></div>
            <div><span class="i pink"><?= lp_icon('door') ?></span><h3>Premium Sanctuary Suites</h3><p>Private rooms for singles, couples and groups.</p></div>
            <div><span class="i purple"><?= lp_icon('hand') ?></span><h3>Professional Therapists</h3><p>Attentive, dedicated care in every session.</p></div>
            <div><span class="i pink"><?= lp_icon('shield') ?></span><h3>Relaxing Private Environment</h3><p>Calm, quiet spaces away from the everyday.</p></div>
        </div>
    </section>

    <section class="lp-section" id="treatments">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Most Booked Treatments</p>
                <h2 class="serif">Restorative Treatments &amp; Signature Rituals</h2>
                <p>Discover thoughtfully curated treatments designed to restore balance, comfort, and well-being.</p>
            </header>
            <?php if ($services): ?>
            <div class="lp-cards">
                <?php foreach ($services as $i => $s):
                    $imgUrl = isset($roomImages[$s['room_code']]) ? (string)room_image_url($roomImages[$s['room_code']]) : cu_url('/assets/images/' . ($serviceImages[$s['room_code']] ?? 'hero-spa.jpg'));
                    $alt = $i % 2 === 1; ?>
                <article class="lp-card">
                    <div class="lp-card-img"><img src="<?= e($imgUrl) ?>" alt="<?= e($s['service_name']) ?>" loading="lazy"><?php if ((int)$s['bookings'] > 0): ?><span class="lp-rank">#<?= $i + 1 ?> Most Booked</span><?php endif; ?></div>
                    <div class="lp-card-body">
                        <h3 class="serif"><?= e($s['service_name']) ?></h3>
                        <p class="lp-card-desc"><?= e($s['description']) ?></p>
                        <div class="lp-details" hidden>
                            <p>Suitable for <?= (int)$s['base_capacity'] === (int)$s['max_capacity'] ? (int)$s['base_capacity'] . ' guest' . ((int)$s['base_capacity'] > 1 ? 's' : '') : (int)$s['base_capacity'] . ' to ' . (int)$s['max_capacity'] . ' guests' ?>.</p>
                        </div>
                        <div class="lp-meta">
                            <span class="lp-dur"><?= lp_icon('clock') ?><?= e(cu_duration_label((int)$s['duration'])) ?></span>
                            <span class="lp-price">&#8369;<?= number_format((float)$s['price']) ?></span>
                        </div>
                        <div class="lp-card-actions">
                            <button type="button" class="lp-btn lp-btn-line" data-toggle-details aria-expanded="false">View Details</button>
                            <a class="lp-btn <?= $alt ? 'lp-btn-pink' : 'lp-btn-purple' ?>" href="<?= e(cu_url('/book-appointment.php?service_id=' . (int)$s['id'])) ?>">Book Now</a>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="lp-empty">Our treatment menu is being updated. Please check back soon.</p><?php endif; ?>
        </div>
    </section>

    <section class="lp-section lp-wellness" id="wellness">
        <div class="lp-wrap lp-split">
            <div class="lp-collage">
                <img class="c1" src="<?= e(cu_url('/assets/images/wellness-collage-1.jpg')) ?>" alt="Guest relaxing during a facial massage" loading="lazy">
                <img class="c2" src="<?= e(cu_url('/assets/images/wellness-collage-2.jpg')) ?>" alt="Candles, frangipani and herbal compress" loading="lazy">
                <div class="c3"><span class="i purple"><?= lp_icon('lotus') ?></span><p>Slow down. Breathe in. Let the sanctuary do the rest.</p></div>
            </div>
            <div class="lp-wellness-copy">
                <p class="lp-eyebrow">Wellness at Bella Vista</p>
                <h2 class="serif">Rediscover Balance Through Personalized Wellness</h2>
                <p class="lp-lead">Experience a more thoughtful approach to relaxation through restorative treatments, carefully selected wellness rituals, and personalized sanctuary experiences.</p>
                <p>Each visit is paced around you: a private room, an unhurried session and a team that pays attention to how you feel.</p>
                <ul class="lp-benefits">
                    <li><span class="i purple"><?= lp_icon('door') ?></span>Private rooms for every session</li>
                    <li><span class="i pink"><?= lp_icon('shield') ?></span><?= $gap > 0 ? e((string)$gap) . '-minute cleaning gap between sessions' : 'Rooms prepared between sessions' ?></li>
                    <li><span class="i purple"><?= lp_icon('star') ?></span>Loyalty points on completed visits</li>
                </ul>
                <a class="lp-btn lp-btn-purple" href="#treatments" data-scroll>Explore Wellness</a>
            </div>
        </div>
    </section>

    <section class="lp-section" id="spaces">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow">Our Spaces</p>
                <h2 class="serif">Spa Facilities &amp; Sanctuary Spaces</h2>
                <p>Explore our carefully designed spaces for relaxation, comfort, and complete rejuvenation.</p>
            </header>
            <?php if ($facilities): $n = count($facilities); ?>
            <div class="lp-gallery">
                <?php foreach ($facilities as $i => $f):
                    $span = $i === 0 ? 7 : ($i === 1 ? 5 : intdiv(12, max(1, $n - 2)));
                    $accent = $i % 2 === 0 ? 'purple' : 'pink'; ?>
                <figure class="lp-g <?= $accent ?>" style="--span: <?= $span ?>">
                    <img src="<?= e(isset($typeImages[$f['room_type']]) ? (string)room_image_url($typeImages[$f['room_type']]) : cu_url('/assets/images/' . facility_image((string)$f['room_type']))) ?>" alt="<?= e($f['room_type']) ?>" loading="lazy">
                    <figcaption>
                        <span class="tag"><?= (int)$f['total'] ?> <?= (int)$f['total'] === 1 ? 'room' : 'rooms' ?></span>
                        <h3 class="serif"><?= e($f['room_type']) ?></h3>
                        <p><?= e($f['description']) ?></p>
                    </figcaption>
                </figure>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="lp-section lp-testimonials" id="reviews">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Guest Voices</p>
                <h2 class="serif">Praised for Our Sanctuary Experience</h2>
                <p class="lp-demo-note">Sample content for layout purposes. Approved guest reviews will appear here once a reviews feature is added.</p>
            </header>
            <div class="lp-quotes">
                <?php
                $demo = [
                    ['The room was quiet and beautifully prepared. I left feeling completely refreshed.', 'Sample Guest A'],
                    ['Warm, attentive service from the moment I arrived. A truly calming visit.', 'Sample Guest B'],
                    ['A perfect way to slow down after a long week. I would happily return.', 'Sample Guest C'],
                ];
                foreach ($demo as $i => [$text, $who]): ?>
                <blockquote class="lp-quote">
                    <span class="i <?= $i % 2 ? 'pink' : 'purple' ?>"><?= lp_icon('quote') ?></span>
                    <div class="stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <p><?= e($text) ?></p>
                    <footer><strong><?= e($who) ?></strong><em>Demo content</em></footer>
                </blockquote>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="lp-section lp-promos">
        <div class="lp-wrap lp-promo-grid">
            <article class="lp-promo lp-promo-purple" id="membership">
                <p class="lp-eyebrow gold">Sanctuary Membership</p>
                <h2 class="serif">Experience More with Sanctuary Membership</h2>
                <p>Enjoy exclusive wellness privileges, loyalty benefits, and personalized experiences.</p>
                <?php if ($tiers): ?>
                <ul class="lp-tiers">
                    <?php foreach ($tiers as $t): ?>
                    <li><strong><?= e($t['tier_name']) ?></strong><span><?= rtrim(rtrim(number_format((float)$t['discount_percentage'], 2), '0'), '.') ?>% off &middot; <?= rtrim(rtrim(number_format((float)$t['points_multiplier'], 2), '0'), '.') ?>x points</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <a class="lp-btn lp-btn-white" href="<?= e($loggedIn ? cu_url('/loyalty-rewards.php') : cu_url('/register.php')) ?>">Explore Membership</a>
            </article>
            <article class="lp-promo lp-promo-blush" id="reserve">
                <p class="lp-eyebrow pink">Reservations</p>
                <h2 class="serif">Your Sanctuary Awaits</h2>
                <p>Reserve your preferred treatment and discover your next moment of relaxation.</p>
                <?php if ($hoursText !== ''): ?><p class="lp-promo-hours"><span class="i pink"><?= lp_icon('clock') ?></span>Open <?= e($hoursText) ?></p><?php endif; ?>
                <a class="lp-btn lp-btn-pink" href="<?= e(cu_url('/book-appointment.php')) ?>">Book an Appointment</a>
            </article>
        </div>
    </section>

    <section class="lp-final">
        <div class="lp-wrap">
            <span class="i pink big"><?= lp_icon('lotus') ?></span>
            <h2 class="serif">Your Sanctuary Awaits</h2>
            <p>Take a moment for yourself. Discover the restorative experience waiting at Bella Vista Oasis.</p>
            <div class="lp-actions center">
                <a class="lp-btn lp-btn-purple" href="<?= e(cu_url('/book-appointment.php')) ?>">Book Your Experience</a>
                <a class="lp-btn lp-btn-white-line" href="<?= e(cu_url('/services.php')) ?>">Explore Our Treatments</a>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php';