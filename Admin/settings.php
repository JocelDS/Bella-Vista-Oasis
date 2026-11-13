<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/settings-data.php';
require_once __DIR__ . '/includes/dashboard-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;
$allowed = sc_allowed_admin();
$cfg = $hours = $rooms = $therapists = [];
$status = ['open' => false, 'text' => '—', 'last' => null, 'last_by' => null];

try {
    $notifications = get_notifications($pdo, $adminId);
    $ready = sc_ready($pdo);
    if ($ready) {
        $cfg        = sc_config($pdo);
        $hours      = sc_hours($pdo);
        $rooms      = sc_rooms($pdo);
        $therapists = sc_therapists($pdo);
        $status     = sc_status($pdo);
    }
} catch (Throwable $e) {
    error_log('[BVO settings] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load settings. Check the PHP error log.');
}

$c = static fn(string $k, string $d = '') => $cfg[$k] ?? $d;
$on = static fn(string $k, string $d = '1') => ($cfg[$k] ?? $d) === '1';

$pageTitle     = 'Settings';
$crumb         = 'Settings';
$footerScripts = $ready && $allowed ? [url('/assets/js/settings.js'), url('/assets/js/blocked-dates.js')] : [];
$cssFile       = __DIR__ . '/assets/css/settings.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
$bdCssFile     = __DIR__ . '/assets/css/blocked-dates.css';
$bdCssVer      = is_file($bdCssFile) ? (string)filemtime($bdCssFile) : '1';
require __DIR__ . '/includes/header.php';

function sc_switch(string $key, bool $checked, string $label): string
{
    return '<label class="sc-switch"><input type="checkbox" data-cfg="' . e($key) . '"' . ($checked ? ' checked' : '') . ' aria-label="' . e($label) . '"><span class="sc-track" aria-hidden="true"></span></label>';
}
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/settings.css')) ?>?v=<?= e($cssVer) ?>">
<link rel="stylesheet" href="<?= e(url('/assets/css/blocked-dates.css')) ?>?v=<?= e($bdCssVer) ?>">

<section class="page-head sc-head">
    <div>
        <p class="label-small">System Configuration</p>
        <h1 class="page-title">Sanctuary Operations &amp; System Configuration</h1>
        <p class="page-sub">Configure sanctuary operations, manage room rates, maintain service standards, and control essential spa management settings.</p>
    </div>
    <?php if ($ready): ?>
    <div class="sc-meta">
        <div class="sc-meta-card">
            <p class="label-small">Current Operational Status</p>
            <p class="sc-meta-value"><span class="sc-dot <?= $status['open'] ? 'is-open' : 'is-closed' ?>" id="scDot"></span><span id="scStatusText"><?= e($status['text']) ?></span></p>
        </div>
        <div class="sc-meta-card">
            <p class="label-small">Last Configuration Update</p>
            <p class="sc-meta-value" id="scLastText"><?= $status['last'] ? e($status['last']) : 'No changes yet' ?></p>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Configuration tables are missing</h2>
    <p class="confirm-text">Import <strong>database/settings-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It only adds four new tables and keeps every existing record. Then refresh this page.</p>
</section>
<?php elseif (!$allowed): ?>
<section class="card">
    <p class="label-small">Restricted</p>
    <h2 class="card-title">Configuration is limited to administrators</h2>
    <p class="confirm-text">Your account role does not permit viewing or changing system configuration.</p>
</section>
<?php else: ?>

<nav class="sc-tabs" role="tablist" aria-label="Configuration sections">
    <button type="button" class="sc-tab is-active" role="tab" aria-selected="true" data-tab="hours"><?= icon('clock') ?><span>Operating Hours &amp; Schedule</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="pricing"><?= icon('wallet') ?><span>Pricing &amp; Room Rates</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="sanitation"><?= icon('shield') ?><span>Sanitation &amp; Safety Protocols</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="staff"><?= icon('users') ?><span>Staff &amp; Operational Rules</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="blocked"><?= icon('calendar') ?><span>Blocked Dates</span></button>
</nav>

