<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/calendar-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$today   = date('Y-m-d');
$adminId = (int)$_SESSION['admin_id'];

$initial = cal_params($_GET);

try {
    $options       = get_booking_options($pdo);
    $allRooms      = $pdo->query('SELECT id, room_name, room_type, room_status FROM rooms ORDER BY id ASC')->fetchAll();
    $allTherapists = $pdo->query('SELECT id, full_name, availability_status FROM therapists ORDER BY full_name ASC')->fetchAll();
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO calendar] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load the calendar: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$pageTitle     = 'Appointment Calendar';
$crumb         = 'Appointment Calendar';
$extraStyles   = [url('/assets/css/appointment-calendar.css')];
$footerScripts = [url('/assets/js/appointment-calendar.js')];
require __DIR__ . '/includes/header.php';
?>
<div id="calApp" class="cal-app"
     data-view="<?= e($initial['view']) ?>"
     data-date="<?= e($initial['date']) ?>"
     data-today="<?= e($today) ?>"
     data-room="<?= $initial['room'] ?: '' ?>"
     data-therapist="<?= $initial['therapist'] ?: '' ?>"
     data-status="<?= e($initial['status']) ?>"
     data-service="<?= $initial['service'] ?: '' ?>">

<section class="cal-hero">
    <div class="cal-hero-text">
        <p class="label-small">Suite Orchestration · Daily Sanctuary Schedule</p>
        <h1 class="page-title">Appointment Calendar</h1>
        <p class="page-sub">Monitor room schedules, therapist availability, and guest appointments through an organized real-time calendar.</p>
    </div>
    <div class="cal-hero-tools">
        <div class="seg-switch" id="viewSwitch" role="tablist" aria-label="Calendar view">
            <button type="button" role="tab" class="seg-btn" data-view="multi">Multi-Room</button>
            <button type="button" role="tab" class="seg-btn" data-view="day">Day View</button>
            <button type="button" role="tab" class="seg-btn" data-view="week">Week View</button>
        </div>
        <div class="date-nav">
            <button type="button" class="icon-btn" id="datePrev" aria-label="Previous"><?= icon('chevron', 'ico ico-left') ?></button>
            <button type="button" class="date-label" id="dateLabel" aria-live="polite"></button>
            <button type="button" class="icon-btn" id="dateNext" aria-label="Next"><?= icon('chevron', 'ico ico-right') ?></button>
            <button type="button" class="btn btn-outline btn-sm" id="dateToday">Today</button>
            <input type="date" id="datePick" class="ctl" aria-label="Pick a date" value="<?= e($initial['date']) ?>">
        </div>
    </div>
</section>

