<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/appointments-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$today   = date('Y-m-d');
$adminId = (int)$_SESSION['admin_id'];

$filters = appt_parse_filters(['status' => 'all', 'page' => 1, 'per_page' => 10]);

try {
    $payload       = appt_payload($pdo, $filters);
    $options       = get_booking_options($pdo);
    $allTherapists = $pdo->query('SELECT id, full_name FROM therapists ORDER BY full_name ASC')->fetchAll();
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO appointments] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load appointments: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$stats  = $payload['stats'];
$counts = $payload['counts'];
$tabs   = ['all' => 'All Appointments'] + dash_status_map();

$pageTitle    = 'Appointment Management';
$crumb        = 'Appointments';
$extraStyles  = [url('/assets/css/appointments.css')];
$footerScripts = [url('/assets/js/appointments.js')];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <div>
        <h1 class="page-title">Appointment Management</h1>
        <p class="page-sub">Manage spa bookings, monitor appointment statuses, coordinate therapists, and oversee daily guest schedules.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-soft" id="openAvailability"><?= icon('sliders') ?><span>Manage Availability</span></button>
        <button type="button" class="btn btn-primary" id="openAppt"><?= icon('plus') ?><span>New Appointment</span></button>
    </div>
</section>

<section class="stats stats-5" aria-label="Appointment summary">
    <article class="stat">
        <div class="stat-top"><span class="label-small">Total Appointments</span><span class="stat-ico"><?= icon('clipboard') ?></span></div>
        <p class="stat-value" data-stat="total"><?= (int)$stats['total'] ?></p>
        <p class="stat-note">All booking records</p>
    </article>
    <article class="stat stat-hl">
        <div class="stat-top"><span class="label-small">Pending Bookings</span><span class="stat-ico"><?= icon('clock') ?></span></div>
        <p class="stat-value" data-stat="pending"><?= (int)$stats['pending'] ?></p>
        <p class="stat-note">Awaiting confirmation</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Today's Appointments</span><span class="stat-ico"><?= icon('calendar') ?></span></div>
        <p class="stat-value" data-stat="today"><?= (int)$stats['today'] ?></p>
        <p class="stat-note"><?= e(date('l, M j')) ?></p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Total Revenue</span><span class="stat-ico"><?= icon('wallet') ?></span></div>
        <p class="stat-value stat-money" data-stat="revenue"><?= e($stats['revenue']) ?></p>
        <p class="stat-note">From paid transactions</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Completion Rate</span><span class="stat-ico"><?= icon('check-ring') ?></span></div>
        <p class="stat-value" data-stat="rate"><?= e($stats['rate']) ?></p>
        <p class="stat-note" data-stat="rate_note"><?= e($stats['rate_note']) ?></p>
    </article>
</section>