<!-- ============ TAB 1 · HOURS ============ -->
<section class="sc-panel" data-panel="hours" role="tabpanel">
    <div class="sc-cols">
        <div class="sc-stack">
            <article class="card">
                <div class="card-head"><div><p class="label-small">Operating Schedule</p><h2 class="card-title">Sanctuary Operating Hours</h2></div><span class="sc-tag">Saved to database</span></div>

                <p class="sc-sub">Weekly operating days</p>
                <div class="sc-days" id="scDays">
                    <?php foreach ($hours as $h): ?>
                        <button type="button" class="sc-day<?= $h['is_open'] ? ' is-on' : '' ?>" data-day-tile="<?= (int)$h['day'] ?>" aria-pressed="<?= $h['is_open'] ? 'true' : 'false' ?>">
                            <span><?= e(strtoupper(substr($h['name'], 0, 3))) ?></span><strong><?= $h['is_open'] ? 'ON' : 'OFF' ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="sc-table-wrap">
                    <table class="sc-table" aria-label="Weekly operating schedule">
                        <thead><tr><th>Day</th><th>Open</th><th>Opening time</th><th>Closing time</th></tr></thead>
                        <tbody>
                        <?php foreach ($hours as $h): ?>
                            <tr data-day="<?= (int)$h['day'] ?>">
                                <td><?= e($h['name']) ?></td>
                                <td><label class="sc-switch"><input type="checkbox" data-hours-open<?= $h['is_open'] ? ' checked' : '' ?> aria-label="<?= e($h['name']) ?> open"><span class="sc-track" aria-hidden="true"></span></label></td>
                                <td><input type="time" data-hours-start value="<?= e($h['open']) ?>" aria-label="<?= e($h['name']) ?> opening time"></td>
                                <td><input type="time" data-hours-end value="<?= e($h['close']) ?>" aria-label="<?= e($h['name']) ?> closing time"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sc-time-cards">
                    <div class="sc-time-card">
                        <p class="label-small">Opening Time</p>
                        <input type="time" id="scStdOpen" aria-label="Standard opening time">
                        <p class="sc-note">Applies to every open day when changed</p>
                    </div>
                    <div class="sc-time-card is-dark">
                        <p class="label-small">Closing Time</p>
                        <input type="time" id="scStdClose" aria-label="Standard closing time">
                        <p class="sc-note">Applies to every open day when changed</p>
                    </div>
                </div>

                <div class="sc-mini-grid">
                    <div class="sc-mini">
                        <p class="label-small">Daily Reset / Cleaning Gap</p>
                        <div class="sc-num"><input type="number" min="0" max="120" step="5" inputmode="numeric" data-cfg="cleaning_gap_minutes" value="<?= e($c('cleaning_gap_minutes', '30')) ?>" aria-label="Cleaning gap in minutes"><span>Minutes</span></div>
                        <p class="sc-note">Rooms stay blocked this long after each session. Used by every booking.</p>
                    </div>
                    <div class="sc-mini">
                        <p class="label-small">Cancellation Notice Period</p>
                        <div class="sc-num"><input type="number" min="0" max="720" step="1" inputmode="numeric" data-cfg="cancellation_notice_hours" value="<?= e($c('cancellation_notice_hours', '48')) ?>" aria-label="Cancellation notice in hours"><span>Hours prior</span></div>
                        <p class="sc-note">Guests cancelling later than this may forfeit their deposit.</p>
                    </div>
                </div>
            </article>
        </div>

        <aside class="sc-stack">
            <article class="card">
                <div class="card-head"><div><p class="label-small">Rates &amp; Rooms</p><h2 class="card-title">Sanctuary Room Tariffs</h2></div></div>
                <div class="sc-tariffs" id="scTariffSummary"></div>
                <button type="button" class="btn btn-soft btn-sm sc-link-btn" data-goto="pricing">Manage room rates</button>
            </article>
        </aside>
    </div>
</section>

<!-- ============ TAB 2 · PRICING ============ -->
<section class="sc-panel" data-panel="pricing" role="tabpanel" hidden>
    <article class="card">
        <div class="card-head"><div><p class="label-small">Room Tariffs</p><h2 class="card-title">Pricing &amp; Room Rates</h2></div><span class="sc-tag">Per session</span></div>
        <p class="sc-note sc-note-top">Room rates are stored separately from service prices. Rooms without a rate show “No rate set”.</p>
        <div class="sc-room-grid" id="scRoomGrid"></div>
    </article>
</section>

