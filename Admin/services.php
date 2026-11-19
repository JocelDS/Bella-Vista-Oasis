<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/services-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];

try {
    $services      = sv_list($pdo);
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO services] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load services: ' . e($e->getMessage()));
}

$total      = count($services);
$bookable   = 0;
$noChamber  = [];
foreach ($services as $s) {
    if ($s['room_open'] > 0) {
        $bookable++;
    } else {
        $noChamber[] = $s;
    }
}
$prices   = array_column($services, 'price');
$fromPrice = $prices ? min($prices) : 0.0;

$pageTitle     = 'Services Management';
$crumb         = 'Services Management';
$footerScripts = [url('/assets/js/services.js'), url('/assets/js/room-addons.js')];
$cssFile       = __DIR__ . '/assets/css/services.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
$addonCss      = __DIR__ . '/assets/css/service-addons.css';
$addonCssVer   = is_file($addonCss) ? (string)filemtime($addonCss) : '1';
$extraStyles   = [
    url('/assets/css/services.css') . '?v=' . $cssVer,
    url('/assets/css/service-addons.css') . '?v=' . $addonCssVer,
];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <div>
        <p class="label-small">Treatments · Rates · Guests</p>
        <h1 class="page-title">Services Management</h1>
        <p class="page-sub">Manage every spa treatment guests can book online: name, description, price, duration, compatible room code, base guests and maximum guests. Physical rooms are managed separately in <a href="<?= e(url('/room-management.php')) ?>">Room Management</a>.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline" href="#spaAddonsSection"><span>Spa Ritual Add-ons</span></a>
        <button type="button" class="btn btn-primary" id="svNew"><?= icon('plus') ?><span>New Treatment</span></button>
    </div>
</section>

<?php if ($total === 0): ?>
<section class="card sv-alert sv-alert-warn" role="alert">
    <p class="label-small">No Treatments Yet</p>
    <h2 class="card-title">Guests cannot book until you add a treatment</h2>
    <p class="sv-text">The customer booking page lists the treatments saved here. Add your first treatment, then make sure a chamber with the same code exists in <a href="<?= e(url('/room-management.php')) ?>">Room Management</a>.</p>
</section>
<?php elseif ($noChamber): ?>
<section class="card sv-alert sv-alert-warn" role="alert">
    <p class="label-small">Needs Attention</p>
    <h2 class="card-title"><?= count($noChamber) === 1 ? 'One treatment has no bookable chamber' : count($noChamber) . ' treatments have no bookable chamber' ?></h2>
    <p class="sv-text">
        <?php
        $names = array_map(static fn(array $s): string => $s['service_name'] . ' (' . $s['room_code'] . '-1)', $noChamber);
        echo e(implode(', ', $names));
        ?>
        will not appear on the customer booking page until a chamber with that code is available in <a href="<?= e(url('/room-management.php')) ?>">Room Management</a>.
    </p>
</section>
<?php endif; ?>

<section class="stats sv-stats" aria-label="Treatment statistics">
    <article class="stat">
        <div class="stat-top"><span class="label-small">Total Treatments</span><span class="stat-ico"><?= icon('bed') ?></span></div>
        <p class="stat-value"><?= $total ?></p>
        <p class="stat-note">Saved in the system</p>
    </article>
    <article class="stat stat-hl">
        <div class="stat-top"><span class="label-small">Open For Booking</span><span class="stat-ico"><?= icon('check-ring') ?></span></div>
        <p class="stat-value"><?= $bookable ?></p>
        <p class="stat-note">Visible to guests online</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Not Bookable</span><span class="stat-ico"><?= icon('door') ?></span></div>
        <p class="stat-value"><?= count($noChamber) ?></p>
        <p class="stat-note">No available chamber</p>
    </article>
    <article class="stat">
        <div class="stat-top"><span class="label-small">Starting Rate</span><span class="stat-ico"><?= icon('wallet') ?></span></div>
        <p class="stat-value"><?= $total ? e(sv_peso($fromPrice)) : '–' ?></p>
        <p class="stat-note">Lowest treatment price</p>
    </article>
</section>

