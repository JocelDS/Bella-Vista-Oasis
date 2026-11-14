<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

$pdo = db();
$loggedIn = cu_logged_in();

$services = [];
$rooms = [];
$addons = [];
try {
    $services = $pdo->query('SELECT id, service_name, room_code, description, duration, price, base_capacity, max_capacity FROM services ORDER BY id')->fetchAll();
    foreach ($pdo->query("SELECT SUBSTRING_INDEX(room_name, '-', 1) AS code, MIN(room_type) AS room_type, COUNT(*) AS total, MIN(description) AS description FROM rooms GROUP BY code") as $r) {
        $rooms[$r['code']] = $r;
    }
} catch (Throwable $ex) {
}
$roomList = [];
$imgCol = room_images_ready($pdo) ? ', d.image_path' : ', NULL AS image_path';
try {
    $roomList = $pdo->query("SELECT r.id, r.room_name, r.room_type, r.room_status, r.description, d.amenities, d.maximum_capacity" . $imgCol . ", (SELECT p.rate FROM room_pricing p WHERE p.room_id = r.id ORDER BY p.updated_at DESC, p.id DESC LIMIT 1) AS rate FROM rooms r LEFT JOIN room_details d ON d.room_id = r.id ORDER BY r.id")->fetchAll();
} catch (Throwable $ex) {
    $roomList = [];
}
try {
    $addons = $pdo->query("SELECT name, description, price FROM service_addons WHERE status = 'active' ORDER BY sort_order, id")->fetchAll();
} catch (Throwable $ex) {
    $addons = [];
}

$extraFee = (float)cu_setting('extra_person_fee', '0');
$gap = (int)cu_setting('cleaning_gap_minutes', '0');
$arrive = (int)cu_setting('arrival_minutes_before', '0');
$notice = (int)cu_setting('cancellation_notice_hours', '0');
$note = cu_setting('housekeeping_note');
$phone = cu_setting('contact_phone');
$images = ['SR' => 'treatment-single-room.jpg', 'CS' => 'treatment-couples-suite.jpg', 'FL' => 'treatment-family-lounge.jpg'];
$roomImages = room_images_by_code($pdo);
/* Photo uploaded in admin Room Management first, bundled default image as fallback. */
function sv_photo(string $code, array $uploaded, array $defaults, string $fallback = 'hero-spa.jpg'): string
{
    if (isset($uploaded[$code])) return (string)room_image_url($uploaded[$code]);
    return cu_url('/assets/images/' . ($defaults[$code] ?? $fallback));
}

