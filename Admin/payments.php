<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/billing-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = bill_ready($pdo);
$services = [];
$catalog  = [];
$commission = null;

try {
    $notifications = get_notifications($pdo, $adminId);
    $services      = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name ASC')->fetchAll();
    if ($ready) {
        $catalog    = $pdo->query('SELECT id, addon_name, price, commissionable FROM service_addons WHERE is_active = 1 ORDER BY addon_name ASC')->fetchAll();
        $commission = bill_config($pdo)['commission'];
    }
} catch (Throwable $e) {
    error_log('[BVO payments] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load Payment Management right now.');
}

$adminName = (string)($_SESSION['admin_name'] ?? 'Administrator');
$canAuth   = bill_can_authorize();

$pageTitle     = 'Payment Management';
$crumb         = 'Payment Management';
$extraStyles   = [
    url('/assets/css/payments.css') . '?v=' . (string)@filemtime(__DIR__ . '/assets/css/payments.css'),
    url('/assets/css/payments-responsive.css') . '?v=' . (string)@filemtime(__DIR__ . '/assets/css/payments-responsive.css'),
];
$footerScripts = [url('/assets/js/payments.js') . '?v=' . (string)@filemtime(__DIR__ . '/assets/js/payments.js')];
require __DIR__ . '/includes/header.php';
?>
<div id="payApp" class="pay-app"
     data-ready="<?= $ready ? '1' : '0' ?>"
     data-can-auth="<?= $canAuth ? '1' : '0' ?>"
     data-admin="<?= e($adminName) ?>"
     data-receipt="<?= e(url('/payment-receipt.php')) ?>"
     data-calendar="<?= e(url('/appointment-calendar.php')) ?>"
     data-settings="<?= e(url('/settings.php')) ?>">

<?php if (!$ready): ?>
    <div class="pay-setup" role="alert">
        <strong>Payment Management needs a one-time database update.</strong>
        <span>Open phpMyAdmin, select <code>bella_vista_oasis</code>, go to Import, and run <code>database/payment_management_migration.sql</code>. Then refresh this page.</span>
    </div>
<?php endif; ?>

<section class="pay-hero">
    <div class="pay-hero-text">
        <p class="label-small">Billing &amp; Transactions</p>
        <div class="pay-title-row">
            <h1 class="page-title">Billing &amp; Transactions</h1>
            <span class="pay-terminal"><span class="pay-dot"></span>Terminal 01 · <?= e($adminName) ?> (Active Shift)</span>
        </div>
        <p class="page-sub">Audit sanctuary guest folios, settle thermal suite tariffs, balance advance booking deposits, and export fiscal reconciliation manifests.</p>
    </div>
    <div class="pay-hero-actions">
        <div class="pay-hero-top">
            <span class="pay-protocol"><?= icon('shield') ?>Sanctuary Protocol · Calm Flow</span>
            <a class="btn btn-grad btn-sm" href="<?= e(url('/appointment-calendar.php')) ?>"><?= icon('plus') ?>New Walk-in / Booking</a>
        </div>
        <div class="pay-hero-btns">
            <button type="button" class="btn btn-outline btn-sm" id="btnDrawer"><?= icon('wallet') ?>Reconcile Drawer</button>
            <a class="btn btn-outline btn-sm" id="btnExport" href="#"><?= icon('download') ?>Export Manifest</a>
            <button type="button" class="btn btn-grad btn-sm" id="btnNewCharge"><?= icon('plus') ?>New Charge / Invoice</button>
        </div>
    </div>
</section>

