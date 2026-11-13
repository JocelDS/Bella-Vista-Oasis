<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/customers-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;
$tiers = $services = $therapists = $rooms = [];

try {
    $ready         = cm_ready($pdo);
    $notifications = get_notifications($pdo, $adminId);
    if ($ready) {
        $tiers      = cm_tiers($pdo);
        $services   = $pdo->query('SELECT id, service_name, duration, price FROM services ORDER BY service_name')->fetchAll();
        $therapists = $pdo->query("SELECT id, full_name FROM therapists WHERE availability_status <> 'on_leave' ORDER BY full_name")->fetchAll();
        $rooms      = $pdo->query("SELECT id, room_name FROM rooms WHERE room_status <> 'maintenance' ORDER BY room_name")->fetchAll();
    }
} catch (Throwable $e) {
    error_log('[BVO customers] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load customer management. Check the PHP error log.');
}

$pageTitle     = 'Customer Management';
$crumb         = 'Customer Management';
$jsFile        = __DIR__ . '/assets/js/customer-management.js';
$footerScripts = $ready ? [url('/assets/js/customer-management.js') . '?v=' . (is_file($jsFile) ? filemtime($jsFile) : '1')] : [];
$cssFile       = __DIR__ . '/assets/css/customer-management.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
require __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('/assets/css/customer-management.css')) ?>?v=<?= e($cssVer) ?>">
<section class="page-head">
    <div>
        <p class="label-small">Guest Directory · Personalized Care</p>
        <h1 class="page-title">Guest Profiles &amp; Customer Management</h1>
        <p class="page-sub">Manage guest profiles, track customer activity, monitor spending, and maintain personalized spa experiences.</p>
    </div>
    <?php if ($ready): ?>
    <div class="page-actions">
        <button type="button" class="btn btn-outline" id="cmExport"><?= icon('download') ?><span>Export Directory</span></button>
        <button type="button" class="btn btn-primary" id="cmNew"><?= icon('plus') ?><span>New Customer</span></button>
        <button type="button" class="btn btn-primary" id="cmWalkin"><?= icon('plus') ?><span>Register Walk-in Guest</span></button>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Customer profile fields are missing</h2>
    <p class="confirm-text">Import <strong>database/customer-management-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It only adds new columns to the customers table and keeps every existing record. Then refresh this page.</p>
</section>
<?php else: ?>

<div class="cm-period">
    <label class="label-small" for="cmPeriod">Reporting period</label>
    <select id="cmPeriod" aria-label="Reporting period">
        <option value="30">Last 30 days</option>
        <option value="90">Last 90 days</option>
        <option value="365">Last 12 months</option>
        <option value="all">All time</option>
    </select>
</div>

<section class="stats stats-cm" aria-label="Customer statistics">
    <article class="stat"><div class="stat-top"><span class="label-small">Total Customers</span><span class="stat-ico"><?= icon('users') ?></span></div><p class="stat-value" data-cm-stat="total">–</p><p class="stat-note" data-cm-note="total">&nbsp;</p></article>
    <article class="stat stat-hl"><div class="stat-top"><span class="label-small">New Customers</span><span class="stat-ico"><?= icon('plus') ?></span></div><p class="stat-value" data-cm-stat="new">–</p><p class="stat-note" data-cm-note="new">&nbsp;</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Active Customers</span><span class="stat-ico"><?= icon('pulse') ?></span></div><p class="stat-value" data-cm-stat="active">–</p><p class="stat-note" data-cm-note="active">&nbsp;</p></article>
    <article class="stat stat-revenue"><div class="stat-top"><span class="label-small">Total Customer Revenue</span><span class="stat-ico"><?= icon('wallet') ?></span></div><p class="stat-value stat-money" data-cm-stat="revenue">–</p><p class="stat-note" data-cm-note="revenue">&nbsp;</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Customer Retention</span><span class="stat-ico"><?= icon('refresh') ?></span></div><p class="stat-value" data-cm-stat="retention">–</p><p class="stat-note" data-cm-note="retention">&nbsp;</p></article>
</section>

