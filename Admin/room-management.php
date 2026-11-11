<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/rooms-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;

try {
    $ready         = rm_ready($pdo);
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO rooms] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load room management: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$pageTitle     = 'Room Management';
$crumb         = 'Room Management';
$footerScripts = $ready ? [url('/assets/js/room-management.js')] : [];
$cssFile       = __DIR__ . '/assets/css/room-management.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/room-management.css')) ?>?v=<?= e($cssVer) ?>">
<section class="page-head">
    <div>
        <p class="label-small">Sanctuary Suites · Climate Care</p>
        <h1 class="page-title">Sanctuary Treatment Suites &amp; Climate Control</h1>
        <p class="page-sub">Manage treatment room availability, monitor suite conditions, and maintain a comfortable sanctuary environment for every guest.</p>
    </div>
    <?php if ($ready): ?>
    <div class="page-actions">
        <button type="button" class="btn btn-outline" id="openLog"><?= icon('sliders') ?><span>Maintenance Log</span></button>
        <button type="button" class="btn btn-primary" id="openNewRoom"><?= icon('plus') ?><span>New Sanctuary Suite</span></button>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Room management tables are missing</h2>
    <p class="confirm-text">Import <strong>database/room-management-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It only adds new tables: room_details, room_climate_logs, room_maintenance_logs and climate_settings. Existing records are not touched. Then refresh this page.</p>
</section>
<?php else: ?>

<section class="stats stats-rm" aria-label="Suite statistics">
    <article class="stat"><div class="stat-top"><span class="label-small">Total Treatment Suites</span><span class="stat-ico"><?= icon('door') ?></span></div><p class="stat-value" id="sTotal">–</p><p class="stat-note">Registered sanctuary suites</p></article>
    <article class="stat stat-hl"><div class="stat-top"><span class="label-small">Available Suites</span><span class="stat-ico"><?= icon('check-ring') ?></span></div><p class="stat-value" id="sAvail">–</p><p class="stat-note">Ready for guests</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Cleaning Required</span><span class="stat-ico"><?= icon('refresh') ?></span></div><p class="stat-value" id="sClean">–</p><p class="stat-note">Linen &amp; sanitation reset</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Maintenance</span><span class="stat-ico"><?= icon('gear') ?></span></div><p class="stat-value" id="sMaint">–</p><p class="stat-note">Closed for repair</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Average Temperature</span><span class="stat-ico"><?= icon('pulse') ?></span></div><p class="stat-value" id="sTemp">–</p><p class="stat-note" id="sTempNote">Manually recorded</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Average Humidity</span><span class="stat-ico"><?= icon('leaf') ?></span></div><p class="stat-value" id="sHum">–</p><p class="stat-note" id="sHumNote">Manually recorded</p></article>
</section>

<section class="card card-wide rm-toolbar-card">
    <div class="appt-toolbar">
        <div class="tabs" id="rmTabs" role="tablist" aria-label="Suite filter">
            <button type="button" class="tab is-active" data-tab="all">All Treatment Suites <span class="count" data-count="all">0</span></button>
            <button type="button" class="tab" data-tab="available">Available <span class="count" data-count="available">0</span></button>
            <button type="button" class="tab" data-tab="cleaning">Cleaning <span class="count" data-count="cleaning">0</span></button>
            <button type="button" class="tab" data-tab="maintenance">Maintenance <span class="count" data-count="maintenance">0</span></button>
            <button type="button" class="tab" data-tab="climate">Climate Monitoring <span class="count" data-count="climate">0</span></button>
            <button type="button" class="tab" data-tab="attention">Needs Attention <span class="count" data-count="attention">0</span></button>
        </div>
        <div class="filters-row">
            <input type="search" class="ctl search-inline" id="rmSearch" placeholder="Search suite name or type" aria-label="Search suites">
            <select class="ctl" id="rmType" aria-label="Room type"><option value="">All room types</option></select>
            <select class="ctl" id="rmTemp" aria-label="Temperature status">
                <option value="">Any temperature</option>
                <option value="ok">Optimal</option>
                <option value="low">Too Cool</option>
                <option value="high">Too Warm</option>
                <option value="none">Not recorded</option>
            </select>
            <select class="ctl" id="rmHum" aria-label="Humidity status">
                <option value="">Any humidity</option>
                <option value="ok">Optimal</option>
                <option value="low">Too Dry</option>
                <option value="high">Too Humid</option>
                <option value="none">Not recorded</option>
            </select>
            <button type="button" class="btn btn-outline btn-sm" id="rmReset">Reset</button>
        </div>
        <p class="rm-legend" id="rmLegend"></p>
    </div>
