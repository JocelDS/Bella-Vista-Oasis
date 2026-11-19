<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/dashboard-data.php';
require_once __DIR__ . '/assets/icons/icons.php';
require_once __DIR__ . '/includes/analytics-data.php';

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$notifications = get_notifications($pdo, $adminId);
$selectedPeriod = analytics_normalize_period($_GET['period'] ?? 'month');
$startFilter = analytics_normalize_date($_GET['start'] ?? null);
$endFilter = analytics_normalize_date($_GET['end'] ?? null);

$todayDate = date('Y-m-d');
$dailyDay = analytics_normalize_date($_GET['day'] ?? null) ?? $todayDate;
$daily = analytics_daily_report($pdo, $dailyDay);

$range = analytics_period($selectedPeriod, $startFilter, $endFilter);
$selectedPeriod = $range['period'];
$summary = analytics_summary($pdo, $range['start'], $range['end'], $range['prevStart'], $range['prevEnd']);
$completedStats = analytics_completed_stats($pdo, $range['start'], $range['end'], $range['prevStart'], $range['prevEnd']);
$distribution = analytics_distribution($pdo, $range['start'], $range['end']);
$pacing = analytics_revenue_pacing($pdo, $range['start'], $range['end']);
$mix = analytics_service_mix($pdo, $range['start'], $range['end']);
$matrix = analytics_therapist_matrix($pdo, $range['start'], $range['end']);
$suites = analytics_suite_health($pdo, $range['start'], $range['end']);
$methods = analytics_payment_methods($pdo, $range['start'], $range['end']);
$weekday = analytics_weekday_load($pdo, $range['start'], $range['end']);
$guests = analytics_guest_insights($pdo, $range['start'], $range['end']);
$loyalty = analytics_loyalty_insights($pdo, $range['start'], $range['end']);
$checkins = analytics_checkin_insights($pdo, $range['start'], $range['end']);

$revenuePct = analytics_change_pct((float)$summary['revenue'], (float)$summary['previousRevenue']);
$completedPct = analytics_change_pct((float)$completedStats['completed'], (float)$completedStats['previous']);
$avgPct = analytics_change_pct((float)$summary['avgTransaction'], (float)$summary['previousAvgTransaction']);

$deltaChip = static function (float $pct): string {
    $up = $pct >= 0;
    return '<span class="kpi-delta ' . ($up ? 'up' : 'down') . '">' . ($up ? '▲ +' : '▼ ') . e(number_format(abs($pct), 1)) . '%</span>';
};

$periodPills = [
    'today'   => 'Today',
    'week'    => 'This Week',
    'month'   => 'This Month',
    'quarter' => analytics_period('quarter')['label'],
];

$mixActive = array_values(array_filter($mix, static fn(array $r): bool => (float)$r['revenue'] > 0));
$mixTotal = 0.0;
foreach ($mixActive as $r) {
    $mixTotal += (float)$r['revenue'];
}
$sourceRows = [];
$otherRevenue = 0.0;
foreach ($mixActive as $i => $r) {
    if ($i < 3) {
        $sourceRows[] = ['name' => (string)$r['service_name'], 'revenue' => (float)$r['revenue']];
    } else {
        $otherRevenue += (float)$r['revenue'];
    }
}
if ($otherRevenue > 0) {
    $sourceRows[] = ['name' => 'Other treatments', 'revenue' => $otherRevenue];
}
$sourceColors = ['#986BC2', '#E18BB9', '#C5A0DD', '#D9B38C'];

$popular = array_values(array_filter($mix, static fn(array $r): bool => (int)$r['bookings'] > 0));
usort($popular, static fn(array $a, array $b): int => [(int)$b['bookings'], (float)$b['revenue']] <=> [(int)$a['bookings'], (float)$a['revenue']]);
$popular = array_slice($popular, 0, 5);
$popularTotal = 0;
foreach ($mix as $r) {
    $popularTotal += (int)$r['bookings'];
}
$popularMax = $popular ? max(1, (int)$popular[0]['bookings']) : 1;
$idleServices = count(array_filter($mix, static fn(array $r): bool => (int)$r['bookings'] === 0));

