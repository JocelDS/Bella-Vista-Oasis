<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/dashboard-data.php';
require_once __DIR__ . '/includes/settings-data.php';
require_once __DIR__ . '/includes/account-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;
$data    = ['admin' => [], 'profile' => [], 'prefs' => []];
$audit   = [];
$passAt  = null;
$remembered = false;

try {
    $notifications = get_notifications($pdo, $adminId);
    $ready = acc_ready($pdo);
    if ($ready) {
        $data       = acc_load($pdo, $adminId);
        $audit      = acc_audit($pdo, $adminId);
        $passAt     = acc_last_event($pdo, $adminId, 'passphrase_rotated');
        $remembered = acc_has_remembered($pdo, $adminId);
    }
} catch (Throwable $e) {
    error_log('[BVO account page] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load account settings. Check the PHP error log.');
}

$a      = $data['admin'];
$p      = $data['profile'];
$pref   = $data['prefs'];
$avatar = $ready ? acc_avatar_url($p['avatar'] ?? '') : null;
$canConfig = sc_allowed_admin();
$ua     = acc_browser((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$allNotifs = [];
if ($ready) {
    $st = $pdo->prepare('SELECT title, message, created_at FROM notifications WHERE admin_id IS NULL OR admin_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([$adminId]);
    $allNotifs = $st->fetchAll();
}

$pageTitle     = 'Account Settings';
$crumb         = 'Account Settings';
$footerScripts = $ready ? [url('/assets/js/account-settings.js')] : [];
$cssFiles      = ['settings.css', 'account-settings.css'];
require __DIR__ . '/includes/header.php';

foreach ($cssFiles as $f) {
    $path = __DIR__ . '/assets/css/' . $f;
    echo '<link rel="stylesheet" href="' . e(url('/assets/css/' . $f)) . '?v=' . e(is_file($path) ? (string)filemtime($path) : '1') . '">' . "\n";
}

function acc_switch(string $key, bool $checked, string $label): string
{
    return '<label class="sc-switch"><input type="checkbox" data-pref="' . e($key) . '"' . ($checked ? ' checked' : '') . ' aria-label="' . e($label) . '"><span class="sc-track" aria-hidden="true"></span></label>';
}
?>
<section class="page-head acc-head">
    <div>
        <div class="acc-pills">
            <span class="acc-pill"><?= e((string)($a['role'] ?? 'Administrator')) ?> Governance</span>
            <span class="acc-pill acc-pill-soft">Signed in as <?= e((string)($a['email'] ?? '')) ?></span>
        </div>
        <h1 class="page-title">Account &amp; Sanctuary Settings</h1>
        <p class="page-sub">Manage your credentials, operational shift preferences, security permissions and alert dispatches.</p>
    </div>
    <?php if ($ready): ?>
    <div class="acc-head-actions">
        <div class="sc-meta-card">
            <p class="label-small">Access Level</p>
            <p class="sc-meta-value"><?= e((string)($a['role'] ?? 'Administrator')) ?></p>
        </div>
        <button type="button" class="btn btn-primary" id="accSaveTop"><?= icon('check') ?><span>Save Changes</span></button>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Account tables could not be created</h2>
    <p class="confirm-text">The page creates <strong>admin_profiles</strong>, <strong>admin_preferences</strong> and <strong>admin_audit_log</strong> inside bella_vista_oasis automatically. Make sure the MySQL user has CREATE permission, then refresh.</p>
</section>
<?php else: ?>

<nav class="sc-tabs" role="tablist" aria-label="Account sections">
    <button type="button" class="sc-tab is-active" role="tab" aria-selected="true" data-tab="profile"><?= icon('user') ?><span>Profile &amp; Security</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="notifications"><?= icon('bell') ?><span>Notification Preferences</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="shift"><?= icon('users') ?><span>Sanctuary Shift &amp; Staff</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="billing"><?= icon('wallet') ?><span>Billing &amp; Fiscal Preferences</span></button>
    <button type="button" class="sc-tab" role="tab" aria-selected="false" data-tab="audit"><?= icon('clipboard') ?><span>System Audit Log</span></button>
</nav>

<form id="accForm" autocomplete="off" novalidate>

<!-- ============ TAB · PROFILE & SECURITY ============ -->
<section class="sc-panel" data-panel="profile" role="tabpanel">
    <div class="sc-cols">
        <div class="sc-stack">

            <article class="card">
                <div class="card-head"><div><p class="label-small">Credential Authority</p><h2 class="card-title">Executive Identity</h2></div><span class="sc-tag" id="accDirty" hidden>Unsaved changes</span></div>

                <div class="acc-avatar-row">
                    <div class="acc-avatar" id="accAvatar">
                        <img id="accAvatarImg" src="<?= $avatar ? e($avatar) : '' ?>" alt="Profile photo" <?= $avatar ? '' : 'hidden' ?>>
                        <span id="accAvatarInit" <?= $avatar ? 'hidden' : '' ?>><?= e(name_initials((string)($a['full_name'] ?? ''))) ?></span>
                    </div>
                    <div>
                        <p class="acc-avatar-name" id="accNameLive"><?= e((string)($a['full_name'] ?? '')) ?></p>
                        <p class="sc-note">Your photo appears on this page. JPG, PNG or WebP, up to 2 MB.</p>
                        <div class="acc-avatar-actions">
                            <button type="button" class="btn btn-soft btn-sm" id="accPhotoBtn">Change Photo</button>
                            <button type="button" class="btn btn-outline btn-sm" id="accPhotoRemove" <?= $avatar ? '' : 'hidden' ?>>Remove</button>
                            <input type="file" id="accPhotoInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </div>
                    </div>
                </div>

                <div class="form-grid acc-grid">
                    <div class="field"><label for="accName">Full Legal Name</label><input type="text" id="accName" data-profile="full_name" maxlength="120" value="<?= e((string)($a['full_name'] ?? '')) ?>"><p class="acc-err" data-err="full_name"></p></div>
                    <div class="field"><label for="accTitle">Designation / Role Title</label><input type="text" id="accTitle" data-profile="designation" maxlength="120" placeholder="e.g. Spa General Manager" value="<?= e($p['designation']) ?>"><p class="acc-err" data-err="designation"></p></div>
                    <div class="field"><label for="accEmail">Executive Direct Email</label><input type="email" id="accEmail" data-profile="email" maxlength="190" value="<?= e((string)($a['email'] ?? '')) ?>" data-original="<?= e((string)($a['email'] ?? '')) ?>"><p class="acc-err" data-err="email"></p></div>
                    <div class="field">
                        <label for="accPhone">Direct Extension &amp; Concierge Hotline</label>
                        <div class="acc-split">
                            <input type="text" id="accPhone" data-profile="phone" maxlength="40" placeholder="+63 900 000 0000" value="<?= e($p['phone']) ?>" aria-label="Concierge hotline">
                            <input type="text" id="accExt" data-profile="extension" maxlength="10" inputmode="numeric" placeholder="Ext." value="<?= e($p['extension']) ?>" aria-label="Direct extension">
                        </div>
                        <p class="acc-err" data-err="phone"></p><p class="acc-err" data-err="extension"></p>
                    </div>
                    <div class="field field-full">
                        <label>Consult Alerts &amp; Language Localizations</label>
                        <div class="acc-chips" id="accLangs">
                            <?php foreach (ACC_LANGUAGES as $lang): $on = in_array($lang, $p['languages'], true); ?>
                                <button type="button" class="acc-chip<?= $on ? ' is-on' : '' ?>" data-lang="<?= e($lang) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($lang) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="acc-err" data-err="languages"></p>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head"><div><p class="label-small">Fortified Protocol</p><h2 class="card-title">Security &amp; Hardware Access</h2></div><span class="sc-tag">Admin controlled</span></div>

                <div class="acc-sec-grid">
                    <div class="acc-sec-card">
                        <div class="acc-sec-top"><?= icon('shield') ?><?= acc_switch('two_factor', ($pref['two_factor'] ?? '0') === '1', 'Two-factor authentication preference') ?></div>
                        <h3>Two-Factor Authentication</h3>
                        <p class="sc-note">Saves your preference to require a second step at sign-in. Enforcement at login is not active yet.</p>
                    </div>
                    <div class="acc-sec-card">
                        <div class="acc-sec-top"><?= icon('clock') ?>
                            <select id="accIdle" data-pref="idle_minutes" aria-label="Inactivity screen guard">
                                <?php foreach (ACC_IDLE_OPTIONS as $m): ?>
                                    <option value="<?= $m ?>" <?= (int)($pref['idle_minutes'] ?? 0) === $m ? 'selected' : '' ?>><?= $m === 0 ? 'Off' : $m . ' min' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <h3>Inactivity Screen Guard</h3>
                        <p class="sc-note">Signs you out of this console after the chosen idle time on any admin page.</p>
                    </div>
                </div>

                <div class="acc-row">
                    <div>
                        <p class="acc-row-title">Master Sanctuary Passphrase</p>
                        <p class="sc-note"><?= $passAt ? 'Last rotated ' . e(acc_when($passAt)) : 'Not rotated since account tracking began' ?></p>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="accPassBtn">Update Passphrase</button>
                </div>

                <div class="acc-row">
                    <div>
                        <p class="acc-row-title"><?= e($ua) ?> <span class="sc-tag">This session</span></p>
                        <p class="sc-note">IP <?= e(client_ip()) ?> · <?= $remembered ? 'A remembered-device sign-in is active' : 'No remembered devices' ?></p>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="accRevokeBtn" <?= $remembered ? '' : 'disabled' ?>>Sign Out Remembered Devices</button>
                </div>
            </article>

            <article class="card">
                <div class="card-head"><div><p class="label-small">Quiet Telemetry</p><h2 class="card-title">Shift Dispatch &amp; Sanctuary Alerts</h2></div><span class="sc-tag">Saved per admin</span></div>
                <div class="acc-alerts">
                    <?php foreach (ACC_ALERTS as $key => [$title, $badge, $desc]): ?>
                        <div class="acc-alert">
                            <div>
                                <p class="acc-row-title"><?= e($title) ?> <?php if ($badge): ?><span class="sc-tag"><?= e($badge) ?></span><?php endif; ?></p>
                                <p class="sc-note"><?= e($desc) ?></p>
                            </div>
                            <?= acc_switch($key, ($pref[$key] ?? '1') === '1', $title) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </div>

        <aside class="sc-stack">
            <article class="card">
                <div class="card-head"><div><p class="label-small">Visual Preferences</p><h2 class="card-title">Locale &amp; Environment</h2></div></div>
                <div class="field"><label>Base Currency</label><div class="acc-static">Philippine Peso (₱ PHP)</div></div>
                <div class="field"><label>Temporal Zone &amp; Daylight Calibration</label><div class="acc-static"><?= e(date_default_timezone_get()) ?> (<?= e(date('T')) ?>, UTC<?= e(date('P')) ?>)</div></div>
                <div class="field"><label for="accDensity">Console Aesthetic Mode</label>
                    <select id="accDensity" data-pref="density">
                        <?php foreach (ACC_DENSITY_OPTIONS as $v => $label): ?>
                            <option value="<?= e($v) ?>" <?= ($pref['density'] ?? 'comfortable') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="acc-err" data-err="density"></p>
                </div>
                <p class="sc-note">Currency and time zone are set for the whole sanctuary in the system configuration.</p>
            </article>

            <article class="card">
                <div class="card-head"><div><p class="label-small">Access Privileges</p><h2 class="card-title">Executive Powers</h2></div></div>
                <ul class="acc-powers">
                    <?php foreach (ACC_MODULES as [$label, $file]):
                        $exists  = is_file(__DIR__ . '/' . $file);
                        $granted = $exists && ($file !== 'settings.php' || $canConfig); ?>
                        <li class="<?= $granted ? 'is-on' : 'is-off' ?>"><?= icon($granted ? 'check' : 'close') ?><span><?= e($label) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="sc-note">Member since <?= e(!empty($a['created_at']) ? date('M j, Y', strtotime((string)$a['created_at'])) : '—') ?></p>
            </article>

            <article class="card acc-photo-card">
                <img src="<?= e(url('/assets/images/dashboard-banner.jpg')) ?>" alt="Bella Vista Oasis sanctuary">
                <div class="acc-photo-cap"><p class="label-small">Bella Vista Oasis</p><p>Sanctuary presence</p></div>
            </article>
        </aside>
    </div>
</section>

<!-- ============ TAB · NOTIFICATIONS ============ -->
<section class="sc-panel" data-panel="notifications" role="tabpanel" hidden>
    <article class="card">
        <div class="card-head"><div><p class="label-small">Recent Notices</p><h2 class="card-title">Notification History</h2></div></div>
        <?php if (!$allNotifs): ?>
            <p class="confirm-text">You're all caught up.</p>
        <?php else: ?>
            <div class="acc-list">
                <?php foreach ($allNotifs as $n): ?>
                    <div class="acc-list-item">
                        <p class="acc-row-title"><?= e((string)$n['title']) ?></p>
                        <?php if (!empty($n['message'])): ?><p class="sc-note"><?= e((string)$n['message']) ?></p><?php endif; ?>
                        <p class="sc-note"><?= e(dash_time_ago($n['created_at'] ?? null)) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="sc-note">Choose which alerts you receive under Shift Dispatch &amp; Sanctuary Alerts in the Profile &amp; Security tab.</p>
    </article>
</section>

<!-- ============ TAB · SHIFT & STAFF ============ -->
<section class="sc-panel" data-panel="shift" role="tabpanel" hidden>
    <article class="card">
        <div class="card-head"><div><p class="label-small">Operations</p><h2 class="card-title">Sanctuary Shift &amp; Staff</h2></div></div>
        <p class="confirm-text">Therapist shifts, leave and capacity are managed in Therapist Assignment. Staff rules and operating hours live in System Configuration.</p>
        <div class="acc-links">
            <a class="btn btn-soft" href="<?= e(url('/therapist-assignment.php')) ?>"><?= icon('users') ?><span>Therapist Assignment</span></a>
            <?php if ($canConfig): ?><a class="btn btn-outline" href="<?= e(url('/settings.php')) ?>"><?= icon('gear') ?><span>System Configuration</span></a><?php endif; ?>
        </div>
    </article>
</section>

<!-- ============ TAB · BILLING ============ -->
<section class="sc-panel" data-panel="billing" role="tabpanel" hidden>
    <article class="card">
        <div class="card-head"><div><p class="label-small">Fiscal</p><h2 class="card-title">Billing &amp; Fiscal Preferences</h2></div></div>
        <div class="form-grid acc-grid">
            <div class="field"><label>Base Currency</label><div class="acc-static">Philippine Peso (₱ PHP)</div></div>
            <div class="field"><label>Fiscal Time Zone</label><div class="acc-static"><?= e(date_default_timezone_get()) ?></div></div>
        </div>
        <p class="confirm-text">Payments, receipts and reconciliation are handled in Payment Management. Room rates are set in System Configuration.</p>
        <div class="acc-links">
            <a class="btn btn-soft" href="<?= e(url('/payments.php')) ?>"><?= icon('wallet') ?><span>Payment Management</span></a>
            <?php if ($canConfig): ?><a class="btn btn-outline" href="<?= e(url('/settings.php')) ?>"><?= icon('gear') ?><span>Room Rates</span></a><?php endif; ?>
        </div>
    </article>
</section>

<!-- ============ TAB · AUDIT ============ -->
<section class="sc-panel" data-panel="audit" role="tabpanel" hidden>
    <article class="card">
        <div class="card-head"><div><p class="label-small">Accountability</p><h2 class="card-title">System Audit Log</h2></div><span class="sc-tag">Your actions</span></div>
        <?php if (!$audit): ?>
            <p class="confirm-text">No account or configuration changes recorded for you yet.</p>
        <?php else: ?>
            <div class="sc-table-wrap">
                <table class="sc-table" aria-label="Audit log">
                    <thead><tr><th>When</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $r): ?>
                        <tr><td><?= e(acc_when((string)$r['when'])) ?></td><td><?= e($r['what']) ?></td><td><?= e($r['detail']) ?></td><td><?= e($r['ip'] ?: '—') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>

<div class="acc-bar" id="accBar">
    <div class="acc-bar-text">
        <?= icon('shield') ?>
        <span>Bella Vista Sanctuary Executive Compliance<small>Changes are recorded in your audit log.</small></span>
    </div>
    <div class="acc-bar-actions">
        <button type="button" class="btn btn-outline" id="accDiscard">Discard Draft</button>
        <button type="button" class="btn btn-primary" id="accCommit">Commit Changes</button>
    </div>
</div>
</form>

<!-- Passphrase modal -->
<div class="modal" id="accPassModal" hidden>
    <div class="modal-card modal-sm" role="dialog" aria-modal="true" aria-labelledby="accPassTitle">
        <div class="modal-head"><div><p class="label-small">Security</p><h2 class="card-title" id="accPassTitle">Update Master Passphrase</h2></div><button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button></div>
        <p class="form-error" id="accPassErr" hidden></p>
        <div class="form-grid">
            <div class="field field-full"><label for="accPassCur">Current passphrase</label><input type="password" id="accPassCur" autocomplete="current-password"></div>
            <div class="field field-full"><label for="accPassNew">New passphrase</label><input type="password" id="accPassNew" autocomplete="new-password"><p class="sc-note">8+ characters with an uppercase letter, a lowercase letter and a number.</p></div>
            <div class="field field-full"><label for="accPassCon">Confirm new passphrase</label><input type="password" id="accPassCon" autocomplete="new-password"></div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button type="button" class="btn btn-primary" id="accPassSave">Update Passphrase</button></div>
    </div>
</div>

<!-- Email confirmation modal -->
<div class="modal" id="accConfirmModal" hidden>
    <div class="modal-card modal-sm" role="dialog" aria-modal="true" aria-labelledby="accConfTitle">
        <div class="modal-head"><div><p class="label-small">Confirm identity</p><h2 class="card-title" id="accConfTitle">Confirm Login Email Change</h2></div><button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button></div>
        <p class="confirm-text">Enter your master passphrase to change the email you sign in with.</p>
        <p class="form-error" id="accConfErr" hidden></p>
        <div class="field"><label for="accConfPass">Master passphrase</label><input type="password" id="accConfPass" autocomplete="current-password"></div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button type="button" class="btn btn-primary" id="accConfOk">Confirm &amp; Save</button></div>
    </div>
</div>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>