</section>

<section class="room-grid" id="roomGrid" aria-live="polite"></section>

<section class="card" id="climateSection">
    <div class="card-head">
        <div>
            <p class="label-small">Climate Control · Manually Recorded</p>
            <h2 class="card-title">Suite Climate Conditions</h2>
            <p class="cell-sub" id="rangeText"></p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-outline btn-sm" id="openSettings">Recommended Ranges</button>
            <button type="button" class="btn btn-primary btn-sm" id="openClimate"><?= icon('plus') ?><span>Update Climate</span></button>
        </div>
    </div>
    <p class="rm-note">Values are entered by staff and are not live sensor readings. This system does not control air-conditioning equipment.</p>
    <div class="clim-grid" id="climateGrid"></div>
</section>

<section class="card card-wide" id="mtSection">
    <div class="appt-toolbar">
        <div>
            <p class="label-small">Housekeeping &amp; Environment</p>
            <h2 class="card-title">Sanctuary Suite Maintenance &amp; Temperature</h2>
        </div>
        <div class="filters-row">
            <input type="search" class="ctl search-inline" id="mtSearch" placeholder="Search suites, type or maintenance" aria-label="Search table">
            <select class="ctl" id="mtStatus" aria-label="Room status">
                <option value="">All statuses</option>
                <option value="available">Available</option>
                <option value="cleaning">Cleaning</option>
                <option value="maintenance">Maintenance</option>
            </select>
        </div>
    </div>
    <div class="table-wrap">
        <table class="manifest appt-table mt-table">
            <thead>
                <tr>
                    <th><button type="button" class="th-sort" data-sort="name">Suite Name</button></th>
                    <th><button type="button" class="th-sort" data-sort="type">Room Type</button></th>
                    <th><button type="button" class="th-sort" data-sort="temp">Temperature</button></th>
                    <th><button type="button" class="th-sort" data-sort="hum">Humidity</button></th>
                    <th><button type="button" class="th-sort" data-sort="maint">Last Maintenance</button></th>
                    <th>Maintenance Details</th>
                    <th><button type="button" class="th-sort" data-sort="status">Room Status</button></th>
                </tr>
            </thead>
            <tbody id="mtBody"></tbody>
        </table>
    </div>
    <div class="table-foot"><span id="mtFoot"></span><button type="button" class="btn btn-outline btn-sm" id="mtLog">Open Maintenance Log</button></div>
</section>

