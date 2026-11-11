<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/dashboard-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo       = db();
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$adminId   = (int)$_SESSION['admin_id'];
$loadError = null;

try {
    $stats         = get_dashboard_stats($pdo, $today, $yesterday);
    $trend         = get_week_trend($pdo, $today);
    $dist          = get_distribution($pdo);
    $rooms         = get_rooms($pdo);
    $announcements = get_announcements($pdo);
    $notifications = get_notifications($pdo, $adminId);
    $schedule      = fetch_schedule($pdo, $today, 'all', '', 1);
    $options       = get_booking_options($pdo);
} catch (Throwable $e) {
    error_log('[BVO dashboard] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load dashboard data: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fullName = (string)($_SESSION['admin_name'] ?? 'Administrator');
$parts    = preg_split('/\s+/', trim($fullName));
$first    = $parts[0] !== '' ? $parts[0] : 'Administrator';

$t = $stats['today'];
$y = $stats['yesterday'];
$confirmedPct = $t['all'] > 0 ? round($t['confirmed'] / $t['all'] * 100) : 0;
$completedPct = $t['all'] > 0 ? round($t['completed'] / $t['all'] * 100) : 0;
[$dAllCls, $dAllTxt]   = dash_delta((int)$t['all'], (int)$y['all'], 'vs yesterday');
[$dConfCls, $dConfTxt] = dash_delta((int)$t['confirmed'], (int)$y['confirmed'], 'vs yesterday');
[$dCompCls, $dCompTxt] = dash_delta((int)$t['completed'], (int)$y['completed'], 'vs yesterday');

$roomsBooked = max(0, $stats['rooms_total'] - $stats['rooms_available']);

$summary = $t['all'] . ' treatment' . ($t['all'] === 1 ? '' : 's') . ' scheduled today'
    . ' · ' . $stats['pending_upcoming'] . ' awaiting confirmation'
    . ' · ' . $stats['rooms_available'] . ' of ' . $stats['rooms_total'] . ' suites available';

$statusLabels = dash_status_map();
$chartPayload = [
    'labels' => $trend['labels'],
    'full'   => $trend['full'],
    'values' => $trend['values'],
];

$pageTitle = 'Dashboard Overview';
$crumb     = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome">
    <div class="welcome-text">
        <p class="status-pill"><span class="dot"></span> All Systems Operational</p>
        <h1><?= e($greeting) ?>, <?= e($first) ?>.<br>Sanctuary is <em>operating smoothly.</em></h1>
        <p class="welcome-date"><?= e(date('l, F j, Y')) ?></p>
        <p class="welcome-sum"><?= e($summary) ?></p>
    </div>
    <div class="welcome-actions">
        <?php $hasQr = is_file(__DIR__ . '/qr-checkin.php'); ?>
        <a class="btn btn-soft" href="<?= e(url('/api/export-manifest.php')) ?>" download>
            <?= icon('download') ?><span>Download Manifest</span>
        </a>
        <a class="btn btn-soft <?= $hasQr ? '' : 'is-soon' ?>" href="<?= $hasQr ? e(url('/qr-checkin.php')) : '#' ?>" <?= $hasQr ? '' : 'data-soon="QR Check-in"' ?>>
            <?= icon('qr') ?><span>Scan Guest QR</span>
        </a>
        <button type="button" class="btn btn-primary" id="openBooking" aria-haspopup="dialog">
            <?= icon('plus') ?><span>Quick Booking</span>
        </button>
    </div>
</section>

<section class="stats" aria-label="Key statistics">
    <article class="stat">
        <div class="stat-top"><span class="label-small">Total Appointments</span><span class="stat-ico"><?= icon('calendar') ?></span></div>
        <p class="stat-value"><?= (int)$t['all'] ?></p>
        <p class="stat-note delta-<?= e($dAllCls) ?>"><?= e($dAllTxt) ?></p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Confirmed Bookings</span><span class="stat-ico"><?= icon('check') ?></span></div>
        <p class="stat-value"><?= (int)$t['confirmed'] ?></p>
        <p class="stat-note delta-<?= e($dConfCls) ?>"><?= e($dConfTxt) ?> · <?= (int)$confirmedPct ?>% of today</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Completed Sessions</span><span class="stat-ico"><?= icon('check-ring') ?></span></div>
        <p class="stat-value"><?= (int)$t['completed'] ?></p>
        <p class="stat-note delta-<?= e($dCompCls) ?>"><?= e($dCompTxt) ?> · <?= (int)$completedPct ?>% of today</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Pending Requests</span><span class="stat-ico"><?= icon('clock') ?></span></div>
        <p class="stat-value"><?= (int)$stats['pending_upcoming'] ?></p>
        <p class="stat-note"><?= (int)$t['pending'] ?> scheduled for today</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Available Suites</span><span class="stat-ico"><?= icon('door') ?></span></div>
        <p class="stat-value"><?= (int)$stats['rooms_available'] ?></p>
        <p class="stat-note">of <?= (int)$stats['rooms_total'] ?> total suites</p>
    </article>
    <article class="stat stat-revenue">
        <div class="stat-top"><span class="label-small">Total Revenue</span><span class="stat-ico"><?= icon('wallet') ?></span></div>
        <p class="stat-value stat-money"><?= e(peso($stats['revenue_total'])) ?></p>
        <p class="stat-note"><?= e(peso($stats['revenue_today'])) ?> collected today</p>
    </article>
</section>

<div class="grid-2">
    <div class="col">
        <section class="card" aria-labelledby="flowTitle">
            <header class="card-head">
                <div>
                    <p class="label-small">Last 7 Days</p>
                    <h2 id="flowTitle" class="card-title">Intake Telemetry &amp; Flow Rhythm</h2>
                    <p class="card-sub">Daily appointment totals across the past seven days.</p>
                </div>
                <div class="chip-total"><span><?= (int)$trend['total'] ?></span> total</div>
            </header>
            <div class="chart-box">
                <canvas id="flowChart" role="img" aria-label="Line chart of daily appointments for the last seven days" data-chart="<?= e((string)json_encode($chartPayload)) ?>"></canvas>
            </div>
            <dl class="mini-stats">
                <div><dt>Daily Average</dt><dd><?= e(number_format((float)$trend['average'], 1)) ?></dd></div>
                <div><dt>Peak Day</dt><dd><?= e($trend['peak']) ?><?= $trend['peak_n'] > 0 ? ' · ' . (int)$trend['peak_n'] : '' ?></dd></div>
                <div><dt>Total Members</dt><dd><?= (int)$stats['members'] ?> active</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="distTitle">
            <header class="card-head">
                <div>
                    <p class="label-small">All Appointments</p>
                    <h2 id="distTitle" class="card-title">Sanctuary Appointment Distribution</h2>
                </div>
                <div class="chip-total"><span><?= (int)$dist['total'] ?></span> total</div>
            </header>
            <?php if ($dist['total'] === 0): ?>
                <p class="empty">No appointment records yet.</p>
            <?php else: ?>
                <div class="seg-bar" role="img" aria-label="Appointment status distribution">
                    <?php foreach ($dist['segments'] as $s): if ($s['count'] < 1) continue; ?>
                        <span class="seg seg-<?= e($s['key']) ?>" style="width: <?= e((string)$s['pct']) ?>%" title="<?= e($s['label'] . ': ' . $s['count'] . ' (' . $s['pct'] . '%)') ?>"></span>
                    <?php endforeach; ?>
                </div>
                <ul class="seg-legend">
                    <?php foreach ($dist['segments'] as $s): ?>
                        <li>
                            <span class="swatch seg-<?= e($s['key']) ?>"></span>
                            <span class="seg-label"><?= e($s['label']) ?></span>
                            <span class="seg-count"><?= (int)$s['count'] ?></span>
                            <span class="seg-pct"><?= e(number_format((float)$s['pct'], 1)) ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="col">
        <section class="card" aria-labelledby="suiteTitle">
            <header class="card-head">
                <div>
                    <p class="label-small">Live Availability</p>
                    <h2 id="suiteTitle" class="card-title">Sanctuary Suite Status</h2>
                </div>
                <div class="chip-total"><span><?= (int)$stats['rooms_available'] ?></span> / <?= (int)$stats['rooms_total'] ?> available</div>
            </header>
            <?php if (!$rooms): ?>
                <p class="empty">No suites have been configured.</p>
            <?php else: ?>
                <ul class="suite-list">
                    <?php foreach ($rooms as $r):
                        $rs = strtolower((string)$r['room_status']);
                        $known = ['available' => 'Available', 'cleaning' => 'Cleaning', 'maintenance' => 'Maintenance'];
                        $cls = isset($known[$rs]) ? 'rs-' . $rs : 'rs-other';
                        $lbl = $known[$rs] ?? ucwords(str_replace('_', ' ', $rs));
                        ?>
                        <li class="suite <?= e($cls) ?>">
                            <span class="suite-bar"></span>
                            <div class="suite-info">
                                <p class="suite-name"><?= e((string)$r['room_name']) ?></p>
                                <p class="suite-type"><?= e((string)$r['room_type']) ?></p>
                            </div>
                            <span class="badge <?= e($cls) ?>"><span class="dot"></span><?= e($lbl) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="banner" role="img" aria-label="Bella Vista Oasis spa banner">
                <span class="banner-text">Bella Vista Oasis</span>
            </div>
        </section>

        <section class="card card-soft" aria-labelledby="stdTitle">
            <header class="card-head card-head-icon">
                <span class="stat-ico"><?= icon('leaf') ?></span>
                <div>
                    <p class="label-small">Operational Standards</p>
                    <h2 id="stdTitle" class="card-title">Sanctuary Operational Standard</h2>
                </div>
            </header>
            <?php if (!$announcements): ?>
                <p class="empty">No announcements have been posted.</p>
            <?php else: ?>
                <ul class="std-list">
                    <?php foreach ($announcements as $a):
                        $pr = strtolower((string)$a['priority']);
                        $prKnown = ['high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];
                        $prCls = isset($prKnown[$pr]) ? 'pr-' . $pr : 'pr-normal';
                        $prLbl = $prKnown[$pr] ?? ucfirst($pr);
                        ?>
                        <li>
                            <div class="std-head">
                                <p class="std-title"><?= e((string)$a['title']) ?></p>
                                <span class="badge <?= e($prCls) ?>"><?= e($prLbl) ?></span>
                            </div>
                            <p class="std-desc"><?= e((string)$a['description']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="card card-wide" id="manifest" aria-labelledby="manifestTitle"
         data-page="<?= (int)$schedule['page'] ?>" data-pages="<?= (int)$schedule['pages'] ?>" data-total="<?= (int)$schedule['total'] ?>">
    <header class="card-head manifest-head">
        <div>
            <p class="label-small"><?= e(date('l, F j, Y')) ?></p>
            <h2 id="manifestTitle" class="card-title">Today's Schedule &amp; Guest Manifest</h2>
            <p class="card-sub"><strong><?= (int)$t['all'] ?></strong> appointment<?= $t['all'] === 1 ? '' : 's' ?> today ·
                <?= (int)$t['confirmed'] ?> confirmed · <?= (int)$t['completed'] ?> completed · <?= (int)$t['pending'] ?> pending</p>
        </div>
        <div class="manifest-tools">
            <label class="search search-inline">
                <?= icon('search') ?>
                <input type="search" id="scheduleSearch" placeholder="Search guest name" maxlength="100" autocomplete="off" aria-label="Search by guest name">
            </label>
            <?php $hasAppts = is_file(__DIR__ . '/appointments.php'); ?>
            <a class="btn btn-outline btn-sm <?= $hasAppts ? '' : 'is-soon' ?>" href="<?= $hasAppts ? e(url('/appointments.php')) : '#' ?>" <?= $hasAppts ? '' : 'data-soon="Appointment Management"' ?>>
                <span>View All</span><?= icon('arrow') ?>
            </a>
        </div>
    </header>

    <div class="filters" id="statusFilters" role="group" aria-label="Filter by status">
        <button type="button" class="filter is-active" data-status="all">All <span><?= (int)$t['all'] ?></span></button>
        <?php foreach ($statusLabels as $key => $label): ?>
            <button type="button" class="filter" data-status="<?= e($key) ?>"><?= e($label) ?> <span><?= (int)($t[$key] ?? 0) ?></span></button>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap" id="tableWrap">
        <table class="manifest">
            <thead>
                <tr>
                    <th scope="col">Guest Name</th>
                    <th scope="col">Treatment / Service</th>
                    <th scope="col">Assigned Therapist</th>
                    <th scope="col">Time</th>
                    <th scope="col">Suite / Room</th>
                    <th scope="col">Status</th>
                    <th scope="col">Payment</th>
                </tr>
            </thead>
            <tbody id="scheduleBody"><?= render_schedule_rows($schedule['rows'], false) ?></tbody>
        </table>
        <div class="loading" id="scheduleLoading" hidden><span class="spinner"></span></div>
    </div>

    <footer class="table-foot">
        <p id="scheduleSummary"><?= e(schedule_summary($schedule['total'], $schedule['page'], $schedule['per_page'], count($schedule['rows']))) ?></p>
        <nav class="pager" id="schedulePager" aria-label="Schedule pagination"></nav>
    </footer>
</section>

<div class="modal" id="bookingModal" role="dialog" aria-modal="true" aria-labelledby="bookingTitle" hidden>
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <p class="label-small">Reservation Desk</p>
                <h2 id="bookingTitle" class="card-title">Quick Booking</h2>
            </div>
            <button type="button" class="icon-btn" id="closeBooking" aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form id="bookingForm" novalidate>
            <?= csrf_field() ?>
            <div class="form-error" id="bookingError" role="alert" hidden></div>
            <div class="form-grid">
                <div class="field field-full">
                    <label for="bk_customer">Guest</label>
                    <select id="bk_customer" name="customer_id" required>
                        <option value="">Select a guest</option>
                        <?php foreach ($options['customers'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="bk_service">Treatment / Service</label>
                    <select id="bk_service" name="service_id" required>
                        <option value="">Select a treatment</option>
                        <?php foreach ($options['services'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['service_name'] . ' · ' . (int)$o['duration'] . ' min · ' . peso($o['price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_therapist">Therapist</label>
                    <select id="bk_therapist" name="therapist_id" required>
                        <option value="">Select a therapist</option>
                        <?php foreach ($options['therapists'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_room">Suite / Room</label>
                    <select id="bk_room" name="room_id" required>
                        <option value="">Select a suite</option>
                        <?php foreach ($options['rooms'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['room_name'] . ' · ' . $o['room_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_date">Date</label>
                    <input type="date" id="bk_date" name="appointment_date" min="<?= e($today) ?>" value="<?= e($today) ?>" required>
                </div>
                <div class="field">
                    <label for="bk_time">Start Time</label>
                    <input type="time" id="bk_time" name="start_time" step="900" required>
                </div>
            </div>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" id="cancelBooking">Cancel</button>
                <button type="submit" class="btn btn-primary" id="bookingSubmit"><span>Create Booking</span></button>
            </footer>
        </form>
    </div>
</div>
<?php
$footerScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];
require __DIR__ . '/includes/footer.php';