<!-- ============ TAB 3 · SANITATION ============ -->
<section class="sc-panel" data-panel="sanitation" role="tabpanel" hidden>
    <div class="sc-cols">
        <article class="card">
            <div class="card-head"><div><p class="label-small">Standards of Care</p><h2 class="card-title">Sanitation &amp; Safety Thresholds</h2></div><span class="sc-tag">Saved to database</span></div>

            <div class="sc-rule">
                <div><h3>Mandatory Room Cleaning Gap</h3><p>Block each room between sessions so it can be reset properly.</p>
                    <div class="sc-num sc-num-inline"><input type="number" min="0" max="120" step="5" inputmode="numeric" data-cfg="cleaning_gap_minutes" value="<?= e($c('cleaning_gap_minutes', '30')) ?>" aria-label="Cleaning gap in minutes"><span>Minutes</span></div></div>
                <?= sc_switch('cleaning_gap_enabled', $on('cleaning_gap_enabled'), 'Mandatory room cleaning gap') ?>
            </div>
            <div class="sc-rule">
                <div><h3>Automated Cleaning &amp; HVAC Circulation</h3><p>Run air circulation and purification between guests. Recorded as the sanctuary standard.</p></div>
                <?= sc_switch('hvac_automation', $on('hvac_automation'), 'Automated HVAC circulation') ?>
            </div>
            <div class="sc-rule">
                <div><h3>Housekeeping Standard Before Reuse</h3><p>Require a completed sanitation routine before a room hosts another guest.</p>
                    <label class="sc-field"><span class="label-small">Standard description</span>
                        <textarea rows="2" maxlength="300" data-cfg="housekeeping_note"><?= e($c('housekeeping_note')) ?></textarea></label></div>
                <?= sc_switch('housekeeping_standard', $on('housekeeping_standard'), 'Housekeeping standard before reuse') ?>
            </div>
        </article>

        <aside class="card">
            <div class="card-head"><div><p class="label-small">Operational Safety</p><h2 class="card-title">Safety &amp; Maintenance Rules</h2></div></div>
            <div class="sc-rule">
                <div><h3>Block Rooms Under Maintenance</h3><p>Rooms marked as maintenance cannot be booked.</p></div>
                <?= sc_switch('block_maintenance_rooms', $on('block_maintenance_rooms'), 'Block rooms under maintenance') ?>
            </div>
            <div class="sc-rule">
                <div><h3>Cleaning Verification</h3><p>A reset is only complete once staff verify it.</p></div>
                <?= sc_switch('cleaning_verification', $on('cleaning_verification'), 'Cleaning verification') ?>
            </div>
            <div class="sc-rule">
                <div><h3>Equipment Readiness Check</h3><p>Confirm oils, stones, linens and tables are ready before each session.</p></div>
                <?= sc_switch('equipment_readiness', $on('equipment_readiness'), 'Equipment readiness check') ?>
            </div>
        </aside>
    </div>
</section>

<!-- ============ TAB 4 · STAFF ============ -->
<section class="sc-panel" data-panel="staff" role="tabpanel" hidden>
    <div class="sc-cols">
        <article class="card">
            <div class="card-head"><div><p class="label-small">Therapist Roster</p><h2 class="card-title">Staff &amp; Operational Rules</h2></div><span class="sc-tag"><?= count($therapists) ?> therapist<?= count($therapists) === 1 ? '' : 's' ?></span></div>
            <div class="sc-table-wrap">
                <table class="sc-table sc-staff" aria-label="Therapists">
                    <thead><tr><th>Therapist</th><th>Specialization</th><th>Availability</th><th>Next assignment</th></tr></thead>
                    <tbody>
                    <?php if (!$therapists): ?><tr><td colspan="4" class="sc-empty">No therapists are registered yet.</td></tr><?php endif; ?>
                    <?php foreach ($therapists as $t): ?>
                        <tr>
                            <td><strong><?= e($t['full_name']) ?></strong></td>
                            <td><?= e($t['specialization'] ?? '—') ?></td>
                            <td>
                                <select data-therapist="<?= (int)$t['id'] ?>" aria-label="Availability for <?= e($t['full_name']) ?>">
                                    <?php foreach (array_unique(['available', 'on_leave', (string)$t['availability_status']]) as $opt): ?>
                                        <option value="<?= e($opt) ?>"<?= $opt === $t['availability_status'] ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $opt))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><?= e($t['next_assignment'] ?? 'None scheduled') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <aside class="card">
            <div class="card-head"><div><p class="label-small">How It Applies</p><h2 class="card-title">Assignment Rules</h2></div></div>
            <ul class="sc-list">
                <li><strong>Available</strong> therapists can be assigned to new bookings and walk-ins.</li>
                <li><strong>On leave</strong> therapists are skipped when auto-assigning and flagged as unavailable.</li>
                <li>Cleaning gap and operating hours above apply to every therapist’s sessions.</li>
            </ul>
            <p class="sc-note">Per-therapist working hours and daily appointment limits need an extra table and are not part of this release.</p>
        </aside>
    </div>
</section>