<section class="cal-bar">
    <div class="cal-filters">
        <select id="cfRoom" class="ctl" aria-label="Filter by suite">
            <option value="">All Sanctuary Suites</option>
            <?php foreach ($allRooms as $o): ?>
                <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['room_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="cfTherapist" class="ctl" aria-label="Filter by therapist">
            <option value="">All Therapists</option>
            <?php foreach ($allTherapists as $o): ?>
                <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="cfStatus" class="ctl" aria-label="Filter by status">
            <option value="">Appointment Status</option>
            <?php foreach (dash_status_map() as $k => $label): ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="cfService" class="ctl" aria-label="Filter by service">
            <option value="">Service Type</option>
            <?php foreach ($options['services'] as $o): ?>
                <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['service_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-outline btn-sm" id="cfReset"><?= icon('refresh') ?><span>Reset</span></button>
        <button type="button" class="btn btn-primary btn-sm" id="openBooking"><?= icon('plus') ?><span>New Booking</span></button>
    </div>
    <ul class="cal-legend" aria-label="Legend">
        <li><span class="lg lg-session"></span>Treatment in Session</li>
        <li><span class="lg lg-confirmed"></span>Confirmed</li>
        <li><span class="lg lg-pending"></span>Pending</li>
        <li><span class="lg lg-completed"></span>Completed</li>
        <li><span class="lg lg-gap"></span>Cleaning Gap</li>
        <li><span class="lg lg-open"></span>Available</li>
        <li><span class="lg lg-off"></span>No-Show / Cancelled</li>
    </ul>
</section>

<section class="card cal-card-wrap" aria-labelledby="matrixTitle">
    <header class="cal-head">
        <div>
            <p class="label-small" id="calKicker">Live Room Schedule</p>
            <h2 id="matrixTitle" class="card-title">Suite Timeline Matrix</h2>
        </div>
        <div class="cal-head-meta">
            <span class="chip-total" id="calHours"></span>
            <span class="cal-hint" id="calHint">Click any open slot to reserve or a card to inspect</span>
        </div>
    </header>
    <p class="cal-summary" id="calSummary"></p>
    <div class="cal-scroll" id="calScroll">
        <div id="calBody" class="cal-body"></div>
        <div class="loading" id="calLoading" hidden><span class="spinner"></span></div>
    </div>
</section>

<div class="cal-lower">
    <section class="card inspect" id="inspect" aria-labelledby="inspectTitle">
        <header class="card-head">
            <div>
                <p class="label-small">Inspection Card</p>
                <h2 id="inspectTitle" class="card-title">Appointment Details</h2>
            </div>
            <span class="badge st-other" id="inspectCode" hidden></span>
        </header>
        <div id="inspectBody"><p class="empty">Select an appointment on the calendar to inspect its details.</p></div>
    </section>

    <div class="col">
        <section class="card" aria-labelledby="openTitle">
            <header class="card-head">
                <div>
                    <p class="label-small" id="openKicker">Ready for Walk-ins</p>
                    <h2 id="openTitle" class="card-title">Instant Sanctuary Openings</h2>
                </div>
            </header>
            <ul class="open-list" id="openList"></ul>
        </section>

        <section class="card" aria-labelledby="rosterTitle">
            <header class="card-head">
                <div>
                    <p class="label-small">Therapist Assignment</p>
                    <h2 id="rosterTitle" class="card-title">Therapist Roster</h2>
                </div>
            </header>
            <ul class="roster" id="rosterList"></ul>
        </section>
    </div>
</div>

</div>

<div class="modal" id="bookModal" role="dialog" aria-modal="true" aria-labelledby="bookTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div>
                <p class="label-small">Reservation Desk</p>
                <h2 id="bookTitle" class="card-title">New Booking</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form id="bookForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-error" id="bookError" role="alert" hidden></div>
            <div class="form-grid">
                <div class="field field-full">
                    <label for="bk_customer">Customer</label>
                    <select id="bk_customer" name="customer_id" required>
                        <option value="">Select a customer</option>
                        <?php foreach ($options['customers'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e((string)$o['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="bk_service">Service</label>
                    <select id="bk_service" name="service_id" required>
                        <option value="">Select a service</option>
                        <?php foreach ($options['services'] as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" data-duration="<?= (int)$o['duration'] ?>" data-price="<?= e(number_format((float)$o['price'], 2, '.', '')) ?>"><?= e($o['service_name'] . ' · ' . (int)$o['duration'] . ' min · ' . peso($o['price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_therapist">Therapist</label>
                    <select id="bk_therapist" name="therapist_id" required>
                        <option value="">Select a therapist</option>
                        <?php foreach ($allTherapists as $o): $off = $o['availability_status'] === 'on_leave'; ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $off ? 'disabled' : '' ?>><?= e((string)$o['full_name'] . ($off ? ' · On leave' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_room">Room</label>
                    <select id="bk_room" name="room_id" required>
                        <option value="">Select a room</option>
                        <?php foreach ($allRooms as $o): $off = $o['room_status'] !== 'available'; ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $off ? 'disabled' : '' ?>><?= e($o['room_name'] . ' · ' . $o['room_type'] . ($off ? ' · ' . ucfirst((string)$o['room_status']) : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_date">Appointment Date</label>
                    <input type="date" id="bk_date" name="appointment_date" min="<?= e($today) ?>" value="<?= e($today) ?>" required>
                </div>
                <div class="field">
                    <label for="bk_amount">Total Amount (₱)</label>
                    <input type="number" id="bk_amount" name="total_amount" min="0" step="0.01" inputmode="decimal">
                </div>
                <div class="field">
                    <label for="bk_start">Start Time</label>
                    <input type="time" id="bk_start" name="start_time" step="900" required>
                </div>
                <div class="field">
                    <label for="bk_end">End Time</label>
                    <input type="time" id="bk_end" name="end_time" step="900">
                </div>
                <div class="field">
                    <label for="bk_pay">Payment Status</label>
                    <select id="bk_pay" name="payment_status">
                        <option value="pending">Pending</option>
                        <option value="deposit">Deposit</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="field">
                    <label for="bk_status">Appointment Status</label>
                    <select id="bk_status" name="appointment_status">
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                    </select>
                </div>
            </div>
            <footer class="modal-foot modal-foot-end">
                <button type="submit" class="btn btn-primary" id="bookSubmit"><span>Save Booking</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="reschedModal" role="dialog" aria-modal="true" aria-labelledby="reschedTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div>
                <p class="label-small" id="reschedKicker">Reschedule</p>
                <h2 id="reschedTitle" class="card-title">Reschedule Appointment</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form id="reschedForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="id" id="rs_id" value="">
            <div class="form-error" id="reschedError" role="alert" hidden></div>
            <p class="resched-note" id="reschedNote"></p>
            <div class="form-grid">
                <div class="field">
                    <label for="rs_date">New Date</label>
                    <input type="date" id="rs_date" name="appointment_date" min="<?= e($today) ?>" required>
                </div>
                <div class="field">
                    <label for="rs_start">New Start Time</label>
                    <input type="time" id="rs_start" name="start_time" step="900" required>
                </div>
                <div class="field">
                    <label for="rs_room">Room</label>
                    <select id="rs_room" name="room_id" required>
                        <?php foreach ($allRooms as $o): $off = $o['room_status'] !== 'available'; ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $off ? 'disabled' : '' ?>><?= e($o['room_name'] . ' · ' . $o['room_type'] . ($off ? ' · ' . ucfirst((string)$o['room_status']) : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="rs_therapist">Therapist</label>
                    <select id="rs_therapist" name="therapist_id" required>
                        <?php foreach ($allTherapists as $o): $off = $o['availability_status'] === 'on_leave'; ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $off ? 'disabled' : '' ?>><?= e((string)$o['full_name'] . ($off ? ' · On leave' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="check-line" id="reschedCheck" hidden></p>
            <footer class="modal-foot modal-foot-end">
                <button type="submit" class="btn btn-primary" id="reschedSubmit"><span>Save New Schedule</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="intakeModal" role="dialog" aria-modal="true" aria-labelledby="intakeTitle" hidden>
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <p class="label-small" id="intakeKicker">Intake Folio</p>
                <h2 id="intakeTitle" class="card-title">Guest Folio</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="folio" id="intakeBody"></div>
        <footer class="modal-foot modal-foot-end">
        </footer>
    </div>
</div>

<div class="modal" id="confirmModal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
    <div class="modal-card modal-sm">
        <h2 id="confirmTitle" class="card-title">Please confirm</h2>
        <p class="confirm-text" id="confirmText"></p>
        <footer class="modal-foot modal-foot-end">
            <button type="button" class="btn btn-outline" id="confirmNo">Go Back</button>
            <button type="button" class="btn btn-primary" id="confirmYes">Confirm</button>
        </footer>
    </div>
</div>
<?php
require __DIR__ . '/includes/footer.php';