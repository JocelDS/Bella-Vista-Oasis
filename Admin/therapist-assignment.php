<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/therapists-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;
$profiles = false;

try {
    $ready         = ta_ready($pdo);
    $profiles      = $ready && ta_has_profile($pdo);
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO therapists] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load therapist assignment: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$pageTitle     = 'Therapist Assignment';
$crumb         = 'Therapist Assignment';
$footerScripts = $ready ? [url('/assets/js/therapist-assignment.js')] : [];
$cssFile       = __DIR__ . '/assets/css/therapist-assignment.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/therapist-assignment.css')) ?>?v=<?= e($cssVer) ?>">
<section class="page-head">
    <div>
        <p class="label-small">Roster Dispatch · Daily &amp; Weekly Coverage</p>
        <h1 class="page-title">Therapist Assignment &amp; Roster Dispatch</h1>
        <p class="page-sub">Manage therapist availability, coordinate treatment assignments, monitor workloads, and maintain balanced daily and weekly schedules.</p>
    </div>
    <?php if ($ready): ?>
    <div class="page-actions">
        <button type="button" class="btn btn-outline" id="btnRoster"><?= icon('calendar') ?><span>Weekly Roster</span></button>
        <?php if ($profiles): ?>
        <button type="button" class="btn btn-outline" id="btnAddTherapist"><?= icon('plus') ?><span>Add New Therapist</span></button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" id="btnAvail"><?= icon('sliders') ?><span>Manage Availability</span></button>
        <button type="button" class="btn btn-primary" id="btnAssign"><?= icon('plus') ?><span>Assign Therapist</span></button>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Therapist assignment tables are missing</h2>
    <p class="confirm-text">Import <strong>database/therapist-assignment-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It makes appointments.therapist_id nullable (so a booking can be unassigned) and adds four new tables: therapist_shifts, therapist_leaves, therapist_capacity and therapist_assignment_logs. Existing records are not touched. Then refresh this page.</p>
</section>
<?php else: ?>

<?php if (!$profiles): ?>
<section class="card"><p class="label-small">Optional Setup</p><p class="confirm-text">To enable <strong>Add New Therapist</strong>, import <strong>database/therapist-registration-extensions.sql</strong> in phpMyAdmin, then refresh.</p></section>
<?php endif; ?>

<section class="stats stats-ta" aria-label="Therapist statistics" data-appointments-url="<?= e(url('/appointments.php')) ?>" data-photo-base="<?= e(url('/assets/uploads/therapists/')) ?>" data-profiles="<?= $profiles ? '1' : '0' ?>">
    <article class="stat"><div class="stat-top"><span class="label-small">Total Therapists</span><span class="stat-ico"><?= icon('users') ?></span></div><p class="stat-value" id="sTotal">–</p><p class="stat-note" id="sTotalNote">On the spa roster</p></article>
    <article class="stat stat-hl"><div class="stat-top"><span class="label-small">Available Therapists</span><span class="stat-ico"><?= icon('check-ring') ?></span></div><p class="stat-value" id="sAvail">–</p><p class="stat-note" id="sAvailNote">Ready for assignments</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Assigned Sessions</span><span class="stat-ico"><?= icon('clipboard') ?></span></div><p class="stat-value" id="sAssigned">–</p><p class="stat-note">Upcoming pending &amp; confirmed</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Assignment Coverage</span><span class="stat-ico"><?= icon('pulse') ?></span></div><p class="stat-value" id="sCover">–</p><p class="stat-note" id="sCoverNote">Eligible bookings with a therapist</p></article>
    <article class="stat stat-dark"><div class="stat-top"><span class="label-small">Active Workload</span><span class="stat-ico"><?= icon('clock') ?></span></div><p class="stat-value" id="sWork">–</p><p class="stat-note" id="sWorkNote">Sessions today</p></article>
</section>

<section class="ta-layout">
    <aside class="ta-side">
        <section class="card" id="queueCard">
            <div class="card-head">
                <div><p class="label-small">Needs a Therapist</p><h2 class="card-title">Unassigned Booking</h2></div>
                <span class="count-pill" id="queueCount">0</span>
            </div>
            <div class="queue-list" id="queueList"></div>
        </section>

        <section class="card">
            <div class="card-head"><div><p class="label-small">Today</p><h2 class="card-title">Therapist Availability</h2></div></div>
            <ul class="avail-list" id="availList"></ul>
        </section>

        <section class="card">
            <div class="card-head"><div><p class="label-small">Live Checks</p><h2 class="card-title">Assignment Alerts</h2></div><span class="count-pill" id="alertCount">0</span></div>
            <ul class="alert-list" id="alertList"></ul>
        </section>
    </aside>

    <div class="ta-main">
        <section class="card ta-toolbar">
            <div class="filters-row">
                <input type="search" class="ctl search-inline" id="thSearch" placeholder="Search therapist or specialization" aria-label="Search therapists">
                <select class="ctl" id="thStatus" aria-label="Availability"><option value="">All availability</option><option value="available">Available</option><option value="on_leave">On leave</option><option value="inactive">Inactive</option></select>
                <select class="ctl" id="thService" aria-label="Service compatibility"><option value="">All services</option></select>
                <button type="button" class="btn btn-outline btn-sm" id="thReset">Reset</button>
            </div>
            <p class="cell-sub" id="thCount"></p>
        </section>
        <section class="th-grid" id="thGrid" aria-live="polite"></section>
    </div>