<!-- ============ TAB 5 · BLOCKED DATES ============ -->
<section class="sc-panel" data-panel="blocked" role="tabpanel" hidden>
    <div class="sc-cols">
        <article class="card">
            <div class="card-head"><div><p class="label-small">Blocked Dates</p><h2 class="card-title">Special Closures &amp; Holiday Blocks</h2></div><span class="sc-tag" id="bdCount">Loading…</span></div>
            <form id="bdForm" class="bd-form" novalidate>
                <div class="field">
                    <label for="bdDate">Date</label>
                    <input type="date" id="bdDate" name="blocked_date" min="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="bdReason">Reason</label>
                    <input type="text" id="bdReason" name="reason" maxlength="255" placeholder="Private event, maintenance, holiday..." required>
                </div>
                <button type="submit" class="btn btn-primary" id="bdSubmit">Add Blocked Date</button>
            </form>
            <div class="bd-form-msgs">
                <p class="form-error" id="bdError" role="alert" hidden></p>
                <p class="sc-warn" id="bdWarn" role="status" hidden></p>
            </div>
            <ul class="bd-list" id="bdList" aria-live="polite"><li class="bd-empty">Loading…</li></ul>
        </article>
        <aside class="card">
            <div class="card-head"><div><p class="label-small">How It Applies</p><h2 class="card-title">Blocked Date Rules</h2></div></div>
            <ul class="sc-list">
                <li>Blocked dates are shown as <strong>not available</strong> in every booking date picker.</li>
                <li>The booking rules also reject them on the server, so they cannot be booked by any path.</li>
                <li>Only today or later can be blocked. Past entries stay in the list for reference.</li>
                <li>Existing pending or confirmed bookings are not cancelled automatically. A warning shows how many are affected.</li>
            </ul>
        </aside>
    </div>
</section>

<!-- ============ GALLERY ============ -->
<section class="sc-gallery" aria-label="Sanctuary facilities">
    <figure class="sc-shot sc-shot-1"><figcaption>Single &amp; Dual Chambers</figcaption></figure>
    <figure class="sc-shot sc-shot-2"><figcaption>Organic Ritual Oils</figcaption></figure>
    <figure class="sc-shot sc-shot-3"><figcaption>Private Couples Suites</figcaption></figure>
</section>

<!-- ============ ACTION BAR ============ -->
<section class="sc-bar" aria-label="Configuration actions">
    <p class="sc-bar-info"><span class="label-small">Last updated</span><span id="scBarLast"><?= $status['last'] ? e($status['last']) . ' by ' . e($status['last_by'] ?? 'Administrator') : 'No changes recorded yet' ?></span></p>
    <div class="sc-bar-actions">
        <button type="button" class="btn btn-soft" id="scReview">Review Operational Configuration</button>
        <button type="button" class="btn btn-outline" id="scReset" disabled>Reset Unsaved Changes</button>
        <button type="button" class="btn btn-primary" id="scSave" disabled>Save Configuration Changes</button>
    </div>
</section>

<!-- Rate modal -->
<div class="modal" id="scRateModal" role="dialog" aria-modal="true" aria-labelledby="srTitle" hidden>
    <div class="modal-card modal-sm">
        <div class="modal-head"><div><p class="label-small">Edit Rate</p><h2 class="card-title" id="srTitle">Room category</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button></div>
        <form id="scRateForm" novalidate>
            <p class="form-error" id="srError" role="alert" hidden></p>
            <p class="sc-note" id="srCurrent"></p>
            <div class="field"><label for="srApply">Apply to</label><select id="srApply"></select></div>
            <div class="field"><label for="srRate">New rate per session (₱)</label><input id="srRate" type="number" min="1" max="9999999.99" step="0.01" inputmode="decimal" required></div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="srSave">Save Rate</button>
            </div>
        </form>
    </div>
</div>

<!-- Review modal -->
<div class="modal" id="scReviewModal" role="dialog" aria-modal="true" aria-labelledby="rvTitle" hidden>
    <div class="modal-card modal-lg">
        <div class="modal-head"><div><p class="label-small">Saved Configuration</p><h2 class="card-title" id="rvTitle">Operational Review</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button></div>
        <div id="rvBody"><p class="empty">Loading…</p></div>
        <div class="modal-foot"><button type="button" class="btn btn-primary" data-close>Close</button></div>
    </div>
</div>

<!-- Confirm -->
<div class="modal" id="scConfirmModal" role="dialog" aria-modal="true" aria-labelledby="scCTitle" hidden>
    <div class="modal-card modal-sm">
        <h2 class="card-title" id="scCTitle">Confirm</h2>
        <p class="confirm-text" id="scCText"></p>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" data-close>Keep editing</button>
            <button type="button" class="btn btn-primary" id="scCOk">Confirm</button>
        </div>
    </div>
</div>

<script>window.SC_ROOMS = <?= json_encode($rooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>