<div class="modal" id="suiteModal" role="dialog" aria-modal="true" aria-labelledby="suiteTitle" hidden>
    <div class="modal-card">
        <form id="suiteForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="su_action" value="create_room">
            <input type="hidden" name="id" id="su_id" value="">
            <header class="modal-head">
                <div><p class="label-small" id="suiteKicker">Sanctuary Suite</p><h2 class="card-title" id="suiteTitle">New Sanctuary Suite</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="suiteError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="su_name">Room Name</label><input type="text" id="su_name" name="room_name" maxlength="150" required></div>
                <div class="field"><label for="su_type">Room Type</label><input type="text" id="su_type" name="room_type" maxlength="100" list="typeList" required></div>
                <div class="field"><label for="su_status">Room Status</label>
                    <select id="su_status" name="room_status"><option value="available">Available</option><option value="cleaning">Cleaning</option><option value="maintenance">Maintenance</option></select></div>
                <div class="field field-full"><label for="su_desc">Room Description</label><textarea id="su_desc" name="description" rows="2" maxlength="1000"></textarea></div>
                <div class="field"><label for="su_amen">Amenities</label><input type="text" id="su_amen" name="amenities" maxlength="500" placeholder="Separate with commas"></div>
                <div class="field"><label for="su_cap">Maximum Capacity</label><input type="number" id="su_cap" name="maximum_capacity" min="1" max="20" step="1"></div>
            </div>
            <datalist id="typeList"></datalist>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="suiteSubmit"><span>Save Suite</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="statusModal" role="dialog" aria-modal="true" aria-labelledby="statusTitle" hidden>
    <div class="modal-card modal-sm">
        <form id="statusForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" id="st_id">
            <header class="modal-head">
                <div><p class="label-small" id="statusKicker">Update Status</p><h2 class="card-title" id="statusTitle">Suite Status</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="statusError" role="alert" hidden></p>
            <div class="field"><label for="st_status">Room Status</label>
                <select id="st_status" name="room_status"><option value="available">Available</option><option value="cleaning">Cleaning</option><option value="maintenance">Maintenance</option></select></div>
            <p class="cell-sub">Suites under maintenance or cleaning cannot be booked.</p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="statusSubmit"><span>Update Status</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="climateModal" role="dialog" aria-modal="true" aria-labelledby="climateTitle" hidden>
    <div class="modal-card">
        <form id="climateForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_climate">
            <header class="modal-head">
                <div><p class="label-small">Manual Observation</p><h2 class="card-title" id="climateTitle">Update Suite Climate</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="climateError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="cl_room">Select Room</label><select id="cl_room" name="room_id" required></select></div>
                <div class="field"><label for="cl_temp">Temperature (°C)</label><input type="number" id="cl_temp" name="temperature" step="0.1" min="10" max="45" required></div>
                <div class="field"><label for="cl_hum">Humidity (%)</label><input type="number" id="cl_hum" name="humidity" step="0.1" min="0" max="100" required></div>
                <div class="field"><label for="cl_date">Observation Date</label><input type="date" id="cl_date" name="observation_date" required></div>
                <div class="field"><label for="cl_time">Observation Time</label><input type="time" id="cl_time" name="observation_time" required></div>
                <div class="field field-full"><label for="cl_notes">Condition Notes</label><textarea id="cl_notes" name="condition_notes" rows="2" maxlength="500"></textarea></div>
            </div>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="climateSubmit"><span>Save Update</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="settingsModal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle" hidden>
    <div class="modal-card modal-sm">
        <form id="settingsForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <header class="modal-head">
                <div><p class="label-small">Climate Settings</p><h2 class="card-title" id="settingsTitle">Recommended Ranges</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="settingsError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field"><label for="se_tmin">Min Temp (°C)</label><input type="number" id="se_tmin" name="min_temp" step="0.1" required></div>
                <div class="field"><label for="se_tmax">Max Temp (°C)</label><input type="number" id="se_tmax" name="max_temp" step="0.1" required></div>
                <div class="field"><label for="se_hmin">Min Humidity (%)</label><input type="number" id="se_hmin" name="min_hum" step="0.1" required></div>
                <div class="field"><label for="se_hmax">Max Humidity (%)</label><input type="number" id="se_hmax" name="max_hum" step="0.1" required></div>
            </div>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="settingsSubmit"><span>Save Ranges</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="maintModal" role="dialog" aria-modal="true" aria-labelledby="maintTitle" hidden>
    <div class="modal-card">
        <form id="maintForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_maintenance">
            <input type="hidden" name="id" id="mn_id" value="">
            <header class="modal-head">
                <div><p class="label-small" id="maintKicker">Maintenance</p><h2 class="card-title" id="maintTitle">Record Maintenance</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="maintError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="mn_room">Suite</label><select id="mn_room" name="room_id" required></select></div>
                <div class="field field-full"><label for="mn_title">Issue</label><input type="text" id="mn_title" name="maintenance_title" maxlength="150" required></div>
                <div class="field field-full"><label for="mn_desc">Maintenance Description</label><textarea id="mn_desc" name="description" rows="3" maxlength="2000"></textarea></div>
                <div class="field"><label for="mn_date">Maintenance Date</label><input type="date" id="mn_date" name="scheduled_date" required></div>
                <div class="field"><label for="mn_status">Status</label>
                    <select id="mn_status" name="maintenance_status"><option value="scheduled">Scheduled</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select></div>
            </div>
            <p class="cell-sub">In Progress marks the suite as under maintenance so it cannot be booked. Completing a record does not reopen the suite; verify it, then mark it available.</p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="maintSubmit"><span>Save Record</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="logModal" role="dialog" aria-modal="true" aria-labelledby="logTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small">Maintenance</p><h2 class="card-title" id="logTitle">Maintenance Log</h2></div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary btn-sm" id="logAdd"><?= icon('plus') ?><span>Add Record</span></button>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </div>
        </header>
        <div class="filters-row log-filters">
            <select class="ctl" id="logRoom" aria-label="Suite"><option value="">All suites</option></select>
            <select class="ctl" id="logStatus" aria-label="Status"><option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select>
        </div>
        <div class="log-list" id="logList"></div>
    </div>
</div>

<div class="modal" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="detailTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small" id="detailKicker">Suite Details</p><h2 class="card-title" id="detailTitle">Suite</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="detailBody"></div>
    </div>
</div>

<div class="modal" id="scheduleModal" role="dialog" aria-modal="true" aria-labelledby="scheduleTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small" id="scheduleKicker">Suite Schedule</p><h2 class="card-title" id="scheduleTitle">Schedule</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="scheduleBody"></div>
    </div>
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>