<section class="card cm-filters" aria-label="Filter customers">
    <form id="cmFilterForm" class="cm-filter-grid" autocomplete="off" onsubmit="return false">
        <label class="search cm-search">
            <?= icon('search') ?>
            <input type="search" id="fQ" placeholder="Search name, email, phone, ID" maxlength="100" aria-label="Search customers">
        </label>
        <select id="fStatus" aria-label="Customer status">
            <option value="all">Status: All</option>
            <option value="active">Status: Active</option>
            <option value="inactive">Status: Inactive</option>
        </select>
        <select id="fTier" aria-label="Membership tier">
            <option value="all">Tier: All</option>
            <?php foreach ($tiers as $t): ?><option value="<?= e($t) ?>">Tier: <?= e($t) ?></option><?php endforeach; ?>
            <option value="none">Tier: None</option>
        </select>
        <select id="fReg" aria-label="Registration date">
            <option value="any">Joined: Any</option>
            <option value="7">Joined: 7 days</option>
            <option value="30">Joined: 30 days</option>
            <option value="90">Joined: 90 days</option>
            <option value="365">Joined: 12 months</option>
        </select>
        <select id="fSpend" aria-label="Spending range">
            <option value="any">Any spending</option>
            <option value="lt5">Under ₱5,000</option>
            <option value="5to10">₱5,000 – ₱10,000</option>
            <option value="10to20">₱10,000 – ₱20,000</option>
            <option value="gt20">Over ₱20,000</option>
        </select>
        <select id="fSort" aria-label="Sort guests">
            <option value="newest">Sort: Newest</option>
            <option value="name">Sort: Customer name</option>
            <option value="spending">Sort: Highest spending</option>
            <option value="appointments">Sort: Most appointments</option>
        </select>
        <button type="button" class="btn btn-soft btn-sm" id="fClear">Clear filters</button>
    </form>
</section>

<div class="cm-layout">
    <section class="card cm-directory" aria-label="Customer directory">
        <div class="card-head">
            <div>
                <p class="label-small">Active Sanctuary Registry</p>
                <h2 class="card-title">Guest Directory</h2>
            </div>
            <p class="chip-total" id="cmCount">Loading…</p>
        </div>
        <div class="cm-list" id="cmList" aria-live="polite"></div>
        <div class="cm-pager-row">
            <p class="cm-summary" id="cmSummary"></p>
            <nav class="cm-pager" id="cmPager" aria-label="Pagination"></nav>
        </div>
    </section>

    <aside class="card cm-side" id="cmSide" aria-label="Selected customer" data-memberships="<?= is_file(__DIR__ . '/memberships.php') ? e(url('/memberships.php')) : '' ?>">
        <p class="empty">Select a guest to see their details.</p>
    </aside>
</div>

<!-- Customer form (create / edit) -->
<div class="modal" id="cmFormModal" role="dialog" aria-modal="true" aria-labelledby="cmFormTitle" hidden>
    <div class="modal-card modal-lg">
        <div class="modal-head">
            <div><p class="label-small" id="cmFormKicker">Guest Registration</p><h2 class="card-title" id="cmFormTitle">New Customer</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <form id="cmForm" novalidate enctype="multipart/form-data">
            <input type="hidden" name="action" id="cfAction" value="create">
            <input type="hidden" name="id" id="cfId" value="">
            <p class="form-error" id="cfError" role="alert" hidden></p>

            <p class="cm-section">Personal information</p>
            <div class="form-grid">
                <div class="field"><label for="cfFirst">First name *</label><input id="cfFirst" name="first_name" maxlength="80" required></div>
                <div class="field"><label for="cfMiddle">Middle name</label><input id="cfMiddle" name="middle_name" maxlength="80"></div>
                <div class="field"><label for="cfLast">Last name *</label><input id="cfLast" name="last_name" maxlength="80" required></div>
                <div class="field"><label for="cfEmail">Email address *</label><input id="cfEmail" name="email" type="email" maxlength="150"></div>
                <div class="field"><label for="cfPhone">Contact number *</label><input id="cfPhone" name="phone" type="tel" maxlength="30" required></div>
                <div class="field"><label for="cfDob">Date of birth</label><input id="cfDob" name="date_of_birth" type="date"></div>
                <div class="field"><label for="cfGender">Gender</label>
                    <select id="cfGender" name="gender"><option value="">Not specified</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option><option value="prefer_not">Prefer not to say</option></select></div>
                <div class="field field-full"><label for="cfAddress">Complete address</label><input id="cfAddress" name="address" maxlength="255"></div>
                <div class="field field-full cm-photo-field">
                    <label for="cfPhoto">Profile photo (JPG, PNG or WebP, max 2 MB)</label>
                    <div class="cm-photo-row">
                        <span class="cm-avatar cm-avatar-lg" id="cfPreview" aria-hidden="true">?</span>
                        <input id="cfPhoto" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
            </div>

            <p class="cm-section">Customer information</p>
            <div class="form-grid">
                <div class="field"><label for="cfReg">Registration date</label><input id="cfReg" name="registered_at" type="date"></div>
                <div class="field"><label for="cfStatus">Customer status</label>
                    <select id="cfStatus" name="customer_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div class="field field-full"><label for="cfNotes">Internal notes</label><textarea id="cfNotes" name="notes" rows="2" maxlength="2000"></textarea></div>
            </div>

            <p class="cm-section">Additional information</p>
            <div class="form-grid">
                <div class="field field-full"><label for="cfPrefs">Customer preferences</label><textarea id="cfPrefs" name="preferences" rows="2" maxlength="1000"></textarea></div>
                <div class="field field-full"><label for="cfSpecial">Special requests</label><textarea id="cfSpecial" name="special_requests" rows="2" maxlength="1000"></textarea></div>
            </div>
            <p class="cm-hint">Membership tier is managed in Membership &amp; Loyalty and shown here read-only.</p>

            <div class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="button" class="btn btn-soft" id="cfSaveAdd">Save &amp; Add Another</button>
                <button type="submit" class="btn btn-primary" id="cfSave">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Walk-in / booking -->