function sv_icon(string $name): string
{
    $p = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users' => '<circle cx="9" cy="8" r="3.500"/><path d="M2.500 20a6.500 6.500 0 0 1 13 0M16 4.500a3.500 3.500 0 0 1 0 7M18 14a6.500 6.500 0 0 1 3.500 6"/>',
        'check' => '<path d="m5 12.500 4.500 4.500L19 7.500"/>',
        'door' => '<rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="15" cy="12" r="1"/>',
        'hand' => '<path d="M8 13V6a1.500 1.500 0 0 1 3 0v5m0-6a1.500 1.500 0 0 1 3 0v6m0-4a1.500 1.500 0 0 1 3 0v7a6 6 0 0 1-6 6h-1a6 6 0 0 1-5-3l-2-3a1.500 1.500 0 0 1 2.500-1.500L8 14"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.500 3 7.500 7 9 4-1.500 7-4.500 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
        'lotus' => '<path d="M12 20c-4 0-7-3-7-7 3 0 5.500 1 7 3 1.500-2 4-3 7-3 0 4-3 7-7 7Z"/><path d="M12 16c-2-2-2.500-6 0-10 2.500 4 2 8 0 10Z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/>',
        'x' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
        'heart' => '<path d="M12 20s-7-4.500-7-10a4 4 0 0 1 7-2.500A4 4 0 0 1 19 10c0 5.500-7 10-7 10Z"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
function sv_guests(array $s): string
{
    $b = (int)$s['base_capacity'];
    $m = (int)$s['max_capacity'];
    if ($b === $m) return $b . ($b === 1 ? ' guest' : ' guests');
    return $b . ' guests (up to ' . $m . ')';
}
function sv_book_url(int $id): string { return cu_url('/book-appointment.php?service_id=' . $id); }

$pageTitle = 'Services & Rates';
$activeNav = 'services';
$pageStyles = ['landing-page.css', 'services.css', 'services-responsive.css'];
$pageScripts = ['services.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="lp sv">
    <section class="sv-intro">
        <div class="lp-wrap sv-intro-grid">
            <div>
                <p class="lp-eyebrow">Bella Vista Oasis Spa &amp; Sanctuary</p>
                <h1 class="serif">Signature Spa Treatments &amp; <span class="grad-text">Sanctuary Rates</span></h1>
                <p class="lp-lead">Thoughtfully designed treatments and restorative rituals, carefully curated to bring balance, relaxation, and exceptional wellness experiences.</p>
                <p class="sv-note">Every treatment below is booked by private room, with the duration and rate shown. Choose a session, then sign in to continue to booking.</p>
            </div>
            <ul class="sv-indicators">
                <li><span class="i purple"><?= sv_icon('clock') ?></span><div><strong>Treatment Duration</strong><small>Clearly shown on every session</small></div></li>
                <li><span class="i pink"><?= sv_icon('hand') ?></span><div><strong>Professional Therapists</strong><small>Dedicated care in every room</small></div></li>
                <li><span class="i purple"><?= sv_icon('door') ?></span><div><strong>Private Sanctuary Suites</strong><small>Rooms for one, two or a group</small></div></li>
            </ul>
        </div>
    </section>

    <section class="lp-section sv-treatments" id="treatments">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Featured Spa Treatments</p>
                <h2 class="serif">Choose Your Sanctuary Experience</h2>
                <p>Rates and durations are taken directly from our current treatment menu.</p>
            </header>
            <?php if ($services): ?>
            <div class="sv-grid">
                <?php foreach ($services as $i => $s):
                    $code = (string)$s['room_code'];
                    $room = $rooms[$code] ?? null;
                    $accent = $i % 2 === 0 ? 'purple' : 'pink';
                    $extra = ((int)$s['max_capacity'] > (int)$s['base_capacity'] && $extraFee > 0); ?>
                <article class="lp-card sv-card" data-service="<?= (int)$s['id'] ?>">
                    <div class="lp-card-img"><img src="<?= e(sv_photo($code, $roomImages, $images)) ?>" alt="<?= e($s['service_name']) ?>" loading="lazy"></div>
                    <div class="lp-card-body">
                        <span class="sv-cat <?= $accent ?>"><?= e($room['room_type'] ?? 'Sanctuary Treatment') ?></span>
                        <h3 class="serif"><?= e($s['service_name']) ?></h3>
                        <p class="sv-desc"><?= e($s['description']) ?></p>
                        <div class="sv-price-row">
                            <span class="lp-price"><?= cu_peso((float)$s['price']) ?></span>
                            <span class="lp-dur"><?= sv_icon('clock') ?><?= e(cu_duration_label((int)$s['duration'])) ?></span>
                        </div>
                        <ul class="sv-features">
                            <li><?= sv_icon('users') ?>For <?= e(sv_guests($s)) ?></li>
                            <?php if ($room): ?><li><?= sv_icon('door') ?>Private <?= e(strtolower((string)$room['room_type'])) ?>, <?= (int)$room['total'] ?> available</li><?php endif; ?>
                            <?php if ($extra): ?><li><?= sv_icon('plus') ?>Extra guest: <?= cu_peso($extraFee) ?> each beyond <?= (int)$s['base_capacity'] ?></li><?php endif; ?>
                            <?php if ($gap > 0): ?><li><?= sv_icon('shield') ?><?= $gap ?>-minute room preparation between guests</li><?php endif; ?>
                        </ul>
                        <?php if ($room && trim((string)$room['description']) !== ''): ?><p class="sv-info"><?= e($room['description']) ?></p><?php endif; ?>
                        <a class="lp-btn sv-book <?= $accent === 'purple' ? 'lp-btn-purple' : 'lp-btn-pink' ?>" href="<?= e(sv_book_url((int)$s['id'])) ?>" data-book>Book <?= e($s['service_name']) ?></a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="lp-empty">Our treatment menu is being updated. Please check back soon.</p><?php endif; ?>
        </div>
    </section>

    <section class="lp-section" id="rooms">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Our Sanctuary</p>
                <h2 class="serif">Rooms &amp; Suites</h2>
                <p>Every private room in our sanctuary, with its rate, capacity and amenities.</p>
            </header>
            <?php if ($roomList): ?>
            <div class="sv-rooms">
                <?php foreach ($roomList as $i => $rm):
                    $code = strtoupper(explode('-', (string)$rm['room_name'])[0]);
                    $img = (!empty($rm['image_path']) && is_file(ROOM_UPLOAD_DIR . '/' . basename((string)$rm['image_path'])))
                        ? (string)room_image_url((string)$rm['image_path'])
                        : (isset($images[$code]) ? cu_url('/assets/images/' . $images[$code]) : null);
                    $open = strtolower((string)$rm['room_status']) !== 'maintenance';
                    $amen = array_filter(array_map('trim', explode(',', (string)($rm['amenities'] ?? ''))));
                ?>
                <article class="sv-room">
                    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($rm['room_name']) ?>" loading="lazy"><?php endif; ?>
                    <div class="sv-room-body">
                        <div class="sv-room-top"><h3 class="serif"><?= e($rm['room_name']) ?></h3><span class="sv-room-status <?= $open ? 'on' : 'off' ?>"><?= $open ? 'Open for booking' : 'Unavailable' ?></span></div>
                        <small class="sv-room-type"><?= e((string)$rm['room_type']) ?></small>
                        <?php if (trim((string)$rm['description']) !== ''): ?><p><?= e($rm['description']) ?></p><?php endif; ?>
                        <?php if ($amen): ?><div class="sv-room-tags"><?php foreach ($amen as $a): ?><span><?= e($a) ?></span><?php endforeach; ?></div><?php endif; ?>
                        <div class="sv-room-foot">
                            <?php if ($rm['maximum_capacity'] !== null): ?><span><?= sv_icon('users') ?>Up to <?= (int)$rm['maximum_capacity'] ?> guest<?= (int)$rm['maximum_capacity'] === 1 ? '' : 's' ?></span><?php endif; ?>
                            <?php if ($rm['rate'] !== null): ?><b><?= cu_peso((float)$rm['rate']) ?></b><?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="lp-empty">Our rooms are being updated. Please check back soon.</p><?php endif; ?>
        </div>
    </section>

    <section class="lp-section" id="addons">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow">Enhancements</p>
                <h2 class="serif">Spa Ritual Add-ons</h2>
                <p>Enhance your sanctuary experience with carefully selected wellness additions.</p>
            </header>
            <?php
            $cards = [];
            if ($extraFee > 0 && array_filter($services, fn($s) => (int)$s['max_capacity'] > (int)$s['base_capacity'])) {
                $cards[] = ['name' => 'Extra Guest', 'description' => 'Add another guest to a group lounge session, up to the room maximum.', 'price' => $extraFee, 'unit' => 'per person'];
            }
            foreach ($addons as $a) $cards[] = ['name' => $a['name'], 'description' => $a['description'], 'price' => (float)$a['price'], 'unit' => ''];
            ?>
            <?php if ($cards): ?>
            <div class="sv-addons">
                <?php foreach ($cards as $i => $a): ?>
                <div class="sv-addon">
                    <span class="i <?= $i % 2 ? 'pink' : 'purple' ?>"><?= sv_icon($i % 2 ? 'heart' : 'lotus') ?></span>
                    <h3><?= e($a['name']) ?></h3>
                    <p><?= e((string)$a['description']) ?></p>
                    <div class="sv-addon-foot"><b>+<?= cu_peso($a['price']) ?></b><?php if ($a['unit']): ?><small><?= e($a['unit']) ?></small><?php endif; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!$addons): ?>
            <p class="sv-addon-empty">More ritual add-ons will be listed here once the spa confirms them. Ask the concierge about enhancements<?= $phone !== '' ? ' at ' . e($phone) : '' ?>.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="sv-policies" id="policies">
        <div class="lp-wrap">
            <header class="sv-pol-head">
                <p class="lp-eyebrow gold">Please Note</p>
                <h2 class="serif">Spa Etiquette &amp; Sanctuary Policies</h2>
            </header>
            <div class="sv-pol-grid">
                <article><span class="i"><?= sv_icon('calendar') ?></span><h3>Appointment Preparation</h3>
                    <ul><li>Arrive early before your scheduled treatment<?= $arrive > 0 ? ', about ' . $arrive . ' minutes' : '' ?>.</li><li>Allow time for check-in.</li></ul></article>
                <article><span class="i pink"><?= sv_icon('x') ?></span><h3>Cancellation Policy</h3>
                    <ul><?php if ($notice > 0): ?><li>Please cancel at least <?= $notice ?> hours before your session.</li><?php else: ?><li>Please contact the concierge to change or cancel a booking.</li><?php endif; ?><li>Late changes may not be possible.</li></ul></article>
                <article><span class="i"><?= sv_icon('shield') ?></span><h3>Wellness &amp; Safety</h3>
                    <ul><li>Tell your therapist about allergies and treatment preferences.</li><li>Follow spa safety instructions.</li><?php if ($note !== ''): ?><li><?= e($note) ?></li><?php endif; ?></ul></article>
                <article><span class="i pink"><?= sv_icon('lotus') ?></span><h3>Private Sanctuary Experience</h3>
                    <ul><li>Respect the privacy and comfort of other guests.</li><li>Follow the facility guidelines.</li></ul></article>
            </div>
        </div>
    </section>

    <section class="lp-section" id="overview">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Pricing Guide</p>
                <h2 class="serif">Sanctuary Experience Overview</h2>
            </header>
            <?php if ($services): ?>
            <div class="sv-table-wrap">
                <table class="sv-table">
                    <thead><tr><th>Treatment</th><th>Duration</th><th>Price</th><th>Experience Details</th><th>Booking</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td data-label="Treatment"><strong><?= e($s['service_name']) ?></strong><small><?= e($rooms[$s['room_code']]['room_type'] ?? '') ?></small></td>
                            <td data-label="Duration"><?= e(cu_duration_label((int)$s['duration'])) ?></td>
                            <td data-label="Price" class="sv-t-price"><?= cu_peso((float)$s['price']) ?></td>
                            <td data-label="Details"><?= e($s['description']) ?></td>
                            <td data-label=""><a class="lp-btn lp-btn-line" href="<?= e(sv_book_url((int)$s['id'])) ?>" data-book>Book Now</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php';