$recommendation = 'No revenue has been recorded for this period yet.';
if ($mixActive) {
    $topShare = $mixTotal > 0 ? ((float)$mixActive[0]['revenue'] / $mixTotal) * 100 : 0;
    $recommendation = $mixActive[0]['service_name'] . ' leads revenue with ' . number_format($topShare, 1) . '% of the total.';
    if ($idleServices > 0) {
        $recommendation .= ' ' . $idleServices . ' treatment' . ($idleServices === 1 ? ' has' : 's have') . ' no bookings in this period and may need promotion.';
    }
}

$pageTitle = 'Reports & Analytics';
$crumb = 'Reports & Analytics';
$extraStyles = [url('/assets/css/analytics.css')];
$footerScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', url('/assets/js/analytics.js')];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head page-head-analytics">
    <div>
        <h1 class="page-title">Reports &amp; Analytics</h1>
        <p class="page-sub">Operational insights, revenue reconciliation, therapist performance, and guest wellness analytics.</p>
    </div>
    <div class="page-actions report-head-actions">
        <div class="range-group" aria-label="Reporting period filters">
            <?php foreach ($periodPills as $value => $label): ?>
                <a href="<?= e(url('/reports.php')) ?>?period=<?= e($value) ?>" class="range-pill <?= $selectedPeriod === $value ? 'is-active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <button type="button" class="range-pill <?= $selectedPeriod === 'custom' ? 'is-active' : '' ?>" id="customToggle" aria-expanded="<?= $selectedPeriod === 'custom' ? 'true' : 'false' ?>" aria-controls="customRange">Custom</button>
        </div>
        <div class="report-actions">
            <a href="<?= e(url('/api/report-export.php')) ?>?period=<?= e($selectedPeriod) ?>&start=<?= e($range['start']) ?>&end=<?= e($range['end']) ?>" class="btn btn-soft">Download CSV</a>
            <button type="button" class="btn btn-primary" id="printReport">Export PDF Report</button>
        </div>
    </div>
</section>

<form id="customRange" method="get" action="<?= e(url('/reports.php')) ?>" class="report-toolbar custom-range" <?= $selectedPeriod === 'custom' ? '' : 'hidden' ?>>
    <input type="hidden" name="period" value="custom">
    <div class="range-inline">
        <div class="report-period">
            <span class="label-small">Reporting period</span>
            <strong><?= e(date('M j, Y', strtotime($range['start']))) ?> – <?= e(date('M j, Y', strtotime($range['end']))) ?></strong>
        </div>
        <div class="date-pickers">
            <label><span>Start date</span><input type="date" name="start" value="<?= e($range['start']) ?>"></label>
            <label><span>End date</span><input type="date" name="end" value="<?= e($range['end']) ?>"></label>
        </div>
        <div class="report-actions">
            <button type="submit" class="btn btn-primary"><?= icon('refresh') ?><span>Apply Range</span></button>
        </div>
    </div>
</form>

<p class="period-caption">Showing <strong><?= e($range['label']) ?></strong> · <?= e(date('M j, Y', strtotime($range['start']))) ?> – <?= e(date('M j, Y', strtotime($range['end']))) ?></p>

<section class="kpi-grid" aria-label="Key metrics">
    <article class="kpi-card card-revenue">
        <div class="kpi-top"><span class="label-small">Gross Revenue</span><span class="summary-icon">₱</span></div>
        <p class="kpi-value"><?= e(peso($summary['revenue'])) ?></p>
        <p class="kpi-compare"><?= $deltaChip($revenuePct) ?> <span>vs previous period</span></p>
        <dl class="kpi-foot">
            <div><dt>Transactions</dt><dd><?= number_format((int)$summary['transactions']) ?></dd></div>
            <div><dt>Prev. period</dt><dd><?= e(peso($summary['previousRevenue'])) ?></dd></div>
        </dl>
    </article>

    <article class="kpi-card card-appointments">
        <div class="kpi-top"><span class="label-small">Completed Treatments</span><span class="summary-icon">✓</span></div>
        <p class="kpi-value"><?= number_format((int)$completedStats['completed']) ?></p>
        <p class="kpi-compare"><?= $deltaChip($completedPct) ?> <span>vs previous period</span></p>
        <dl class="kpi-foot">
            <div><dt>Completion rate</dt><dd><?= e(number_format((float)$completedStats['completionRate'], 1)) ?>%</dd></div>
            <div><dt>Daily density</dt><dd><?= e(number_format((float)$completedStats['avgDaily'], 1)) ?> / day</dd></div>
        </dl>
    </article>

    <article class="kpi-card card-average">
        <div class="kpi-top"><span class="label-small">Average Ticket Size</span><span class="summary-icon">⟡</span></div>
        <p class="kpi-value"><?= e(peso($summary['avgTransaction'])) ?></p>
        <p class="kpi-compare"><?= $deltaChip($avgPct) ?> <span>vs previous period</span></p>
        <dl class="kpi-foot">
            <div><dt>Appointments</dt><dd><?= number_format((int)$summary['appointments']) ?></dd></div>
            <div><dt>Room utilization</dt><dd><?= e(number_format((float)$summary['roomUtilization'], 1)) ?>%</dd></div>
        </dl>
    </article>

    <article class="kpi-card card-memberships">
        <div class="kpi-top"><span class="label-small">Guest Retention</span><span class="summary-icon">♥</span></div>
        <p class="kpi-value"><?= e(number_format((float)$guests['returningRate'], 1)) ?>%</p>
        <p class="kpi-compare"><span class="kpi-note"><?= number_format((int)$guests['returning']) ?> returning of <?= number_format((int)$guests['activeGuests']) ?> active guests</span></p>
        <dl class="kpi-foot">
            <div><dt>New guests</dt><dd><?= number_format((int)$guests['newGuests']) ?></dd></div>
            <div><dt>Active members</dt><dd><?= number_format((int)$summary['activeMemberships']) ?></dd></div>
        </dl>
    </article>