<section class="card card-wide">
    <div class="card-head manifest-head">
        <div>
            <p class="label-small">Treatment Catalogue</p>
            <h2 class="card-title">All Treatments</h2>
        </div>
    </div>
    <?php if ($total === 0): ?>
    <p class="sv-empty">No treatments have been added yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="manifest sv-table">
            <thead>
                <tr>
                    <th>Treatment</th>
                    <th>Room Code</th>
                    <th>Duration</th>
                    <th>Guests</th>
                    <th class="num">Price</th>
                    <th>Compatible Rooms</th>
                    <th>Bookings</th>
                    <th class="num">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $s):
                    $payload = [
                        'id'            => $s['id'],
                        'service_name'  => $s['service_name'],
                        'room_code'     => $s['room_code'],
                        'description'   => (string)$s['description'],
                        'duration'      => $s['duration'],
                        'price'         => $s['price'],
                        'base_capacity' => $s['base_capacity'],
                        'max_capacity'  => $s['max_capacity'],
                        'bookings'      => $s['bookings'],
                    ];
                    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $guests = $s['base_capacity'] === $s['max_capacity'] ? (string)$s['base_capacity'] : $s['base_capacity'] . ' to ' . $s['max_capacity'];
                    ?>
                <tr>
                    <td data-label="Treatment">
                        <p class="cell-title"><?= e($s['service_name']) ?></p>
                        <?php if ((string)$s['description'] !== ''): ?><p class="cell-sub sv-desc"><?= e((string)$s['description']) ?></p><?php endif; ?>
                    </td>
                    <td data-label="Room Code"><span class="sv-code"><?= e($s['room_code']) ?></span></td>
                    <td data-label="Duration"><?= e(sv_duration_label($s['duration'])) ?></td>
                    <td data-label="Guests">
                        <?= e($guests) ?>
                        <p class="cell-sub">Base <?= (int)$s['base_capacity'] ?> · Max <?= (int)$s['max_capacity'] ?></p>
                    </td>
                    <td class="num" data-label="Price"><?= e(sv_peso($s['price'])) ?></td>
                    <td data-label="Compatible Rooms">
                        <?php if ($s['room_open'] > 0): ?>
                            <span class="badge rs-available"><span class="dot"></span><?= $s['room_open'] ?> open</span>
                            <?php if ($s['room_total'] > $s['room_open']): ?><p class="cell-sub"><?= $s['room_total'] ?> total</p><?php endif; ?>
                        <?php elseif ($s['room_total'] > 0): ?>
                            <span class="badge rs-maintenance"><span class="dot"></span>All unavailable</span>
                        <?php else: ?>
                            <span class="badge rs-maintenance"><span class="dot"></span>No chamber</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Bookings"><?= $s['bookings'] ?></td>
                    <td class="num" data-label="Actions">
                        <div class="sv-actions">
                            <button type="button" class="btn btn-outline btn-sm" data-edit="<?= e((string)$json) ?>">Edit</button>
                            <button type="button" class="btn btn-outline btn-sm btn-danger-soft" data-delete="<?= e((string)$json) ?>">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <p class="sv-foot">A treatment appears on the customer booking page only when at least one chamber in <a href="<?= e(url('/room-management.php')) ?>">Room Management</a> is named with its code, such as <strong>SR-1</strong> for code <strong>SR</strong>.</p>
</section>

<section class="card addons-panel" id="spaAddonsSection" aria-labelledby="addonsTitle">
    <div class="addons-head">
        <div class="addons-heading">
            <span class="addons-icon" aria-hidden="true">✧</span>
            <div>
                <p class="label-small">Enhancements · Rituals</p>
                <h2 class="card-title" id="addonsTitle">Spa Ritual Add-ons</h2>
                <p class="addons-intro">Optional enhancements guests can pair with a treatment, such as a hot stone upgrade or aromatherapy. Active add-ons appear on the customer Services page and can be charged on a guest folio in Payments.</p>
            </div>
        </div>
        <button type="button" class="btn btn-primary" id="addonAddBtn"><?= icon('plus') ?><span>New Add-on</span></button>
    </div>
    <div class="addons-summary">
        <div><span class="addons-summary-value" id="addonTotal">0</span><span class="addons-summary-label">Total add-ons</span></div>
        <div><span class="addons-summary-value" id="addonActive">0</span><span class="addons-summary-label">Visible to guests</span></div>
        <div><span class="addons-summary-value" id="addonInactive">0</span><span class="addons-summary-label">Hidden from guests</span></div>
    </div>
    <div class="addons-toolbar">
        <label class="addons-search-wrap" for="addonSearch">
            <span aria-hidden="true"><?= icon('search') ?></span>
            <input type="search" id="addonSearch" placeholder="Search add-ons" autocomplete="off">
        </label>
        <select id="addonStatusFilter" aria-label="Filter add-ons by status">
            <option value="all">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="addons-grid" id="addonGrid"></div>
    <p class="addons-footnote">Commissionable add-ons count toward therapist commission using the add-on commission rate in Settings. Deactivating an add-on hides it from guests and new folios without removing past charges.</p>