<section class="pay-stats" aria-label="Financial summary">
    <article class="pay-stat">
        <div class="pay-stat-top"><p class="label-small">Today's Gross Revenue</p><span class="pay-stat-ico"><?= icon('wallet') ?></span></div>
        <p class="pay-stat-val" id="stGross">₱0.00</p>
        <div class="pay-bar" id="stBarWrap"><span id="stBar"></span></div>
        <p class="pay-stat-foot"><span id="stPace">Compared with yesterday</span><span id="stTarget"></span></p>
    </article>
    <article class="pay-stat">
        <div class="pay-stat-top"><p class="label-small">Settled Receipts</p><span class="pay-stat-ico"><?= icon('check-ring') ?></span></div>
        <p class="pay-stat-val"><span id="stSettled">0</span><small id="stTotal">/ 0</small></p>
        <p class="pay-stat-foot"><span id="stSplit">0 Digital · 0 Cash</span><span class="pay-chip" id="stSettlePct">0% Balanced</span></p>
    </article>
    <article class="pay-stat pay-stat-gold">
        <div class="pay-stat-top"><p class="label-small">Advance Escrow Deposits</p><span class="pay-stat-ico"><?= icon('shield') ?></span></div>
        <p class="pay-stat-val" id="stEscrow">₱0.00</p>
        <p class="pay-stat-foot"><span id="stEscrowPct">0% of upcoming bookings</span><span id="stEscrowCount">0 active deposits</span></p>
    </article>
    <article class="pay-stat">
        <div class="pay-stat-top"><p class="label-small">In-Session / Open Tabs</p><span class="pay-stat-ico"><?= icon('clock') ?></span></div>
        <p class="pay-stat-val" id="stOpen">₱0.00</p>
        <p class="pay-stat-foot"><span id="stActive">0 active guest folios</span><span class="pay-chip pay-chip-gold" id="stPending">0 Pending Checkout</span></p>
    </article>
</section>

