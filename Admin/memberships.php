<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/membership-data.php';
require_once __DIR__ . '/assets/icons/icons.php';

$pdo     = db();
$adminId = (int)$_SESSION['admin_id'];
$ready   = false;

try {
    $ready         = ml_ready($pdo);
    $notifications = get_notifications($pdo, $adminId);
} catch (Throwable $e) {
    error_log('[BVO memberships] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    exit('Unable to load membership module: ' . e(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
}

$pageTitle     = 'Membership & Loyalty';
$crumb         = 'Membership & Loyalty';
$footerScripts = $ready ? [url('/assets/js/membership-loyalty.js')] : [];
$cssFile       = __DIR__ . '/assets/css/membership-loyalty.css';
$cssVer        = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/membership-loyalty.css')) ?>?v=<?= e($cssVer) ?>">
<section class="page-head">
    <div>
        <p class="label-small">Membership Programs · Guest Rewards</p>
        <h1 class="page-title">Membership Tiers &amp; Loyalty Rewards</h1>
        <p class="page-sub">Manage membership programs, configure loyalty benefits, reward returning guests, and monitor points earned and redeemed across Bella Vista Oasis.</p>
    </div>
    <?php if ($ready): ?>
    <div class="page-actions">
        <button type="button" class="btn btn-outline" id="btnRules"><?= icon('sliders') ?><span>Manage Membership Rules</span></button>
        <button type="button" class="btn btn-outline" id="btnRewards"><?= icon('gift') ?><span>Manage Rewards</span></button>
        <button type="button" class="btn btn-primary" id="btnCreateReward"><?= icon('plus') ?><span>Create Reward</span></button>
        <button type="button" class="btn btn-primary" id="btnAdjust"><?= icon('plus') ?><span>Adjust Member Points</span></button>
    </div>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
<section class="card">
    <p class="label-small">Setup Required</p>
    <h2 class="card-title">Membership and loyalty tables are missing</h2>
    <p class="confirm-text">Import <strong>database/membership-loyalty-extensions.sql</strong> in phpMyAdmin (database bella_vista_oasis). It adds membership tiers, loyalty settings, the points ledger, rewards, redemptions and membership history, and links your existing memberships to the new tiers. Existing records are kept. Then refresh this page.</p>
</section>
<?php else: ?>

<section class="card ml-range">
    <div class="filters-row">
        <label class="ml-lbl" for="rgFrom">Metrics period</label>
        <input type="date" class="ctl" id="rgFrom" aria-label="From date">
        <input type="date" class="ctl" id="rgTo" aria-label="To date">
        <button type="button" class="btn btn-outline btn-sm" id="rgReset">All time</button>
    </div>
</section>

<section class="stats stats-ml" aria-label="Membership statistics" data-photo-base="<?= e(url('/assets/uploads/rewards/')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <article class="stat"><div class="stat-top"><span class="label-small">Total Members</span><span class="stat-ico"><?= icon('users') ?></span></div><p class="stat-value" id="sMembers">–</p><p class="stat-note" id="sMembersNote">Registered memberships</p></article>
    <article class="stat stat-hl"><div class="stat-top"><span class="label-small">Active Memberships</span><span class="stat-ico"><?= icon('check-ring') ?></span></div><p class="stat-value" id="sActive">–</p><p class="stat-note" id="sActiveNote">Currently valid</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Points Issued</span><span class="stat-ico"><?= icon('award') ?></span></div><p class="stat-value" id="sIssued">–</p><p class="stat-note">Awarded to members</p></article>
    <article class="stat"><div class="stat-top"><span class="label-small">Points Redeemed</span><span class="stat-ico"><?= icon('gift') ?></span></div><p class="stat-value" id="sRedeemed">–</p><p class="stat-note" id="sRedeemedNote">Used on rewards</p></article>
    <article class="stat stat-dark"><div class="stat-top"><span class="label-small">Reward Redemption Rate</span><span class="stat-ico"><?= icon('pulse') ?></span></div><p class="stat-value" id="sRate">–</p><p class="stat-note" id="sRateNote">Redeemed vs issued points</p></article>
</section>

<section class="card card-pad" id="tiersSection">
    <div class="card-head">
        <div><p class="label-small">Program Levels</p><h2 class="card-title">Membership Tiers &amp; Privileges</h2></div>
        <button type="button" class="btn btn-soft btn-sm" id="btnNewTier"><?= icon('plus') ?><span>New Tier</span></button>
    </div>
    <div class="tier-grid" id="tierGrid" aria-live="polite"></div>
</section>

<section class="card card-wide" id="membersSection">
    <div class="appt-toolbar">
        <div class="wk-top">
            <div><p class="label-small">Guest Memberships</p><h2 class="card-title">Members</h2></div>
            <div class="filters-row">
                <button type="button" class="btn btn-soft btn-sm" id="btnEnroll"><?= icon('plus') ?><span>Enroll Customer</span></button>
                <input type="search" class="ctl search-inline" id="mbSearch" placeholder="Search name, ID or email" aria-label="Search members">
                <select class="ctl" id="mbTier" aria-label="Filter by tier"><option value="">All tiers</option></select>
                <select class="ctl" id="mbStatus" aria-label="Filter by status"><option value="">All statuses</option><option value="active">Active</option><option value="expired">Expired</option><option value="inactive">Inactive</option></select>
            </div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="manifest ml-table">
            <thead><tr><th>Customer</th><th>Tier</th><th>Points</th><th>Status</th><th>Start</th><th>Expires</th><th>Qualifying Spend</th><th>Actions</th></tr></thead>
            <tbody id="mbBody"></tbody>
        </table>
    </div>
    <div class="table-foot"><span id="mbCount"></span></div>
</section>

<section class="card card-pad" id="rewardsSection">
    <div class="card-head">
        <div><p class="label-small">Reward Catalog</p><h2 class="card-title">Active Rewards &amp; Benefits</h2></div>
        <button type="button" class="btn btn-soft btn-sm" id="btnRedeem"><?= icon('gift') ?><span>Redeem for a Member</span></button>
    </div>
    <div class="reward-grid" id="rewardGrid" aria-live="polite"></div>
</section>

<section class="card card-wide" id="ledgerSection">
    <div class="appt-toolbar">
        <div class="wk-top">
            <div><p class="label-small">Loyalty Ledger</p><h2 class="card-title">Member Redemptions &amp; Loyalty Transactions</h2></div>
        </div>
        <div class="filters-row">
            <input type="search" class="ctl search-inline" id="lgSearch" placeholder="Search member, reference or description" aria-label="Search transactions">
            <select class="ctl" id="lgType" aria-label="Transaction type"><option value="">All Transactions</option><option value="earned">Points Earned</option><option value="redeemed">Points Redeemed</option><option value="bonus">Bonus Points</option><option value="adjustment">Manual Adjustments</option></select>
            <select class="ctl" id="lgTier" aria-label="Membership tier"><option value="">All tiers</option></select>
            <select class="ctl" id="lgStatus" aria-label="Status"><option value="">All statuses</option><option value="completed">Completed</option><option value="pending">Pending</option><option value="cancelled">Cancelled</option><option value="reversed">Reversed</option></select>
            <input type="date" class="ctl" id="lgFrom" aria-label="From date">
            <input type="date" class="ctl" id="lgTo" aria-label="To date">
            <button type="button" class="btn btn-outline btn-sm" id="lgReset">Reset</button>
        </div>
    </div>
    <div class="table-wrap">
        <table class="manifest ml-table">
            <thead><tr><th>Reference</th><th>Member</th><th>Tier</th><th>Type</th><th class="num">Earned</th><th class="num">Redeemed</th><th>Description</th><th><button type="button" class="th-sort" id="lgSort">Date <span id="lgSortArrow">↓</span></button></th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="lgBody"></tbody>
        </table>
    </div>
    <div class="table-foot"><span id="lgCount"></span><div class="pager" id="lgPager"></div></div>
</section>

<div class="modal" id="tierModal" role="dialog" aria-modal="true" aria-labelledby="tierTitle" hidden>
    <div class="modal-card modal-lg">
        <form id="tierForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="tr_action" value="create_tier">
            <input type="hidden" name="id" id="tr_id" value="">
            <header class="modal-head">
                <div><p class="label-small" id="tierKicker">Membership Tier</p><h2 class="card-title" id="tierTitle">New Tier</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="tierError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field"><label for="tr_name">Tier Name</label><input type="text" id="tr_name" name="tier_name" maxlength="60" required></div>
                <div class="field"><label for="tr_color">Tier Color</label><input type="color" id="tr_color" name="tier_color" value="#C4B58D"></div>
                <div class="field field-full"><label for="tr_desc">Tier Description</label><input type="text" id="tr_desc" name="description" maxlength="255"></div>
                <div class="field"><label for="tr_spend">Minimum Spending (₱)</label><input type="number" id="tr_spend" name="minimum_spending" min="0" step="0.01" value="0"></div>
                <div class="field"><label for="tr_points">Minimum Points Earned</label><input type="number" id="tr_points" name="minimum_points" min="0" step="1" value="0"></div>
                <div class="field"><label for="tr_disc">Discount Percentage</label><input type="number" id="tr_disc" name="discount_percentage" min="0" max="100" step="0.01" value="0"></div>
                <div class="field"><label for="tr_mult">Points Multiplier</label><input type="number" id="tr_mult" name="points_multiplier" min="0.1" max="10" step="0.01" value="1"></div>
                <div class="field field-full"><label for="tr_ben">Membership Benefits (one per line)</label><textarea id="tr_ben" name="benefits" rows="4"></textarea></div>
                <div class="field field-full"><label for="tr_prv">Special Privileges (one per line)</label><textarea id="tr_prv" name="privileges" rows="3"></textarea></div>
                <div class="field"><label for="tr_status">Membership Status</label><select id="tr_status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div>
            <p class="cell-sub">Tiers are never deleted. Deactivating a tier keeps every existing member and transaction.</p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="tierSave"><span>Save Tier</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="rewardModal" role="dialog" aria-modal="true" aria-labelledby="rewardTitle" hidden>
    <div class="modal-card modal-xl">
        <form id="rewardForm" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="rw_action" value="create_reward">
            <input type="hidden" name="id" id="rw_id" value="">
            <header class="modal-head">
                <div><p class="label-small" id="rewardKicker">Reward Catalog</p><h2 class="card-title" id="rewardTitle">Create Reward</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="rewardError" role="alert" hidden></p>
            <div class="tf-body">
                <p class="detail-h">Reward Information</p>
                <div class="form-grid">
                    <div class="field"><label for="rw_name">Reward Name</label><input type="text" id="rw_name" name="reward_name" maxlength="120" required></div>
                    <div class="field"><label for="rw_cat">Reward Category</label>
                        <select id="rw_cat" name="reward_category"><option>Add-on</option><option>Discount</option><option>Upgrade</option><option>Experience</option><option>Product</option></select></div>
                    <div class="field field-full"><label for="rw_desc">Reward Description</label><input type="text" id="rw_desc" name="description" maxlength="255"></div>
                    <div class="field field-full">
                        <label for="rw_image">Reward Image</label>
                        <div class="tf-photo">
                            <span class="rw-preview" id="rwPreview"></span>
                            <div class="tf-photo-ctl">
                                <input type="file" id="rw_image" name="reward_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                <span class="cell-sub">JPG, JPEG, PNG or WEBP · max 3 MB</span>
                                <label class="tf-remove" id="rwRemoveRow" hidden><input type="checkbox" name="remove_image" value="1" id="rw_remove"> Remove current image</label>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="detail-h">Redemption Settings</p>
                <div class="form-grid">
                    <div class="field"><label for="rw_points">Required Points</label><input type="number" id="rw_points" name="required_points" min="1" step="1" required></div>
                    <div class="field"><label for="rw_qty">Available Quantity</label><input type="number" id="rw_qty" name="available_quantity" min="0" step="1" placeholder="Blank = unlimited"></div>
                    <div class="field"><label for="rw_max">Maximum Redemptions per Member</label><input type="number" id="rw_max" name="maximum_per_member" min="1" step="1" placeholder="Blank = no limit"></div>
                    <div class="field"><label for="rw_status">Status</label><select id="rw_status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    <div class="field"><label for="rw_from">Valid From</label><input type="date" id="rw_from" name="valid_from"></div>
                    <div class="field"><label for="rw_until">Valid Until</label><input type="date" id="rw_until" name="valid_until"></div>
                </div>
                <p class="detail-h">Eligibility</p>
                <div class="form-grid">
                    <div class="field"><label for="rw_min">Minimum Membership Level</label><select id="rw_min" name="minimum_tier_id"><option value="">No minimum</option></select></div>
                    <div class="field"><label>Eligible Membership Tiers</label><div class="chk-row" id="rwTiers"></div><span class="cell-sub">Leave all unchecked to allow every tier.</span></div>
                </div>
            </div>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-outline" id="rwAnother" value="another"><span>Save &amp; Add Another</span></button>
                <button type="submit" class="btn btn-primary" id="rwSave" value="save"><span>Save Reward</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="rewardsModal" role="dialog" aria-modal="true" aria-labelledby="rewardsTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small">Reward Catalog</p><h2 class="card-title" id="rewardsTitle">Manage Rewards</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="table-wrap"><table class="manifest ml-table"><thead><tr><th>Reward</th><th>Category</th><th class="num">Points</th><th>Stock</th><th>Limit</th><th>Status</th><th>Actions</th></tr></thead><tbody id="rwList"></tbody></table></div>
        <footer class="modal-foot"><button type="button" class="btn btn-primary" id="rwListNew"><span>Create Reward</span></button></footer>
    </div>
</div>

<div class="modal" id="rulesModal" role="dialog" aria-modal="true" aria-labelledby="rulesTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small">Program Configuration</p><h2 class="card-title" id="rulesTitle">Membership Rules</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form id="rulesForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <p class="form-error" id="rulesError" role="alert" hidden></p>
            <p class="detail-h">Earning Rules</p>
            <div class="form-grid">
                <div class="field"><label for="ru_peso">Spend per 1 base point (₱)</label><input type="number" id="ru_peso" name="peso_per_point" min="1" step="1" required></div>
                <div class="field"><label for="ru_term">Membership term (months)</label><input type="number" id="ru_term" name="membership_term_months" min="1" max="120" step="1" required></div>
                <div class="field field-full"><label class="tf-remove"><input type="checkbox" id="ru_auto" name="auto_award" value="1"> Automatically award points for paid, completed appointments</label></div>
            </div>
            <p class="cell-sub" id="ruleExplain"></p>
            <div class="rule-actions">
                <button type="submit" class="btn btn-primary" id="rulesSave"><span>Save Rules</span></button>
                <button type="button" class="btn btn-soft" id="rulesSync"><span>Award Eligible Points Now</span></button>
            </div>
        </form>
        <p class="detail-h">Membership Tiers</p>
        <div class="table-wrap"><table class="manifest ml-table"><thead><tr><th>Tier</th><th class="num">Min. Spend</th><th class="num">Min. Points</th><th class="num">Discount</th><th class="num">Multiplier</th><th>Status</th><th>Actions</th></tr></thead><tbody id="ruTiers"></tbody></table></div>
        <footer class="modal-foot"><button type="button" class="btn btn-primary" id="ruNewTier"><span>New Tier</span></button></footer>
    </div>
</div>

<div class="modal" id="adjustModal" role="dialog" aria-modal="true" aria-labelledby="adjustTitle" hidden>
    <div class="modal-card modal-lg">
        <form id="adjustForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_points">
            <input type="hidden" name="customer_id" id="aj_customer" value="">
            <header class="modal-head">
                <div><p class="label-small">Manual Adjustment</p><h2 class="card-title" id="adjustTitle">Adjust Member Points</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="adjustError" role="alert" hidden></p>
            <div class="form-grid">
                <div class="field field-full"><label for="aj_search">Customer</label>
                    <input type="search" id="aj_search" placeholder="Search customer name or email" autocomplete="off">
                    <div class="pick-list" id="aj_results" hidden></div>
                    <p class="pick-chosen" id="aj_chosen" hidden></p></div>
                <div class="field"><label for="aj_type">Adjustment Type</label><select id="aj_type" name="adjust_type"><option value="add">Add Points</option><option value="deduct">Deduct Points</option></select></div>
                <div class="field"><label for="aj_points">Points Amount</label><input type="number" id="aj_points" name="points" min="1" step="1" required></div>
                <div class="field field-full"><label for="aj_reason">Reason</label><select id="aj_reason" name="reason"><option value="">Select reason</option><option>Bonus points</option><option>Promotion</option><option>Service recovery</option><option>Correction</option><option>Other</option></select></div>
                <div class="field field-full"><label for="aj_notes">Notes</label><textarea id="aj_notes" name="notes" rows="3" maxlength="500"></textarea></div>
            </div>
            <p class="cell-sub">Balances cannot go below zero. Every adjustment is recorded with your administrator account.</p>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="adjustSave"><span>Save Adjustment</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="tierChangeModal" role="dialog" aria-modal="true" aria-labelledby="tcTitle" hidden>
    <div class="modal-card modal-lg">
        <form id="tierChangeForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_tier">
            <input type="hidden" name="customer_id" id="tc_customer" value="">
            <input type="hidden" name="override" id="tc_override" value="">
            <header class="modal-head">
                <div><p class="label-small" id="tcKicker">Membership Change</p><h2 class="card-title" id="tcTitle">Change Membership</h2></div>
                <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
            </header>
            <p class="form-error" id="tcError" role="alert" hidden></p>
            <dl class="ins-grid" id="tcInfo"></dl>
            <div class="form-grid">
                <div class="field field-full"><label for="tc_tier">New Tier</label><select id="tc_tier" name="tier_id"></select></div>
                <div class="field field-full"><div class="tc-req" id="tcReq"></div></div>
                <div class="field field-full"><label for="tc_reason">Reason (Optional)</label><input type="text" id="tc_reason" name="reason" maxlength="200"></div>
            </div>
            <footer class="modal-foot">
                <button type="submit" class="btn btn-primary" id="tcSave"><span>Update Membership</span></button>
            </footer>
        </form>
    </div>
</div>

<div class="modal" id="redeemModal" role="dialog" aria-modal="true" aria-labelledby="redeemTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small">Loyalty Redemption</p><h2 class="card-title" id="redeemTitle">Redeem a Reward</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <p class="form-error" id="redeemError" role="alert" hidden></p>
        <div class="field"><label for="rd_search">Customer</label>
            <input type="search" id="rd_search" placeholder="Search customer name or email" autocomplete="off">
            <div class="pick-list" id="rd_results" hidden></div></div>
        <div id="rdBody" hidden>
            <div class="rd-summary" id="rdSummary"></div>
            <div class="rd-list" id="rdList"></div>
        </div>
    </div>
</div>

<div class="modal" id="memberModal" role="dialog" aria-modal="true" aria-labelledby="memberTitle" hidden>
    <div class="modal-card modal-xl">
        <header class="modal-head">
            <div><p class="label-small" id="memberKicker">Member Loyalty History</p><h2 class="card-title" id="memberTitle">Member</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="folio" id="memberBody"></div>
        <footer class="modal-foot" id="memberFoot"></footer>
    </div>
</div>

<div class="modal" id="txModal" role="dialog" aria-modal="true" aria-labelledby="txTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div><p class="label-small">Loyalty Transaction</p><h2 class="card-title" id="txTitle">Transaction</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <p class="form-error" id="txError" role="alert" hidden></p>
        <div id="txBody"></div>
        <div class="field" id="txReverseRow" hidden><label for="tx_reason">Reason for reversal</label><input type="text" id="tx_reason" maxlength="200"></div>
        <footer class="modal-foot" id="txFoot"></footer>
    </div>
</div>

<div class="modal" id="enrollModal" role="dialog" aria-modal="true" aria-labelledby="enrollTitle" hidden>
    <div class="modal-card modal-lg">
        <header class="modal-head">
            <div><p class="label-small">New Membership</p><h2 class="card-title" id="enrollTitle">Enroll Customer</h2></div>
            <button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <div class="field"><label for="en_search">Customer</label>
            <input type="search" id="en_search" placeholder="Search customer name or email" autocomplete="off">
            <div class="pick-list" id="en_results" hidden></div></div>
        <p class="cell-sub">Pick an existing customer. No duplicate customer profile is created.</p>
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