</section>

<section class="pacing-layout">
    <article class="card chart-card">
        <div class="card-head">
            <div>
                <h2 class="card-title"><?= $pacing['weekly'] ? 'Weekly' : 'Daily' ?> Revenue Pacing</h2>
                <p class="card-sub">Gross revenue collected per <?= $pacing['weekly'] ? 'week' : 'day' ?> across the selected period.</p>
            </div>
            <span class="chip-total"><?= e(peso($pacing['total'])) ?></span>
        </div>
        <div class="chart-box large">
            <canvas id="pacingChart" data-chart='<?= e(json_encode($pacing, JSON_UNESCAPED_UNICODE)) ?>'></canvas>
        </div>
        <p class="chart-foot">Revenue counts paid payments plus deposits on active bookings or forfeited late cancellations.</p>
    </article>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <h2 class="card-title">Source Distribution</h2>
                <p class="card-sub">Revenue share by treatment.</p>
            </div>
            <span class="chip-total"><?= count($mixActive) ?> treatment<?= count($mixActive) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($sourceRows): ?>
            <ul class="progress-list">
                <?php foreach ($sourceRows as $i => $row): ?>
                    <?php $share = $mixTotal > 0 ? ($row['revenue'] / $mixTotal) * 100 : 0; ?>
                    <li>
                        <div class="progress-meta"><span><?= e($row['name']) ?></span><strong><?= e(peso($row['revenue'])) ?></strong></div>
                        <div class="progress-track"><span style="width: <?= e(number_format($share, 1, '.', '')) ?>%; background: <?= e($sourceColors[$i % 4]) ?>"></span></div>
                        <small><?= e(number_format($share, 1)) ?>% of revenue</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="empty-cell">No revenue recorded for this period.</p>
        <?php endif; ?>
        <div class="note-box">
            <span class="label-small">Recommendation</span>
            <p><?= e($recommendation) ?></p>
        </div>
    </article>
</section>

