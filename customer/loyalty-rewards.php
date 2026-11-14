<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';
require_once __DIR__ . '/includes/loyalty-data.php';

if (!cu_logged_in()) {
    $_SESSION['cu_return'] = '/loyalty-rewards.php';
    cu_flash('success', 'Please sign in to view your membership and rewards.');
    cu_redirect('/login.php');
}

$pdo = db();
$cid = (int)$_SESSION['customer_id'];
$st = $pdo->prepare("SELECT full_name, membership_code FROM customers WHERE id = ? AND customer_status = 'active'");
$st->execute([$cid]);
$customer = $st->fetch();
if (!$customer) cu_redirect('/auth/logout.php');

$member = cl_member($pdo, $cid);
$tiers = cl_tiers($pdo);
$tier = ($member && $member['tier_id'] && isset($tiers[$member['tier_id']])) ? $tiers[$member['tier_id']] : null;
$totals = cl_totals($pdo, $cid);
$balance = $totals['balance'];
$rewards = cl_rewards($pdo);
$used = cl_used_counts($pdo, $cid);
$phone = cu_setting('contact_phone');

$available = 0;
$next = null;
foreach ($rewards as $i => $rw) {
    $rewards[$i]['block'] = cl_block($rw, $member, $balance, $tiers, $used[$rw['id']] ?? 0);
    if ($rewards[$i]['block'] === null) $available++;
    elseif ($next === null && $rw['points'] > $balance && $member && $member['status'] === 'active') $next = $rw;
}
$progress = 100;
if ($next) $progress = max(4, min(100, (int)floor($balance / $next['points'] * 100)));

if ($tier) {
    $benefitLabel = $tier['tier_name'];
    $src = $tier;
    $proposed = false;
} else {
    $src = $tiers ? reset($tiers) : null;
    $benefitLabel = $src ? $src['tier_name'] : '';
    $proposed = true;
}
$benefits = [];
if ($src) {
    foreach (cl_lines($src['benefits']) as $b) $benefits[] = ['Benefit', $b];
    foreach (cl_lines($src['privileges']) as $b) $benefits[] = ['Privilege', $b];
}

$showAll = isset($_GET['history']) && $_GET['history'] === 'all';
$history = cl_history($pdo, $cid);
$perPage = 10;
$totalRows = count($history);
$pages = max(1, (int)ceil($totalRows / $perPage));
$pg = $showAll ? max(1, min($pages, (int)($_GET['page'] ?? 1))) : 1;
$rows = $showAll ? array_slice($history, ($pg - 1) * $perPage, $perPage) : array_slice($history, 0, 5);