<section class="card card-wide" id="apptCard">
    <div class="appt-toolbar">
        <div class="tabs" id="statusTabs" role="tablist" aria-label="Filter by status">
            <?php foreach ($tabs as $key => $label): ?>
                <button type="button" role="tab" class="tab <?= $key === 'all' ? 'is-active' : '' ?>" data-status="<?= e($key) ?>" aria-selected="<?= $key === 'all' ? 'true' : 'false' ?>">
                    <?= e($label) ?> <span class="count" data-count="<?= e($key) ?>"><?= (int)($counts[$key] ?? 0) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="filters-row">
            <label class="search search-inline">
                <?= icon('search') ?>
                <input type="search" id="fSearch" placeholder="Search customer, service or ID" maxlength="100" autocomplete="off" aria-label="Search appointments">
            </label>
            <input type="date" id="fDate" class="ctl" aria-label="Filter by date">
            <select id="fTherapist" class="ctl" aria-label="Filter by therapist">
                <option value="">All therapists</option>
                <?php foreach ($allTherapists as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="fService" class="ctl" aria-label="Filter by service">
                <option value="">All services</option>
                <?php foreach ($options['services'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['service_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline btn-sm" id="fReset"><?= icon('refresh') ?><span>Reset</span></button>
        </div>
    </div>

    <div class="table-wrap" id="tableWrap">
        <table class="manifest appt-table">
            <thead>
                <tr>
                    <th scope="col">Appointment ID</th>
                    <th scope="col">Customer Information</th>
                    <th scope="col">Service Details</th>
                    <th scope="col">Date &amp; Time</th>
                    <th scope="col">Assigned Therapist</th>
                    <th scope="col">Room</th>
                    <th scope="col">Payment Status</th>
                    <th scope="col">Appointment Status</th>
                    <th scope="col" class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="apptBody"><?= $payload['html'] ?></tbody>
        </table>
        <div class="loading" id="apptLoading" hidden><span class="spinner"></span></div>
    </div>

    <footer class="table-foot">
        <p id="apptSummary"><?= e($payload['summary']) ?></p>
        <div class="foot-right">
            <label class="per-page">Rows
                <select id="perPage" class="ctl ctl-sm" aria-label="Records per page">
                    <?php foreach (APPT_PER_PAGE_OPTIONS as $n): ?>
                        <option value="<?= $n ?>" <?= $n === 10 ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <nav class="pager" id="apptPager" aria-label="Appointment pagination" data-page="<?= (int)$payload['page'] ?>" data-pages="<?= (int)$payload['pages'] ?>"></nav>
        </div>
    </footer>
</section>

<div class="row-menu-pop" id="rowMenuPop" role="menu" hidden></div>

<div class="modal" id="apptModal" role="dialog" aria-modal="true" aria-labelledby="apptModalTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div>
                <p class="label-small" id="apptModalKicker">Reservation Desk</p>
                <h2 id="apptModalTitle" class="card-title">New Appointment</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form id="apptForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="ap_action" value="create">
            <input type="hidden" name="id" id="ap_id" value="">
            <div class="form-error" id="apptError" role="alert" hidden></div>
            <div class="form-grid">
                <div class="field field-full">
                    <label for="ap_customer">Customer</label>
                    <select id="ap_customer" name="customer_id" required>
                        <option value="">Select a customer</option>
                        <?php foreach ($options['customers'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="ap_health">Medical conditions</label>
                    <textarea id="ap_health" name="health_conditions" rows="2" maxlength="500" required placeholder="Type None if the guest has no medical conditions"></textarea>
                </div>
                <div class="field">
                    <label for="ap_injuries">Injuries</label>
                    <textarea id="ap_injuries" name="health_injuries" rows="2" maxlength="500" required placeholder="Type None if no injuries"></textarea>
                </div>
                <div class="field">
                    <label for="ap_preg">Pregnant?</label>
                    <select id="ap_preg" name="is_pregnant" required>
                        <option value="">Select</option>
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="ap_service">Service</label>
                    <select id="ap_service" name="service_id" required>
                        <option value="">Select a service</option>
                        <?php foreach ($options['services'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" data-duration="<?= (int)$o['duration'] ?>" data-price="<?= e(number_format((float)$o['price'], 2, '.', '')) ?>"><?= e($o['service_name'] . ' · ' . (int)$o['duration'] . ' min · ' . peso($o['price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ap_therapist">Therapist</label>
                    <select id="ap_therapist" name="therapist_id">
                        <option value="0">Assign later</option>
                        <?php foreach ($options['therapists'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ap_room">Room</label>
                    <select id="ap_room" name="room_id" required>
                        <option value="">Select a room</option>
                        <?php foreach ($options['rooms'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['room_name'] . ' · ' . $o['room_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ap_date">Appointment Date</label>
                    <input type="date" id="ap_date" name="appointment_date" min="<?= e($today) ?>" value="<?= e($today) ?>" required>
                </div>
                <div class="field">
                    <label for="ap_guests">Guests</label>
                    <select id="ap_guests" name="guest_count">
                        <option value="">Service default</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5</option>
                        <option value="6">6</option>
                    </select>
                </div>
                <div class="field">
                    <label for="ap_amount_note">Total Amount</label>
                    <input type="text" id="ap_amount_note" value="Computed automatically" readonly tabindex="-1">
                    <input type="hidden" id="ap_amount">
                </div>
                <div class="field">
                    <label for="ap_start">Start Time</label>
                    <select id="ap_start" name="start_time" required><option value="">Select a service and date first</option></select>
                </div>
                <div class="field">
                    <label for="ap_end">End Time</label>
                    <input type="time" id="ap_end" name="end_time" step="900">
                </div>
                <div class="field">
                    <label for="ap_pay">Payment Status</label>
                    <select id="ap_pay" name="payment_status">
                        <option value="pending">Pending</option>
                        <option value="deposit">Deposit</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="field" id="apDownWrap" hidden>
                    <label for="ap_down">Downpayment Amount (₱)</label>
                    <input type="number" id="ap_down" name="downpayment_amount" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="field">
                    <label for="ap_status">Appointment Status</label>
                    <select id="ap_status" name="appointment_status">
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                    </select>
                </div>
            </div>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="apptSubmit"><span>Save Appointment</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="detailTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div>
                <p class="label-small" id="detailKicker">Appointment</p>
                <h2 id="detailTitle" class="card-title">Appointment Details</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="detailBody"></div>
        <footer class="modal-foot" id="detailFoot"></footer>
    </div>
</div>

<div class="modal" id="availModal" role="dialog" aria-modal="true" aria-labelledby="availTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div>
                <p class="label-small">Rooms &amp; Therapists</p>
                <h2 id="availTitle" class="card-title">Manage Availability</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="avail-bar">
            <label for="availDate" class="label-small">Schedule date</label>
            <input type="date" id="availDate" class="ctl" value="<?= e($today) ?>">
            <span class="avail-date" id="availDateLabel"></span>
        </div>
        <div class="avail-grid">
            <section>
                <h3 class="avail-h">Rooms</h3>
                <ul class="avail-list" id="availRooms"></ul>
            </section>
            <section>
                <h3 class="avail-h">Therapists</h3>
                <ul class="avail-list" id="availTherapists"></ul>
            </section>
        </div>
    </div>
</div>

<div class="modal" id="confirmModal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
    <div class="modal-card modal-sm">
        <h2 id="confirmTitle" class="card-title">Please confirm</h2>
        <p class="confirm-text" id="confirmText"></p>
        <footer class="modal-foot">
            <button type="button" class="btn btn-outline" id="confirmNo">Go Back</button>
            <button type="button" class="btn btn-primary" id="confirmYes">Confirm</button>
        </footer>
    </div>
</div>
<?php
require __DIR__ . '/includes/footer.php';