</section>

<section class="card card-wide" id="weekSection">
    <div class="appt-toolbar">
        <div class="wk-top">
            <div><p class="label-small">Shift Coverage</p><h2 class="card-title">Weekly Schedule &amp; Shift Coverage</h2></div>
            <div class="wk-controls">
                <button type="button" class="icon-btn" id="wkPrev" aria-label="Previous week">&lsaquo;</button>
                <span class="wk-range" id="wkRange">–</span>
                <button type="button" class="icon-btn" id="wkNext" aria-label="Next week">&rsaquo;</button>
                <button type="button" class="btn btn-outline btn-sm" id="wkToday">Current Week</button>
                <select class="ctl" id="wkTherapist" aria-label="Therapist filter"><option value="">All therapists</option></select>
            </div>
        </div>
        <div class="wk-legend">
            <span><i class="lg lg-busy"></i>Booked</span>
            <span><i class="lg lg-open"></i>Available</span>
            <span><i class="lg lg-leave"></i>On leave</span>
            <span><i class="lg lg-off"></i>Day off / no shift</span>
            <span><i class="lg lg-conflict"></i>Conflict</span>
        </div>
    </div>
    <div class="table-wrap wk-wrap"><table class="wk-table" id="wkTable"></table></div>
</section>

<div class="modal" id="assignModal" role="dialog" aria-modal="true" aria-labelledby="assignTitle" hidden>
    <div class="modal-card modal-xl">
        <form id="assignForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="therapist_id" id="as_therapist" value="">
            <header class="modal-head">
                <div><p class="label-small">Dispatch</p><h2 class="card-title" id="assignTitle">Assign Therapist</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="assignError" role="alert" hidden></p>
            <div class="field"><label for="as_appt">Select Appointment</label><select id="as_appt" name="appointment_id" required></select></div>
            <dl class="ins-grid as-info" id="asInfo"></dl>
            <p class="detail-h">Select Therapist</p>
            <div class="as-list" id="asList"><p class="cell-sub">Choose an appointment to see eligible therapists.</p></div>
            <label class="as-override" id="asOverrideRow" hidden><input type="checkbox" name="override" value="1" id="as_override"><span id="asOverrideText">Override the specialization recommendation</span></label>
            <div class="field"><label for="as_notes">Notes (optional)</label><input type="text" id="as_notes" name="notes" maxlength="300"></div>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline btn-danger-soft" id="asUnassign" hidden>Remove Therapist</button>
                <button type="submit" class="btn btn-primary" id="assignSubmit"><span>Save Assignment</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="availModal" role="dialog" aria-modal="true" aria-labelledby="availTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small">Roster</p><h2 class="card-title" id="availTitle">Manage Availability</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="field"><label for="av_therapist">Select Therapist</label><select id="av_therapist"></select></div>
        <div class="tabs av-tabs" id="avTabs">
            <button type="button" class="tab is-active" data-av="status">Status &amp; Leave</button>
            <button type="button" class="tab" data-av="shifts">Weekly Shift &amp; Capacity</button>
        </div>

        <form id="availForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_availability">
            <input type="hidden" name="therapist_id" id="av_tid_a">
            <p class="form-error" id="availError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field"><label for="av_status">Availability Status</label><select id="av_status" name="availability_status"><option value="available">Available</option><option value="on_leave">On leave</option></select></div>
                <div class="field"><label for="av_date">Effective Date</label><input type="date" id="av_date" name="effective_date" required></div>
                <div class="field" id="avEndRow"><label for="av_end">Leave Ends (optional)</label><input type="date" id="av_end" name="end_date"></div>
                <div class="field field-full"><label for="av_notes">Notes</label><input type="text" id="av_notes" name="notes" maxlength="300" placeholder="Reason or remarks"></div>
            </div>
            <p class="cell-sub" id="avCurrent"></p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="availSubmit"><span>Save Availability</span></button>
            </footer>
        </form>

        <form id="shiftForm" novalidate hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_shifts">
            <input type="hidden" name="therapist_id" id="av_tid_s">
            <p class="form-error" id="shiftError" role="alert" hidden></p>
            <p class="cell-sub">Working hours are not stored by default. Set them here so the weekly matrix and assignment checks can use them. Days left unchecked are treated as days off.</p>
            <div class="shift-grid" id="shiftGrid"></div>
            <div class="form-grid">
                <div class="field"><label for="av_cap">Daily Session Capacity (optional)</label><input type="number" id="av_cap" name="maximum_sessions" min="1" max="20" step="1"></div>
            </div>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" id="shiftFill">Fill with spa hours</button>
                <button type="button" class="btn btn-outline btn-danger-soft" id="shiftClear">Clear shifts</button>
                <button type="submit" class="btn btn-primary" id="shiftSubmit"><span>Save Shifts</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="profileModal" role="dialog" aria-modal="true" aria-labelledby="profileTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small" id="profileKicker">Therapist</p><h2 class="card-title" id="profileTitle">Profile</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="profileBody"></div>
    </div>