<section class="card data-card matrix-card">
    <div class="card-head">
        <div>
            <h2 class="card-title">Therapist Utilization &amp; Performance Matrix</h2>
            <p class="card-sub">Sessions, billed hours, revenue and shift utilization for each active therapist.</p>
        </div>
        <span class="chip-total"><?= count($matrix) ?> therapists</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Therapist &amp; Specialty</th><th>Completed Sessions</th><th>Booked Hours</th><th>Generated Revenue</th><th>Utilization Rate</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if ($matrix): ?>
                    <?php foreach ($matrix as $row): ?>
                        <?php
                        $util = (float)$row['utilization'];
                        $statusClass = $util >= 80 ? 'high' : ($util >= 40 ? 'balanced' : 'open');
                        $statusLabel = $util >= 80 ? 'High Load' : ($util >= 40 ? 'Balanced' : 'Open Capacity');
                        $initials = strtoupper(substr((string)$row['name'], 0, 1) . (strpos((string)$row['name'], ' ') !== false ? substr((string)strrchr((string)$row['name'], ' '), 1, 1) : ''));
                        ?>
                        <tr>
                            <td>
                                <div class="person-cell">
                                    <span class="avatar-chip"><?= e($initials) ?></span>
                                    <span><strong><?= e($row['name']) ?></strong><br><span class="label-small"><?= e($row['specialty'] !== '' ? $row['specialty'] : '—') ?></span></span>
                                </div>
                            </td>
                            <td><strong><?= (int)$row['completed'] ?></strong> sessions</td>
                            <td><?= e(number_format((float)$row['hours'], 1)) ?> hrs</td>
                            <td><?= e(peso($row['revenue'])) ?></td>
                            <td>
                                <div class="util-cell">
                                    <span class="util-value"><?= e(number_format($util, 0)) ?>%</span>
                                    <span class="progress-track slim"><span style="width: <?= e(number_format($util, 1, '.', '')) ?>%"></span></span>
                                </div>
                            </td>
                            <td><span class="status-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="empty-cell">No active therapists found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="bottom-grid">
    <article class="card data-card">
        <div class="card-head">
            <div>
                <h2 class="card-title">Treatment Popularity</h2>
                <p class="card-sub">Most booked treatments, ranked by volume.</p>
            </div>
            <span class="chip-total">Sorted by volume</span>
        </div>
        <?php if ($popular): ?>
            <ul class="progress-list">
                <?php foreach ($popular as $row): ?>
                    <?php $width = ((int)$row['bookings'] / $popularMax) * 100; $share = $popularTotal > 0 ? ((int)$row['bookings'] / $popularTotal) * 100 : 0; ?>
                    <li>
                        <div class="progress-meta"><span><?= e($row['service_name']) ?></span><strong><?= e(number_format($share, 0)) ?>% <small>(<?= (int)$row['bookings'] ?> booking<?= (int)$row['bookings'] === 1 ? '' : 's' ?>)</small></strong></div>
                        <div class="progress-track"><span style="width: <?= e(number_format($width, 1, '.', '')) ?>%"></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="empty-cell">No bookings in this period.</p>
        <?php endif; ?>
        <p class="chart-foot split"><span>Average session duration</span><strong><?= e(number_format((float)$suites['avgDuration'], 0)) ?> min · <?= e(number_format((float)$suites['avgGuests'], 1)) ?> guests / session</strong></p>
    </article>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <h2 class="card-title">Sanctuary Suite Operational Health</h2>
                <p class="card-sub">Suite availability, cleaning protocol and maintenance activity.</p>
            </div>
            <span class="status-badge <?= $suites['maintenance'] > 0 ? 'balanced' : 'high' ?>"><?= $suites['maintenance'] > 0 ? 'Needs Attention' : 'Optimal Protocol' ?></span>
        </div>
        <dl class="suite-stats">
            <div><dt>Suites Available</dt><dd><?= (int)$suites['available'] ?><small> / <?= (int)$suites['total'] ?></small></dd><span>in service now</span></div>
            <div><dt>Cleaning Gap</dt><dd><?= $suites['gapEnabled'] ? (int)$suites['gapMinutes'] : 0 ?><small> min</small></dd><span><?= $suites['gapEnabled'] ? 'between sessions' : 'gap disabled' ?></span></div>
            <div><dt>Maintenance Logs</dt><dd><?= (int)$suites['logs'] ?></dd><span><?= (int)$suites['openLogs'] ?> still open</span></div>
        </dl>
        <div class="suite-bars">
            <div>
                <div class="progress-meta"><span>Room utilization</span><strong><?= e(number_format((float)$summary['roomUtilization'], 1)) ?>%</strong></div>
                <div class="progress-track"><span style="width: <?= e(number_format(min(100, (float)$summary['roomUtilization']), 1, '.', '')) ?>%"></span></div>
            </div>
            <div>
                <div class="progress-meta"><span>Cancellations &amp; no-shows</span><strong><?= e(number_format((float)$summary['lostRate'], 1)) ?>%</strong></div>
                <div class="progress-track"><span style="width: <?= e(number_format(min(100, (float)$summary['lostRate']), 1, '.', '')) ?>%; background: #E18BB9"></span></div>
            </div>
        </div>
        <p class="chart-foot split"><span>Cleaning verification</span><strong><?= $suites['verification'] ? 'Required before reuse' : 'Not required' ?></strong></p>
    </article>