<div class="pay-grid">
    <div class="pay-main">
        <section class="card pay-table-card" aria-label="Payment transactions">
            <div class="pay-card-head">
                <div>
                    <p class="label-small">Ledger</p>
                    <h2 class="card-title">Payment Transactions</h2>
                </div>
            </div>

            <div class="pay-tabs" id="payTabs" role="tablist">
                <button type="button" role="tab" class="pay-tab is-active" data-tab="all">All Transactions <b id="cAll">0</b></button>
                <button type="button" role="tab" class="pay-tab" data-tab="completed">Completed <b id="cCompleted">0</b></button>
                <button type="button" role="tab" class="pay-tab" data-tab="pending">Pending Settlement <b id="cPending">0</b></button>
                <button type="button" role="tab" class="pay-tab" data-tab="refunds">Refunds / Void <b id="cRefunds">0</b></button>
            </div>

            <div class="pay-filters">
                <select class="ctl" id="fRange" aria-label="Date range">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="custom">Custom Date Range</option>
                    <option value="all">All Time</option>
                </select>
                <span class="pay-custom" id="customRange" hidden>
                    <input type="date" class="ctl" id="fFrom" aria-label="From date">
                    <input type="date" class="ctl" id="fTo" aria-label="To date">
                </span>
                <label class="pay-search">
                    <?= icon('search') ?>
                    <input type="search" id="fSearch" placeholder="Search guest, invoice, reservation" maxlength="80" aria-label="Search transactions">
                </label>
                <select class="ctl" id="fChannel" aria-label="Payment channel">
                    <option value="">All Payment Channels</option>
                    <option value="GCash">GCash</option>
                    <option value="Maya">Maya</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Card">Credit / Debit Card</option>
                    <option value="Cash">Cash</option>
                    <option value="Post to Suite">Post to Suite</option>
                </select>
                <select class="ctl" id="fService" aria-label="Treatment">
                    <option value="0">All Treatments</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e((string)$s['service_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-wrap pay-table-wrap" id="tableWrap">
                <table class="pay-table">
                    <thead>
                        <tr>
                            <th><button type="button" class="pay-sort" data-sort="date">Invoice &amp; Time</button></th>
                            <th><button type="button" class="pay-sort" data-sort="guest">Guest &amp; Treatment</button></th>
                            <th>Channel</th>
                            <th class="num"><button type="button" class="pay-sort" data-sort="gross">Gross</button></th>
                            <th class="num">Deposit</th>
                            <th class="num"><button type="button" class="pay-sort" data-sort="settled">Settled</button></th>
                            <th>Status</th>
                            <th class="num">Action</th>
                        </tr>
                    </thead>
                    <tbody id="payRows"></tbody>
                </table>
                <p class="pay-empty" id="payEmpty" hidden>No transactions match these filters.</p>
            </div>

            <div class="table-foot">
                <span id="payShowing">Showing 0 of 0 transactions</span>
                <div class="pager" id="payPager"></div>
            </div>
        </section>

        <section class="card pay-promo" aria-label="Aromatherapy add-on promotion">
            <span class="pay-promo-ico"><?= icon('leaf') ?></span>
            <div class="pay-promo-text">
                <h2 class="pay-promo-title">Aromatherapy In-Session Add-on Promotion</h2>
                <p id="promoText">Add-on runs via POS during treatment.<?= $commission !== null ? ' Therapist commissions are credited automatically at the standard ' . e(rtrim(rtrim(number_format($commission, 2), '0'), '.')) . '% sanctuary wellness incentive.' : ' No commission rate is configured yet, so no commission is credited.' ?></p>
            </div>
            <button type="button" class="btn btn-soft btn-sm" id="btnTariffs">Review Therapist Tariffs</button>
        </section>
    </div>

    <aside class="pay-side">
        <section class="card pay-folio" id="folioCard" aria-label="Quick settle folio">
            <div class="pay-folio-head">
                <h2 class="card-title">Quick Settle Folio</h2>
                <span class="pay-pos">POS Terminal</span>
            </div>

            <div class="field">
                <label for="folioSelect">Select Active Folio</label>
                <select id="folioSelect"><option value="">Select a guest and appointment</option></select>
            </div>
            <p class="pay-hint" id="folioEmpty">Confirmed or completed appointments with an unpaid balance appear here.</p>

            <form id="settleForm" novalidate hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="settle">
                <input type="hidden" name="appointment_id" id="sfAppt" value="">
                <input type="hidden" name="payment_method" id="sfMethod" value="">
                <input type="hidden" name="idempotency_key" id="sfKey" value="">

                <div class="pay-guest">
                    <strong id="fgGuest"></strong>
                    <span id="fgService"></span>
                    <span class="pay-muted" id="fgMeta"></span>
                </div>

                <div class="pay-lines" id="fgLines"></div>

                <div class="pay-addons" id="addonBox" hidden>
                    <p class="label-small">Add In-Session Add-ons</p>
                    <div id="addonList"></div>
                </div>

                <div class="pay-disc">
                    <div class="field">
                        <label for="sfDisc">Discount (₱)</label>
                        <input type="number" id="sfDisc" name="discount_amount" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                    </div>
                    <div class="field">
                        <label for="sfDiscWhy">Discount Reason</label>
                        <input type="text" id="sfDiscWhy" name="discount_reason" maxlength="100" placeholder="Required if discounted">
                    </div>
                </div>

                <div class="pay-due">
                    <span>Balance Due</span>
                    <strong id="fgDue">₱0.00</strong>
                </div>

                <p class="label-small pay-sub">Select Settlement Method</p>
                <div class="pay-methods" id="methodGrid" role="radiogroup" aria-label="Settlement method">
                    <button type="button" class="pay-method" data-method="digital" role="radio" aria-checked="false"><?= icon('qr') ?><span>GCash / Maya QR</span></button>
                    <button type="button" class="pay-method" data-method="Card" role="radio" aria-checked="false"><?= icon('wallet') ?><span>Terminal Card</span></button>
                    <button type="button" class="pay-method" data-method="Post to Suite" role="radio" aria-checked="false"><?= icon('bed') ?><span>Post to Suite</span></button>
                    <button type="button" class="pay-method" data-method="Cash" role="radio" aria-checked="false"><?= icon('gift') ?><span>Cash Drawer</span></button>
                </div>

                <div class="pay-method-box" id="methodBox" hidden>
                    <div class="field" id="digitalRow" hidden>
                        <label for="sfDigital">Digital Channel</label>
                        <select id="sfDigital">
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="pay-qr" id="qrPanel" hidden>
                        <p class="label-small" id="qrTitle">Scan to pay</p>
                        <div class="pay-qr-frame" id="qrFrame">
                            <img id="qrImg" alt="" hidden>
                            <p class="pay-qr-empty" id="qrEmpty">No QR uploaded for this channel yet.</p>
                        </div>
                        <p class="pay-qr-amount">Amount to pay <strong id="qrAmount">₱0.00</strong></p>
                        <p class="pay-hint">Ask the guest to scan this QR and enter the exact amount. After they pay, enter the reference number from their receipt below.</p>
                        <div class="pay-qr-actions">
                            <label class="btn btn-outline btn-sm" for="qrFile"><span id="qrUploadLabel">Upload QR</span></label>
                            <input type="file" id="qrFile" accept="image/png,image/jpeg,image/webp" hidden>
                            <button type="button" class="btn btn-outline btn-sm btn-danger-soft" id="qrRemove" hidden>Remove</button>
                        </div>
                    </div>
                    <div class="field" id="amountRow">
                        <label for="sfAmount">Amount Received (₱)</label>
                        <input type="number" id="sfAmount" name="amount" min="0.01" step="0.01" inputmode="decimal">
                    </div>
                    <div class="field" id="refRow" hidden>
                        <label for="sfRef" id="sfRefLabel">Reference Number</label>
                        <input type="text" id="sfRef" name="reference" maxlength="60" autocomplete="off">
                    </div>
                    <label class="pay-check" id="confirmRow" hidden>
                        <input type="checkbox" id="sfConfirm" name="confirmed" value="1">
                        <span id="confirmText">I confirm this payment was actually received. Bella Vista does not verify GCash, Maya, or card payments automatically.</span>
                    </label>
                    <p class="pay-hint" id="suiteNote" hidden>The full balance is posted as an authorized suite charge. It stays open until it is settled by another method.</p>
                    <div class="field">
                        <label for="sfNotes">Notes (optional)</label>
                        <input type="text" id="sfNotes" name="notes" maxlength="255">
                    </div>
                </div>

                <div class="form-error" id="sfError" role="alert" hidden></div>
                <button type="submit" class="btn btn-grad pay-submit" id="sfSubmit"><?= icon('check') ?><span>Complete &amp; Print Thermal Slip</span></button>
            </form>
        </section>

        <section class="card pay-escrow" aria-label="Escrow and cancellation policy">
            <div class="pay-escrow-head">
                <span class="pay-escrow-ico"><?= icon('shield') ?></span>
                <h2 class="card-title">Escrow &amp; Cancellation Policy</h2>
            </div>
            <p class="pay-escrow-text" id="escPolicy">Loading policy…</p>
            <div class="pay-grace">
                <p class="pay-grace-title" id="escGraceTitle">Grace Window</p>
                <p id="escGraceText"></p>
            </div>
            <dl class="pay-esc-list">
                <div><dt>Escrow Held</dt><dd id="escHeld">₱0.00</dd></div>
                <div><dt>Today's Forfeitures</dt><dd id="escForfeit">0 cases (₱0)</dd></div>
                <div><dt>Authorized Refunds</dt><dd id="escRefund">₱0 processed</dd></div>
            </dl>
            <button type="button" class="btn btn-outline pay-wide" id="btnLedger">View Escrow Ledger Audit</button>
        </section>
    </aside>
</div>

<div class="modal" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="dmTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div>
                <p class="label-small" id="dmKicker">Transaction</p>
                <h2 class="card-title" id="dmTitle">Transaction Details</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="dmBody"></div>
        <div class="form-error" id="dmError" role="alert" hidden></div>
        <form id="reverseForm" class="pay-reverse" hidden novalidate>
            <div class="field field-full">
                <label for="rvReason" id="rvLabel">Reason</label>
                <textarea id="rvReason" rows="2" maxlength="255" placeholder="Explain why (recorded in the audit trail)"></textarea>
            </div>
            <div class="modal-foot modal-foot-end">
                <button type="button" class="btn btn-outline btn-sm" id="rvCancel">Cancel</button>
                <button type="submit" class="btn btn-danger-soft btn-sm" id="rvSubmit">Confirm</button>
            </div>
        </form>
        <footer class="modal-foot" id="dmFoot"></footer>
    </div>
</div>

<div class="modal" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="cfTitle" hidden>
    <div class="modal-card modal-sm">
        <header class="modal-head">
            <div>
                <p class="label-small">Confirm Settlement</p>
                <h2 class="card-title" id="cfTitle">Record this payment?</h2>
            </div>
        </header>
        <p class="confirm-text" id="cfText"></p>
        <footer class="modal-foot modal-foot-end">
            <button type="button" class="btn btn-outline btn-sm" id="cfNo">Review</button>
            <button type="button" class="btn btn-grad btn-sm" id="cfYes">Confirm &amp; Print</button>
        </footer>
    </div>
</div>

<div class="modal" id="doneModal" role="dialog" aria-modal="true" aria-labelledby="dnTitle" hidden>
    <div class="modal-card modal-sm">
        <header class="modal-head">
            <div>
                <p class="label-small">Saved</p>
                <h2 class="card-title" id="dnTitle">Payment Recorded</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <p class="confirm-text" id="dnText"></p>
        <footer class="modal-foot modal-foot-end">
            <button type="button" class="btn btn-outline btn-sm" data-close>Close</button>
            <a class="btn btn-grad btn-sm" id="dnPrint" href="#" target="_blank" rel="noopener">Print Thermal Slip</a>
        </footer>
    </div>
</div>

<div class="modal" id="drawerModal" role="dialog" aria-modal="true" aria-labelledby="drTitle" hidden>
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <p class="label-small" id="drDate">Today</p>
                <h2 class="card-title" id="drTitle">Reconcile Drawer</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div id="drBody"></div>
    </div>
</div>

<div class="modal" id="ledgerModal" role="dialog" aria-modal="true" aria-labelledby="ldTitle" hidden>
    <div class="modal-card modal-xl pay-ledger-card">
        <header class="modal-head">
            <div>
                <p class="label-small">Escrow &amp; Cancellation Policy</p>
                <h2 class="card-title" id="ldTitle">Escrow Ledger Audit</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="pay-ledger-wrap">
            <table class="pay-table pay-ledger">
                <thead>
                    <tr><th>Transaction</th><th>Appointment</th><th>Customer</th><th class="num">Deposit</th><th class="num">Refund</th><th class="num">Forfeited</th><th>Date</th><th>Status</th></tr>
                </thead>
                <tbody id="ldRows"></tbody>
            </table>
            <p class="pay-empty" id="ldEmpty" hidden>No deposits are on record yet.</p>
        </div>
    </div>
</div>

<div class="modal" id="tariffModal" role="dialog" aria-modal="true" aria-labelledby="tfTitle" hidden>
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <p class="label-small">Therapist Incentive</p>
                <h2 class="card-title" id="tfTitle">Therapist Tariffs</h2>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <p class="confirm-text" id="tfRate"></p>
        <div class="pay-ledger-wrap">
            <table class="pay-table">
                <thead><tr><th>Add-on</th><th class="num">Price</th><th class="num">Therapist Commission</th></tr></thead>
                <tbody id="tfRows">
                    <?php foreach ($catalog as $c): ?>
                        <tr data-price="<?= e((string)$c['price']) ?>" data-comm="<?= (int)$c['commissionable'] ?>">
                            <td><?= e((string)$c['addon_name']) ?></td>
                            <td class="num">₱<?= e(number_format((float)$c['price'], 2)) ?></td>
                            <td class="num tf-comm"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$catalog): ?><p class="pay-empty">No active add-ons are configured.</p><?php endif; ?>
        </div>
        <p class="pay-hint" id="tfMonth"></p>
        <footer class="modal-foot modal-foot-end">
            <a class="btn btn-outline btn-sm" href="<?= e(url('/settings.php')) ?>">Open Settings</a>
            <button type="button" class="btn btn-grad btn-sm" data-close>Done</button>
        </footer>
    </div>
</div>

</div>
<?php require __DIR__ . '/includes/footer.php'; ?>