</section>

<div class="modal" id="addonModal" role="dialog" aria-modal="true" aria-labelledby="addonModalTitle" hidden>
    <div class="modal-card addon-modal-card">
        <form id="addonForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="addonAction" value="create">
            <input type="hidden" name="id" id="addonId" value="">
            <header class="modal-head">
                <div><p class="label-small">Spa Ritual</p><h2 class="card-title" id="addonModalTitle">New Spa Ritual Add-on</h2></div>
                <button type="button" class="icon-btn" data-addon-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="addonError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="addonName">Add-on Name</label><input type="text" id="addonName" name="addon_name" maxlength="120" required></div>
                <div class="field field-full"><label for="addonDescription">Description</label><textarea id="addonDescription" name="description" rows="3" maxlength="500"></textarea></div>
                <div class="field"><label for="addonPrice">Price (₱)</label><input type="number" id="addonPrice" name="price" min="0" max="999999" step="0.01" required></div>
                <div class="field"><label for="addonSort">Display Order</label><input type="number" id="addonSort" name="sort_order" min="0" max="9999" step="1" value="0"></div>
                <div class="field field-full">
                    <div class="addon-checkboxes">
                        <label class="addon-check"><input type="checkbox" id="addonCommissionable" name="commissionable" value="1"><span><strong>Commissionable</strong><small>Counts toward therapist commission.</small></span></label>
                        <label class="addon-check"><input type="checkbox" id="addonIsActive" name="is_active" value="1" checked><span><strong>Active</strong><small>Visible to guests and available on folios.</small></span></label>
                    </div>
                </div>
            </div>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" data-addon-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="addonSaveBtn">Save Add-on</button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="svModal" role="dialog" aria-modal="true" aria-labelledby="svTitle" hidden>
    <div class="modal-card">
        <form id="svForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="svAction" value="create_service">
            <input type="hidden" name="id" id="svId" value="">
            <header class="modal-head">
                <div><p class="label-small" id="svKicker">New Service</p><h2 class="card-title" id="svTitle">Add Service</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="svError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="sv_name">Treatment Name</label><input type="text" id="sv_name" name="service_name" maxlength="150" required></div>
                <div class="field"><label for="sv_code">Room Code (compatible rooms)</label><input type="text" id="sv_code" name="room_code" maxlength="4" pattern="[A-Za-z]{2,4}" placeholder="SR" required></div>
                <div class="field"><label for="sv_price">Price (₱)</label><input type="number" id="sv_price" name="price" min="0" step="0.01" required></div>
                <div class="field"><label for="sv_duration">Duration (minutes)</label><input type="number" id="sv_duration" name="duration" min="15" max="480" step="5" required></div>
                <div class="field"><label for="sv_base">Base Guests</label><input type="number" id="sv_base" name="base_capacity" min="1" max="20" required></div>
                <div class="field"><label for="sv_max">Maximum Guests</label><input type="number" id="sv_max" name="max_capacity" min="1" max="20" required></div>
                <div class="field field-full"><label for="sv_desc">Description</label><textarea id="sv_desc" name="description" rows="3" maxlength="1000"></textarea></div>
            </div>
            <p class="cell-sub" id="svHint">The room code links this service to rooms named with the same code, for example SR-1 and SR-2 for code SR. Guests choose between Base Guests and Maximum Guests when booking, and only rooms with enough physical capacity are offered. Extra guests above Base Guests pay the extra guest fee from Settings.</p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="svSubmit"><span>Save Treatment</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="svDeleteModal" role="dialog" aria-modal="true" aria-labelledby="svDelTitle" hidden>
    <div class="modal-card modal-sm">
        <form id="svDeleteForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_service">
            <input type="hidden" name="id" id="svDelId" value="">
            <header class="modal-head">
                <div><p class="label-small">Delete Treatment</p><h2 class="card-title" id="svDelTitle">Delete this treatment?</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="svDelError" role="alert" hidden></p>
            <p class="confirm-text" id="svDelText"></p>
            <footer class="modal-foot">
                <button type="button" class="btn btn-outline" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="svDelSubmit"><span>Delete Treatment</span></button>
            </footer>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>