<div class="modal" id="cmWalkModal" role="dialog" aria-modal="true" aria-labelledby="cwTitle" hidden>
    <div class="modal-card modal-lg">
        <div class="modal-head">
            <div><p class="label-small" id="cwKicker">Walk-in Guest</p><h2 class="card-title" id="cwTitle">Register Walk-in Guest</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <form id="cmWalkForm" novalidate>
            <input type="hidden" name="action" value="walkin">
            <input type="hidden" name="customer_id" id="cwCustomerId" value="">
            <p class="form-error" id="cwError" role="alert" hidden></p>
            <div class="cm-existing" id="cwExisting" hidden>
                <p><strong id="cwExistingName"></strong> <span id="cwExistingCode"></span> — existing guest record will be used.</p>
                <button type="button" class="btn btn-soft btn-sm" id="cwUseNew">Register as new guest instead</button>
            </div>
            <div class="form-grid">
                <div class="field field-full cm-suggest-wrap">
                    <label for="cwName">Customer name *</label>
                    <input id="cwName" name="name" maxlength="150" autocomplete="off" required>
                    <ul class="cm-suggest" id="cwSuggest" hidden></ul>
                </div>
                <div class="field"><label for="cwPhone">Contact number *</label><input id="cwPhone" name="phone" type="tel" maxlength="30" required></div>
                <div class="field"><label for="cwEmail">Email (optional)</label><input id="cwEmail" name="email" type="email" maxlength="150"></div>
                <div class="field field-full"><label for="cwService">Requested service *</label>
                    <select id="cwService" name="service_id" required>
                        <option value="">Select a service</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['service_name']) ?> · <?= (int)$s['duration'] ?> min · ₱<?= e(number_format((float)$s['price'], 2)) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="cwGuests">Guests</label>
                    <select id="cwGuests" name="guest_count"><option value="">Service default</option>
                        <?php for ($g = 1; $g <= 6; $g++): ?><option value="<?= $g ?>"><?= $g ?></option><?php endfor; ?></select></div>
                <div class="field"><label for="cwDate">Appointment date *</label><input id="cwDate" name="appointment_date" type="date" required></div>
                <div class="field"><label for="cwTime">Preferred time *</label><input id="cwTime" name="start_time" type="time" step="900" required></div>
                <div class="field"><label for="cwTher">Therapist</label>
                    <select id="cwTher" name="therapist_id"><option value="0">Auto-assign</option>
                        <?php foreach ($therapists as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="cwRoom">Sanctuary suite</label>
                    <select id="cwRoom" name="room_id"><option value="0">Auto-assign</option>
                        <?php foreach ($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['room_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field field-full"><label for="cwNotes">Notes</label><textarea id="cwNotes" name="notes" rows="2" maxlength="500"></textarea></div>
            </div>
            <p class="cm-hint">Bookings follow the same availability, room-gap and therapist checks as Appointment Management.</p>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="cwSave">Register &amp; Book</button>
            </div>
        </form>
    </div>
</div>

<!-- Profile -->
<div class="modal" id="cmProfileModal" role="dialog" aria-modal="true" aria-labelledby="cpTitle" hidden>
    <div class="modal-card modal-xl cm-profile-card">
        <div class="modal-head">
            <div><p class="label-small">Guest Profile</p><h2 class="card-title" id="cpTitle">Guest</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <div id="cpBody"><p class="empty">Loading…</p></div>
    </div>
</div>

<!-- Confirm -->
<div class="modal" id="cmConfirmModal" role="dialog" aria-modal="true" aria-labelledby="ccTitle" hidden>
    <div class="modal-card modal-sm">
        <h2 class="card-title" id="ccTitle">Confirm</h2>
        <p class="confirm-text" id="ccText"></p>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" data-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="ccOk">Confirm</button>
        </div>
    </div>
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>