</section>

<h2 class="section-heading">More reports</h2>

<section class="insight-grid" aria-label="Appointment, demand and payment reports">
    <article class="card chart-card">
        <div class="card-head">
            <div>
                <p class="label-small">Appointment distribution</p>
                <h2 class="card-title">Guest flow by status</h2>
            </div>
            <span class="chip-total"><?= number_format((int)$distribution['total']) ?> total</span>
        </div>
        <div class="donut-layout">
            <div class="donut-wrap">
                <canvas id="appointmentDistributionChart" data-chart='<?= e(json_encode($distribution)) ?>'></canvas>
            </div>
            <ul class="legend-list">
                <?php foreach ($distribution['labels'] as $index => $label): ?>
                    <li>
                        <span class="legend-swatch" style="background: <?= e((string)($distribution['colors'][$index] ?? '#173D30')) ?>"></span>
                        <span><?= e((string)$label) ?></span>
                        <strong><?= number_format((int)($distribution['values'][$index] ?? 0)) ?></strong>
                    </li>
                <?php endforeach; ?>
                <?php if (!$distribution['labels']): ?>
                    <li class="empty-cell">No appointments in this period.</li>
                <?php endif; ?>
            </ul>
        </div>
    </article>
    <article class="card chart-card">
        <div class="card-head">
            <div>
                <p class="label-small">Demand pattern</p>
                <h2 class="card-title">Bookings by weekday</h2>
            </div>
        </div>
        <div class="chart-box">
            <canvas id="weekdayChart" data-chart='<?= e(json_encode($weekday)) ?>'></canvas>
        </div>
    </article>
    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Payment channels</p>
                <h2 class="card-title">Revenue by payment method</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th><th>Share</th></tr></thead>
                <tbody>
                    <?php if ($methods): ?>
                        <?php foreach ($methods as $row): ?>
                            <tr>
                                <td><?= e((string)$row['method']) ?></td>
                                <td><?= (int)$row['transactions'] ?></td>
                                <td><?= e(peso((float)$row['revenue'])) ?></td>
                                <td><?= e(number_format((float)$row['share'], 1)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="empty-cell">No payments recorded for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="insight-grid" aria-label="Guest, loyalty and arrival reports">
    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Guest report</p>
                <h2 class="card-title">Guest activity</h2>
            </div>
            <span class="chip-total"><?= number_format((int)$guests['activeGuests']) ?> active</span>
        </div>
        <dl class="mini-stats">
            <div><dt>New guests registered</dt><dd><?= number_format((int)$guests['newGuests']) ?></dd></div>
            <div><dt>Returning guests</dt><dd><?= number_format((int)$guests['returning']) ?></dd></div>
            <div><dt>First-time guests</dt><dd><?= number_format((int)$guests['firstTime']) ?></dd></div>
            <div><dt>Returning rate</dt><dd><?= e(number_format((float)$guests['returningRate'], 1)) ?>%</dd></div>
        </dl>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Top guests</th><th>Bookings</th><th>Spent</th></tr></thead>
                <tbody>
                    <?php if ($guests['top']): ?>
                        <?php foreach ($guests['top'] as $row): ?>
                            <tr>
                                <td><?= e((string)$row['full_name']) ?></td>
                                <td><?= (int)$row['bookings'] ?></td>
                                <td><?= e(peso((float)$row['spent'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="empty-cell">No guest spending in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Membership report</p>
                <h2 class="card-title">Membership &amp; loyalty</h2>
            </div>
            <span class="chip-total"><?= number_format((int)$summary['activeMemberships']) ?> members</span>
        </div>
        <dl class="mini-stats">
            <div><dt>Points earned</dt><dd><?= number_format((int)$loyalty['earned']) ?></dd></div>
            <div><dt>Points redeemed</dt><dd><?= number_format((int)$loyalty['redeemed']) ?></dd></div>
            <div><dt>Expiring in 30 days</dt><dd><?= number_format((int)$loyalty['expiring']) ?></dd></div>
        </dl>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Tier</th><th>Active members</th></tr></thead>
                <tbody>
                    <?php if ($loyalty['tiers']): ?>
                        <?php foreach ($loyalty['tiers'] as $row): ?>
                            <tr><td><?= e((string)$row['tier']) ?></td><td><?= (int)$row['members'] ?></td></tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="empty-cell">No active memberships.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Arrival report</p>
                <h2 class="card-title">Check-in performance</h2>
            </div>
            <span class="chip-total"><?= e(number_format((float)$checkins['rate'], 1)) ?>% checked in</span>
        </div>
        <dl class="mini-stats">
            <div><dt>Confirmed &amp; completed bookings</dt><dd><?= number_format((int)$checkins['expected']) ?></dd></div>
            <div><dt>Checked in</dt><dd><?= number_format((int)$checkins['checkedIn']) ?></dd></div>
            <div><dt>Via QR scan</dt><dd><?= number_format((int)$checkins['byQr']) ?></dd></div>
            <div><dt>Manual check-in</dt><dd><?= number_format((int)$checkins['byManual']) ?></dd></div>
        </dl>
    </article>
</section>

<h2 class="section-heading">Daily report</h2>

<section class="daily-report" aria-labelledby="dailyTitle">
    <form method="get" action="<?= e(url('/reports.php')) ?>" class="report-toolbar">
        <input type="hidden" name="period" value="<?= e($selectedPeriod) ?>">
        <?php if ($selectedPeriod === 'custom'): ?>
            <input type="hidden" name="start" value="<?= e($range['start']) ?>">
            <input type="hidden" name="end" value="<?= e($range['end']) ?>">
        <?php endif; ?>
        <div class="range-inline">
            <div class="report-period">
                <span class="label-small">Daily report</span>
                <strong id="dailyTitle"><?= e($daily['label']) ?><?= $dailyDay === $todayDate ? ' · Today' : '' ?></strong>
            </div>
            <div class="date-pickers">
                <label>
                    <span>Report date</span>
                    <input type="date" name="day" value="<?= e($dailyDay) ?>">
                </label>
            </div>
            <div class="report-actions">
                <button type="submit" class="btn btn-primary"><?= icon('refresh') ?><span>View Day</span></button>
                <?php if ($dailyDay !== $todayDate): ?>
                    <a href="<?= e(url('/reports.php')) ?>?period=<?= e($selectedPeriod) ?>" class="btn btn-soft">Back to Today</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="summary-grid" aria-label="Daily summary">
        <article class="summary-card card-appointments">
            <div class="summary-top"><span class="label-small">Total Bookings</span><span class="summary-icon">⌁</span></div>
            <p class="summary-value"><?= number_format($daily['total']) ?></p>
            <p class="summary-meta"><?= (int)$daily['active'] ?> active · <?= (int)$daily['counts']['cancelled'] ?> cancelled · <?= (int)$daily['counts']['no_show'] ?> no-show</p>
        </article>
        <article class="summary-card card-utilization">
            <div class="summary-top"><span class="label-small">Active Therapists</span><span class="summary-icon">◎</span></div>
            <p class="summary-value"><?= number_format(count($daily['therapists'])) ?></p>
            <p class="summary-meta"><?= $daily['unassigned'] > 0 ? (int)$daily['unassigned'] . ' booking(s) still without a therapist' : 'Every active booking has a therapist' ?></p>
        </article>
        <article class="summary-card card-revenue">
            <div class="summary-top"><span class="label-small">Total Revenue</span><span class="summary-icon">₱</span></div>
            <p class="summary-value"><?= e(peso($daily['revenue'])) ?></p>
            <p class="summary-meta">Paid <?= e(peso($daily['paid'])) ?> + downpayments <?= e(peso($daily['down'])) ?></p>
        </article>
    </div>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Active therapist schedules</p>
                <h2 class="card-title">Sessions by therapist</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Therapist</th><th>Time</th><th>Service</th><th>Suite</th><th>Guest</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if ($daily['therapists']): ?>
                        <?php foreach ($daily['therapists'] as $th): ?>
                            <?php foreach ($th['sessions'] as $i => $s): ?>
                                <tr>
                                    <td><?php if ($i === 0): ?><strong><?= e($th['name']) ?></strong><br><span class="label-small"><?= e($th['specialization']) ?> · <?= count($th['sessions']) ?> session<?= count($th['sessions']) === 1 ? '' : 's' ?></span><?php endif; ?></td>
                                    <td><?= e($s['time']) ?></td>
                                    <td><?= e($s['service']) ?></td>
                                    <td><?= e($s['room']) ?></td>
                                    <td><?= e($s['customer']) ?></td>
                                    <td><?= e($s['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty-cell">No therapist sessions are scheduled for this day.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>