</div>

<div class="modal" id="therapistModal" role="dialog" aria-modal="true" aria-labelledby="therapistTitle" hidden>
    <div class="modal-card modal-xl">
        <form id="therapistForm" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="tf_action" value="create_therapist">
            <input type="hidden" name="id" id="tf_id" value="">
            <header class="modal-head">
                <div><p class="label-small" id="therapistKicker">New Hire Registration</p><h2 class="card-title" id="therapistTitle">Add New Therapist</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="therapistError" role="alert" hidden></p>
            <div class="tf-body">
                <p class="detail-h">Personal Information</p>
                <div class="form-grid">
                    <div class="field"><label for="tf_first">First Name</label><input type="text" id="tf_first" name="first_name" maxlength="60" required></div>
                    <div class="field"><label for="tf_mid">Middle Name (Optional)</label><input type="text" id="tf_mid" name="middle_name" maxlength="60"></div>
                    <div class="field"><label for="tf_last">Last Name</label><input type="text" id="tf_last" name="last_name" maxlength="60" required></div>
                    <div class="field"><label for="tf_email">Email Address</label><input type="email" id="tf_email" name="email" maxlength="150" required></div>
                    <div class="field"><label for="tf_phone">Contact Number</label><input type="tel" id="tf_phone" name="phone" maxlength="20" placeholder="0917 123 4567" required></div>
                    <div class="field field-full"><label for="tf_addr">Complete Address (Optional)</label><input type="text" id="tf_addr" name="address" maxlength="300"></div>
                    <div class="field field-full">
                        <label for="tf_photo">Profile Photo</label>
                        <div class="tf-photo">
                            <span class="avatar avatar-lg" id="tfPreview">?</span>
                            <div class="tf-photo-ctl">
                                <input type="file" id="tf_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                <span class="cell-sub">JPG, JPEG, PNG or WEBP · max 2 MB · initials are used when no photo is set</span>
                                <label class="tf-remove" id="tfRemoveRow" hidden><input type="checkbox" name="remove_photo" value="1" id="tf_remove"> Remove current photo</label>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="detail-h">Professional Information</p>
                <div class="form-grid">
                    <div class="field"><label for="tf_spec">Specialization</label><input type="text" id="tf_spec" name="specialization" maxlength="150" list="specList" placeholder="e.g. Hot Stone &amp; Reflexology" required></div>
                    <div class="field"><label for="tf_years">Years of Experience</label><input type="number" id="tf_years" name="years_experience" min="0" max="60" step="1" required></div>
                    <div class="field field-full"><label for="tf_cert">Certifications (Optional)</label><input type="text" id="tf_cert" name="certifications" maxlength="500" placeholder="e.g. TESDA Massage Therapy NC II"></div>
                    <div class="field"><label for="tf_hired">Employment Date</label><input type="date" id="tf_hired" name="employment_date" required></div>
                    <div class="field"><label for="tf_type">Employment Type</label>
                        <select id="tf_type" name="employment_type" required><option value="">Select type</option><option value="full_time">Full-Time</option><option value="part_time">Part-Time</option><option value="contractual">Contractual</option></select></div>
                </div>
                <datalist id="specList"></datalist>

                <p class="detail-h">Employment &amp; Availability</p>
                <div class="form-grid">
                    <div class="field"><label for="tf_emp">Employment Status</label>
                        <select id="tf_emp" name="employment_status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="on_leave">On Leave</option></select></div>
                    <div class="field"><label for="tf_avail">Initial Availability</label>
                        <select id="tf_avail" name="availability"><option value="available">Available</option><option value="unavailable">Unavailable</option></select></div>
                </div>
                <p class="cell-sub">Only Active therapists who are Available can receive assignments. Inactive therapists keep their history but cannot receive new bookings.</p>
            </div>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-outline" id="tfAnother" value="another"><span>Save &amp; Add Another</span></button>
                <button type="submit" class="btn btn-primary" id="tfSave" value="save"><span>Save Therapist</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
    <div class="modal-card modal-sm">
        <h2 class="card-title" id="confirmTitle">Confirm</h2>
        <p class="confirm-text" id="confirmText"></p>
        <footer class="modal-foot">
            <button type="button" class="btn btn-outline" id="confirmNo">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmYes">Confirm</button>
        </footer>
    </div>
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>