$images = ['Discount' => 'treatment-single-room.jpg', 'Upgrade' => 'wellness-collage-1.jpg', 'Experience' => 'hero-spa.jpg', 'Add-on' => 'treatment-couples-suite.jpg', 'Product' => 'wellness-collage-2.jpg'];
function lr_icon(string $n): string
{
    $p = [
        'star' => '<path d="m12 3 2.700 5.500 6 .9-4.400 4.200 1 6-5.300-2.800L6.700 19.600l1-6L3.300 9.400l6-.9L12 3Z"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8c-2 0-4-1-4-3s3-2 4 1c1-3 4-3 4-1s-2 3-4 3Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'lotus' => '<path d="M12 20c-4 0-7-3-7-7 3 0 5.500 1 7 3 1.500-2 4-3 7-3 0 4-3 7-7 7Z"/><path d="M12 16c-2-2-2.500-6 0-10 2.500 4 2 8 0 10Z"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8L3 12Z"/><circle cx="7.500" cy="8.500" r="1.200"/>',
        'cake' => '<path d="M4 20h16v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6ZM12 12V8m0-4v1"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.700L3 8m0-4v4h4M12 7v5l3 2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}
$benefitIcons = ['clock', 'tag', 'gift', 'cake', 'mail', 'star', 'lotus', 'tag'];

$pageTitle = 'Membership & Loyalty Rewards';
$activeNav = 'loyalty';
$pageStyles = ['landing-page.css', 'loyalty-rewards.css', 'loyalty-responsive.css'];
$pageScripts = ['loyalty-rewards.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="lp lr">
    <section class="lr-intro">
        <div class="lp-wrap lr-intro-grid">
            <div>
                <p class="lp-eyebrow">Bella Vista Oasis Spa &amp; Sanctuary</p>
                <h1 class="serif">Emerald Circle Membership &amp; <span class="grad-text">Sanctuary Rewards</span></h1>
                <p class="lp-lead">Discover exclusive privileges, earn tranquility points, and enjoy thoughtfully curated rewards with every sanctuary experience.</p>
            </div>
            <div class="lr-indicator">
                <?php if ($member): ?>
                    <span class="lr-dot <?= e($member['status']) ?>"></span>
                    <div><strong><?= e($tier['tier_name'] ?? $member['membership_type'] ?? 'Member') ?> Member</strong><small>Membership <?= e($member['status']) ?><?= $member['expiry_date'] ? ' · until ' . e(date('M j, Y', strtotime((string)$member['expiry_date']))) : '' ?></small></div>
                <?php else: ?>
                    <span class="lr-dot none"></span>
                    <div><strong>Guest Profile</strong><small>Not yet enrolled in a membership</small></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($flash): ?><div class="lp-wrap"><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div></div><?php endif; ?>

    <section class="lr-top">
        <div class="lp-wrap lr-top-grid">
            <article class="lr-card" aria-label="Membership card">
                <div class="lr-card-head"><span class="lr-brand">Bella Vista Oasis</span><span class="lr-chip"><?= e($tier['tier_name'] ?? ($member ? ($member['membership_type'] ?: 'Member') : 'Guest')) ?></span></div>
                <p class="lr-card-title serif">Sanctuary Serenity Privilege</p>
                <div class="lr-card-name"><small>Cardholder</small><strong><?= e($customer['full_name']) ?></strong></div>
                <?php if ($member): ?>
                <dl class="lr-card-meta">
                    <div><dt>Membership No.</dt><dd><?= e($customer['membership_code'] ?: 'Pending') ?></dd></div>
                    <div><dt>Status</dt><dd class="cap"><?= e($member['status']) ?></dd></div>
                    <div><dt>Valid Until</dt><dd><?= $member['expiry_date'] ? e(date('M j, Y', strtotime((string)$member['expiry_date']))) : 'No expiry' ?></dd></div>
                </dl>
                <?php else: ?>
                <p class="lr-invite">You are not enrolled in a membership yet. Ask the concierge<?= $phone !== '' ? ' at <strong>' . e($phone) . '</strong>' : '' ?> to enroll you and unlock tier privileges and reward redemption.</p>
                <dl class="lr-card-meta one"><div><dt>Guest No.</dt><dd><?= e($customer['membership_code'] ?: 'Pending') ?></dd></div></dl>
                <?php endif; ?>
                <span class="lr-deco" aria-hidden="true"><?= lr_icon('lotus') ?></span>
            </article>

            <article class="lr-points">
                <p class="lp-eyebrow pink">Your Sanctuary Points</p>
                <p class="lr-balance" id="lrBalance"><span><?= number_format($balance) ?></span> Tranquility Points</p>
                <div class="lr-stats">
                    <div><b><?= number_format($totals['earned']) ?></b><small>Earned</small></div>
                    <div><b><?= number_format($totals['redeemed']) ?></b><small>Redeemed</small></div>
                    <div><b><?= $available ?></b><small>Rewards available</small></div>
                </div>
                <?php if ($next): ?>
                <div class="lr-progress" role="progressbar" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= $progress ?>%"></i></div>
                <p class="lr-progress-note"><?= number_format($next['points'] - $balance) ?> more points to unlock <strong><?= e($next['name']) ?></strong></p>
                <?php elseif ($member && $member['status'] === 'active'): ?>
                <p class="lr-progress-note">You have enough points for every reward in the catalog that is open to you.</p>
                <?php endif; ?>
                <div class="lr-actions">
                    <a class="lp-btn lp-btn-purple" href="#rewards" data-scroll><?= lr_icon('gift') ?>Redeem Rewards</a>
                    <a class="lp-btn lp-btn-pink" href="#history" data-scroll><?= lr_icon('history') ?>View Points History</a>
                </div>
            </article>
        </div>
    </section>

    <section class="lp-section" id="benefits">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink"><?= $proposed ? 'Starting Membership' : e($benefitLabel) . ' Membership' ?></p>
                <h2 class="serif">Emerald Sanctuary Privileges</h2>
                <p>Enjoy thoughtfully selected privileges designed to make every visit more rewarding.</p>
            </header>
            <?php if ($benefits): ?>
            <?php if ($proposed): ?><p class="lr-note">These are the privileges of the <?= e($benefitLabel) ?> tier, the entry membership. They apply once you are enrolled.</p><?php endif; ?>
            <div class="lr-benefits">
                <?php foreach ($benefits as $i => [$cat, $text]): ?>
                <div class="lr-benefit">
                    <span class="i <?= $i % 2 ? 'pink' : 'purple' ?>"><?= lr_icon($benefitIcons[$i % count($benefitIcons)]) ?></span>
                    <small class="<?= $i % 2 ? 'pink' : 'purple' ?>"><?= e($cat) ?></small>
                    <h3><?= e($text) ?></h3>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="lp-section" id="rewards">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow">Rewards</p>
                <h2 class="serif">Curated Rewards Catalog</h2>
                <p>Transform your earned tranquility points into meaningful wellness experiences.</p>
            </header>
            <?php if ($rewards): ?>
            <div class="lr-rewards">
                <?php foreach ($rewards as $i => $rw):
                    $img = $rw['image'] ? ADMIN_URL . '/assets/uploads/rewards/' . rawurlencode($rw['image']) : cu_url('/assets/images/' . ($images[$rw['category']] ?? 'hero-spa.jpg'));
                    $ok = $rw['block'] === null; $accent = $i % 2 ? 'pink' : 'purple';
                    $needMore = $rw['block'] !== null && str_starts_with($rw['block'], 'Needs '); ?>
                <article class="lp-card lr-reward <?= $ok ? '' : 'locked' ?>">
                    <div class="lp-card-img"><img src="<?= e($img) ?>" alt="<?= e($rw['name']) ?>" loading="lazy"><span class="lr-cat <?= $accent ?>"><?= e($rw['category']) ?></span></div>
                    <div class="lp-card-body">
                        <h3 class="serif"><?= e($rw['name']) ?></h3>
                        <p class="sv-desc"><?= e($rw['description']) ?></p>
                        <div class="lr-cost"><b><?= number_format($rw['points']) ?></b> points</div>
                        <p class="lr-status <?= $ok ? 'ok' : '' ?>"><?= $ok ? 'Available' . ($rw['quantity'] !== null ? ' · ' . $rw['quantity'] . ' left' : '') : e($rw['block']) ?><?= $rw['until'] ? ' · valid until ' . e(date('M j, Y', strtotime($rw['until']))) : '' ?></p>
                        <form method="post" action="<?= e(cu_url('/auth/redeem-process.php')) ?>" class="lr-form" data-name="<?= e($rw['name']) ?>" data-points="<?= (int)$rw['points'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reward_id" value="<?= (int)$rw['id'] ?>">
                            <input type="hidden" name="request_token" value="<?= e(bin2hex(random_bytes(16))) ?>">
                            <button type="submit" class="lp-btn <?= $accent === 'purple' ? 'lp-btn-purple' : 'lp-btn-pink' ?> lr-redeem" <?= ($ok || $needMore) ? '' : 'disabled' ?>><?= $needMore ? 'Redeem Reward' : 'Redeem' ?></button>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="lp-empty">New rewards are being curated. Please check back soon.</p><?php endif; ?>
        </div>
    </section>

    <section class="lp-section" id="history">
        <div class="lp-wrap">
            <header class="lp-head">
                <p class="lp-eyebrow pink">Ledger</p>
                <h2 class="serif">Points Transaction History</h2>
            </header>
            <?php if ($rows): ?>
            <div class="sv-table-wrap lr-table-wrap">
                <table class="sv-table lr-table">
                    <thead><tr><th>Date</th><th>Transaction</th><th>Description</th><th class="num">Points Earned</th><th class="num">Points Redeemed</th><th class="num">Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $pts = (int)$r['points']; ?>
                        <tr>
                            <td data-label="Date"><?= e(date('M j, Y', strtotime((string)$r['created_at']))) ?></td>
                            <td data-label="Transaction"><strong class="lr-tx"><?= e(cl_tx_label($r)) ?></strong></td>
                            <td data-label="Description"><?= e((string)$r['description']) ?></td>
                            <td data-label="Earned" class="num earn"><?= $pts > 0 ? '+' . number_format($pts) : '&ndash;' ?></td>
                            <td data-label="Redeemed" class="num spend"><?= $pts < 0 ? number_format(-$pts) : '&ndash;' ?></td>
                            <td data-label="Balance" class="num bal"><?= number_format((int)$r['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="lr-pager">
                <?php if (!$showAll && $totalRows > 5): ?>
                    <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/loyalty-rewards.php?history=all#history')) ?>">View All History (<?= $totalRows ?>)</a>
                <?php elseif ($showAll): ?>
                    <?php if ($pg > 1): ?><a class="lp-btn lp-btn-line" href="<?= e(cu_url('/loyalty-rewards.php?history=all&page=' . ($pg - 1) . '#history')) ?>">Previous</a><?php endif; ?>
                    <span class="lr-page">Page <?= $pg ?> of <?= $pages ?></span>
                    <?php if ($pg < $pages): ?><a class="lp-btn lp-btn-line" href="<?= e(cu_url('/loyalty-rewards.php?history=all&page=' . ($pg + 1) . '#history')) ?>">Next</a><?php endif; ?>
                    <a class="lp-btn lp-btn-line" href="<?= e(cu_url('/loyalty-rewards.php#history')) ?>">Show Latest Only</a>
                <?php endif; ?>
            </div>
            <?php else: ?><p class="lp-empty">No points activity yet. Points appear here after your completed visits.</p><?php endif; ?>
        </div>
    </section>
</main>

<div class="lr-modal" id="lrModal" role="dialog" aria-modal="true" aria-labelledby="lrModalTitle" hidden>
    <div class="lr-modal-box">
        <h3 class="serif" id="lrModalTitle">Confirm Redemption</h3>
        <p id="lrModalText"></p>
        <div class="lr-modal-actions">
            <button type="button" class="lp-btn lp-btn-line" id="lrCancel">Cancel</button>
            <button type="button" class="lp-btn lp-btn-purple" id="lrConfirm"><span class="btn-label">Confirm</span></button>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php';