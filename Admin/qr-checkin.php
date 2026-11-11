<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/qr-checkin-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;

try {
    $ready         = qr_ready($pdo);
    $notifications = get_notifications($pdo, $adminId);
    $policy        = $ready ? qr_settings($pdo) : ['early' => 60, 'late' => 30, 'allow_pending' => false];
} catch (Throwable $e) {
    error_log('[BVO qr-checkin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load QR check-in: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

/** Icons this page needs that the shared icon set does not have. */
function qr_ico(string $name): string
{
    static $p = [
        'camera'   => '<path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/>',
        'scan'     => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/>',
        'keyboard' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>',
        'printer'  => '<path d="M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v5a1 1 0 0 1-1 1h-2"/><rect x="7" y="14" width="10" height="6" rx="1"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'stop'     => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
        'settings' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

$pageTitle     = 'QR Check-in Management';
$crumb         = 'QR Check-in Management';
$footerScripts = $ready ? [
    url('/assets/js/vendor/jsQR.min.js'),
    url('/assets/js/vendor/qrcode.min.js'),
    url('/assets/js/qr-checkin.js') . '?v=' . (is_file(__DIR__ . '/assets/js/qr-checkin.js') ? filemtime(__DIR__ . '/assets/js/qr-checkin.js') : '1'),
] : [];
$cssVer = is_file(__DIR__ . '/assets/css/qr-checkin.css') ? (string)filemtime(__DIR__ . '/assets/css/qr-checkin.css') : '1';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/qr-checkin.css')) ?>?v=<?= e($cssVer) ?>">

<div class="qr-page" id="qrPage" data-appointments-url="<?= e(url('/appointments.php')) ?>" data-tz="<?= e(date_default_timezone_get()) ?>">

<section class="page-head qr-head">
    <div class="qr-head-text">
        <p class="label-small">Reception · Guest Arrival Concierge</p>
        <h1 class="page-title">Reception QR Check-in &amp; Guest Arrival Concierge</h1>
        <p class="page-sub">Manage guest arrivals, verify appointment QR codes, confirm reservations, and monitor real-time reception check-in activity.</p>
    </div>
    <?php if ($ready): ?>
    <div class="qr-head-side">
        <div class="qr-clock" aria-live="off">
            <p class="label-small">Reception Clock</p>
            <p class="qr-clock-time" id="qrClock">--:--:--</p>
            <p class="qr-clock-date" id="qrClockDate"></p>
        </div>
        <div class="qr-service" id="qrService" data-state="checking">
            <span class="dot"></span>
            <span><strong id="qrServiceText">Checking services…</strong><small id="qrServiceSub">QR check-in</small></span>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">QR check-in tables are missing</h2>
    <p class="confirm-text">Import <strong>database/qr-checkin-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It adds three new tables: checkin_settings, appointment_checkins and checkin_activity_logs. Existing records and the appointments table are not touched. Then refresh this page.</p>
</section>
<?php else: ?>

<div class="qr-actions">
    <button type="button" class="btn btn-outline" id="btnSettings"><?= qr_ico('settings') ?><span>Check-in Window</span></button>
    <button type="button" class="btn btn-outline" id="btnManual"><?= qr_ico('keyboard') ?><span>Manual Check-in</span></button>
    <a class="btn btn-primary" href="<?= e(url('/appointments.php')) ?>"><?= icon('plus') ?><span>New Walk-in / Booking</span></a>
</div>

<section class="stats qr-stats" aria-label="Today's check-in summary">
    <article class="stat">
        <div class="stat-top"><span class="label-small">Today's QR Check-ins</span><span class="stat-ico"><?= qr_ico('scan') ?></span></div>
        <p class="stat-value" id="sQr">–</p>
        <div class="qr-bar"><span id="sQrBar"></span></div>
        <p class="stat-note" id="sQrNote">Loading…</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Verified Arrivals</span><span class="stat-ico"><?= icon('check-ring') ?></span></div>
        <p class="stat-value" id="sVer">–</p>
        <div class="qr-bar"><span id="sVerBar"></span></div>
        <p class="stat-note" id="sVerNote">Loading…</p>
    </article>
    <article class="stat stat-hl">
        <div class="stat-top"><span class="label-small">Verification Success Rate</span><span class="stat-ico"><?= icon('shield') ?></span></div>
        <p class="stat-value" id="sRate">–</p>
        <div class="qr-bar"><span id="sRateBar"></span></div>
        <p class="stat-note" id="sRateNote">Loading…</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Manual Check-ins</span><span class="stat-ico"><?= qr_ico('keyboard') ?></span></div>
        <p class="stat-value" id="sMan">–</p>
        <div class="qr-bar"><span id="sManBar"></span></div>
        <p class="stat-note" id="sManNote">Loading…</p>
    </article>
</section>

<section class="qr-grid">
    <div class="qr-col qr-col-left">

        <section class="card qr-terminal" aria-labelledby="termTitle">
            <div class="card-head">
                <div><p class="label-small">Reception Terminal</p><h2 class="card-title" id="termTitle">Optical QR Terminal</h2></div>
                <span class="qr-pill" id="termStatus" data-state="idle">Scanner idle</span>
            </div>

            <div class="qr-seg" role="tablist" aria-label="Terminal mode">
                <button type="button" class="qr-seg-btn is-active" role="tab" aria-selected="true" data-mode="scan"><?= qr_ico('camera') ?><span>Scan QR Code</span></button>
                <button type="button" class="qr-seg-btn" role="tab" aria-selected="false" data-mode="generate"><?= icon('qr') ?><span>Generate QR Code</span></button>
            </div>

            <div class="qr-viewport" id="qrViewport" data-view="idle">
                <video id="qrVideo" playsinline muted></video>
                <canvas id="qrFrame" hidden></canvas>
                <span class="qr-corner tl"></span><span class="qr-corner tr"></span><span class="qr-corner bl"></span><span class="qr-corner br"></span>

                <div class="qr-vp-idle" id="vpIdle">
                    <span class="qr-vp-icon"><?= qr_ico('scan') ?></span>
                    <strong>Align Guest Pass QR Code</strong>
                    <p>Hold the guest's QR pass inside the frame. Verification happens automatically.</p>
                </div>
                <div class="qr-vp-qr" id="vpQr" hidden>
                    <div class="qr-tile"><canvas id="qrCanvas" width="320" height="320" aria-label="Generated QR code"></canvas></div>
                    <p class="qr-vp-cap" id="vpQrCap"></p>
                </div>
                <div class="qr-vp-gen-empty" id="vpGenEmpty" hidden>
                    <span class="qr-vp-icon"><?= icon('qr') ?></span>
                    <strong>No QR code yet</strong>
                    <p>Choose an appointment below and generate its secure check-in code.</p>
                </div>
                <div class="qr-vp-foot"><span id="vpMsg">Camera off</span></div>
            </div>

            <div class="qr-pane" id="paneScan">
                <div class="qr-btn-row">
                    <button type="button" class="btn btn-primary" id="btnScanStart"><?= qr_ico('camera') ?><span>Start Scanner</span></button>
                    <button type="button" class="btn btn-outline" id="btnScanStop" hidden><?= qr_ico('stop') ?><span>Stop Scanner</span></button>
                </div>
                <p class="qr-note" id="scanNote" hidden></p>
            </div>

            <div class="qr-pane" id="paneGen" hidden>
                <div class="field">
                    <label for="genAppt">Appointment</label>
                    <select id="genAppt"><option value="">Loading appointments…</option></select>
                </div>
                <div class="qr-btn-row">
                    <button type="button" class="btn btn-primary" id="btnGenerate"><?= icon('qr') ?><span>Generate QR Code</span></button>
                    <button type="button" class="btn btn-outline" id="btnQrPrint" disabled><?= qr_ico('printer') ?><span>Print</span></button>
                    <button type="button" class="btn btn-outline" id="btnQrDownload" disabled><?= icon('download') ?><span>Download</span></button>
                </div>
                <p class="qr-note" id="genNote">The code carries a random one-time token only. No guest details are encoded in it.</p>
            </div>

            <form class="qr-ref" id="refForm" autocomplete="off">
                <label class="label-small" for="refInput">Booking reference or QR token</label>
                <div class="qr-ref-row">
                    <input type="text" id="refInput" placeholder="e.g. BVO-0003" maxlength="120" spellcheck="false" autocapitalize="characters">
                    <button type="submit" class="btn btn-primary" id="btnRef"><span>Verify</span><?= icon('arrow') ?></button>
                </div>
                <p class="qr-hint">A booking reference looks the appointment up for a manual check-in. A pasted QR token is verified like a scan.</p>
            </form>
        </section>

        <section class="card qr-activity" aria-labelledby="actTitle">
            <div class="card-head">
                <div><p class="label-small">Live Log</p><h2 class="card-title" id="actTitle">Recent Check-in Activity</h2></div>
            </div>
            <ul class="qr-act-list" id="actList"><li class="empty">Loading activity…</li></ul>
        </section>

        <section class="card card-soft qr-protocol" aria-labelledby="protoTitle">
            <div class="qr-proto-head">
                <span class="qr-proto-ico"><?= icon('leaf') ?></span>
                <h2 class="qr-proto-title" id="protoTitle">Reception Instructions</h2>
            </div>
            <ol class="qr-steps">
                <li><strong>Scan the pass.</strong> Start the scanner and hold the guest's QR code in the frame, or type their booking reference.</li>
                <li><strong>Check the details.</strong> Make sure the name, treatment and time shown on the right match the guest in front of you.</li>
                <li><strong>Confirm arrival.</strong> Scanning never checks a guest in by itself: press Confirm to complete it.</li>
                <li><strong>No QR available?</strong> Use Manual Check-in, confirm the guest's identity, then complete the check-in. It is recorded as manual.</li>
            </ol>
            <p class="qr-policy" id="policyText">
                Check-in opens <strong id="polEarly"><?= (int)$policy['early'] ?></strong> min before and stays open <strong id="polLate"><?= (int)$policy['late'] ?></strong> min after the start time.
                <span id="polPending"><?= $policy['allow_pending'] ? 'Pending bookings may be checked in.' : 'Only confirmed bookings can be checked in.' ?></span>
                Arrivals outside the window need a staff acknowledgement.
            </p>
        </section>
    </div>

    <div class="qr-col qr-col-right">
        <section class="card qr-spot" aria-labelledby="spotTitle" id="spotCard">
            <div class="card-head">
                <div class="qr-spot-title">
                    <span class="card-head-ico"><?= icon('shield') ?></span>
                    <div><p class="label-small" id="spotKicker">Guest Verification</p><h2 class="card-title" id="spotTitle">Live Verification Spotlight</h2></div>
                </div>
                <span class="qr-pill" id="spotStatus" data-state="idle">Waiting for scan</span>
            </div>
            <div id="spotBody" aria-live="polite"></div>
        </section>
    </div>
</section>

<section class="card card-wide qr-monitor" aria-labelledby="monTitle">
    <div class="card-head manifest-head">
        <div>
            <p class="label-small">Reception Manifest</p>
            <h2 class="card-title" id="monTitle">Today's Arrival Monitor &amp; QR Activity</h2>
            <p class="card-sub" id="monSub">Arrivals, QR verification and check-in method for the selected date.</p>
        </div>
        <div class="manifest-tools qr-tools">
            <label class="qr-search"><?= icon('search') ?><input type="search" id="monSearch" placeholder="Search reference, guest, service…" aria-label="Search arrivals"></label>
            <input type="date" class="qr-ctl" id="monDate" aria-label="Arrival date">
            <button type="button" class="btn btn-outline btn-sm" id="monRefresh"><?= icon('refresh') ?><span>Refresh</span></button>
        </div>
    </div>

    <div class="filters" id="monFilters" role="tablist" aria-label="Arrival filters">
        <button type="button" class="filter is-active" data-filter="all">All Arrivals <span data-count="all">0</span></button>
        <button type="button" class="filter" data-filter="checked_in">Checked In <span data-count="checked_in">0</span></button>
        <button type="button" class="filter" data-filter="expected">Expected <span data-count="expected">0</span></button>
        <button type="button" class="filter" data-filter="pending_verification">Pending Verification <span data-count="pending_verification">0</span></button>
        <button type="button" class="filter" data-filter="manual">Manual Check-ins <span data-count="manual">0</span></button>
    </div>

    <div class="table-wrap" id="monWrap">
        <table class="manifest qr-table">
            <thead>
                <tr>
                    <th>Booking Reference</th><th>Guest</th><th>Service</th><th>Therapist</th><th>Appointment Time</th>
                    <th>QR Verification</th><th>Arrival Status</th><th>Check-in Method</th><th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="monBody"><tr class="empty-row"><td colspan="9">Loading arrivals…</td></tr></tbody>
        </table>
        <div class="loading" id="monLoading" hidden><span class="spinner"></span></div>
    </div>

    <div class="table-foot">
        <span id="monSummary"></span>
        <nav class="pager" id="monPager" aria-label="Arrival pages"></nav>
    </div>
</section>

<!-- Manual check-in search -->
<div class="modal" id="manualModal" role="dialog" aria-modal="true" aria-labelledby="manualTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div><p class="label-small">When the QR code is not available</p><h2 class="card-title" id="manualTitle">Manual Check-in</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="field">
            <label for="manualQ">Search by booking reference, appointment ID, guest name or contact number</label>
            <input type="search" id="manualQ" placeholder="e.g. BVO-0024, Camille, 0917 555…" maxlength="80" autocomplete="off">
        </div>
        <ul class="qr-results" id="manualResults"><li class="empty">Type at least two characters to search.</li></ul>
    </div>
</div>

<!-- Check-in window -->
<div class="modal" id="settingsModal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle" hidden>
    <div class="modal-card modal-sm">
        <form id="settingsForm" novalidate>
            <?= csrf_field() ?>
            <header class="modal-head">
                <div><p class="label-small">Reception policy</p><h2 class="card-title" id="settingsTitle">Check-in Window</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="confirm-text">Set how early and how late guests can be checked in relative to the appointment start time. Arrivals outside the window show a warning that staff must acknowledge.</p>
            <p class="form-error" id="settingsError" role="alert" hidden></p>
            <div class="qr-form-row">
                <div class="field"><label for="setEarly">Opens (minutes before start)</label><input type="number" id="setEarly" name="early_minutes" min="0" max="720" step="1" required></div>
                <div class="field"><label for="setLate">Closes (minutes after start)</label><input type="number" id="setLate" name="late_minutes" min="0" max="720" step="1" required></div>
            </div>
            <label class="qr-check"><input type="checkbox" id="setPending" name="allow_pending" value="1"><span>Allow guests with a <em>pending</em> (unconfirmed) booking to be checked in</span></label>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="settingsSave"><span>Save Window</span></button>
            </footer>
        </form>
    </div>
</div>

<!-- Generic confirm -->
<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" hidden>
    <div class="modal-card modal-sm">
        <header class="modal-head"><div><p class="label-small" id="confirmKicker">Please confirm</p><h2 class="card-title" id="confirmTitle">Confirm</h2></div></header>
        <p class="confirm-text" id="confirmText"></p>
        <footer class="modal-foot">
            <button type="button" class="btn btn-outline" id="confirmNo">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmYes">Continue</button>
        </footer>
    </div>
</div>

<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
