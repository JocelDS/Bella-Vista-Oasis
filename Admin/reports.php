<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/dashboard-data.php';
require_once __DIR__ . '/assets/icons/icons.php';
require_once __DIR__ . '/includes/analytics-data.php';

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$notifications = get_notifications($pdo, $adminId);
$selectedPeriod = analytics_normalize_period($_GET['period'] ?? '30d');
$startFilter = analytics_normalize_date($_GET['start'] ?? null);
$endFilter = analytics_normalize_date($_GET['end'] ?? null);

$range = analytics_period($selectedPeriod, $startFilter, $endFilter);
$summary = analytics_summary($pdo, $range['start'], $range['end'], $range['prevStart'], $range['prevEnd']);
$distribution = analytics_distribution($pdo, $range['start'], $range['end']);
$trend = analytics_revenue_trend($pdo, $range['start'], $range['end']);
$services = analytics_service_breakdown($pdo, $range['start'], $range['end']);
$rooms = analytics_room_breakdown($pdo, $range['start'], $range['end']);

$revenuePct = analytics_change_pct((float)$summary['revenue'], (float)$summary['previousRevenue']);
$appointmentPct = analytics_change_pct((float)$summary['appointments'], (float)$summary['previousAppointments']);
$avgPct = analytics_change_pct((float)$summary['avgTransaction'], (float)$summary['previousAvgTransaction']);
$utilization = (float)$summary['roomUtilization'];

$pageTitle = 'Reports & Analytics';
$crumb = 'Reports & Analytics';
$extraStyles = [url('/assets/css/analytics.css')];
$footerScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', url('/assets/js/analytics.js')];
require __DIR__ . '/includes/header.php';
?>
<section class="page-head page-head-analytics">
    <div>
        <h1 class="page-title">Sanctuary Operations &amp; Financial Analytics</h1>
        <p class="page-sub">Monitor sanctuary performance, financial activity, resource utilization, and operational efficiency through real-time management insights.</p>
    </div>
    <div class="page-actions">
        <div class="range-group" aria-label="Reporting period filters">
            <?php foreach (['today' => 'Today', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', 'month' => 'This Month'] as $value => $label): ?>
                <button type="submit" form="periodForm" name="period" value="<?= e($value) ?>" class="range-pill <?= $selectedPeriod === $value ? 'is-active' : '' ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<form id="periodForm" method="get" action="<?= e(url('/reports.php')) ?>" class="report-toolbar">
    <div class="range-inline">
        <div class="report-period">
            <span class="label-small">Current reporting period</span>
            <strong><?= e($range['label']) ?></strong>
        </div>
        <div class="date-pickers">
            <label>
                <span>Start date</span>
                <input type="date" name="start" value="<?= e($range['start']) ?>">
            </label>
            <label>
                <span>End date</span>
                <input type="date" name="end" value="<?= e($range['end']) ?>">
            </label>
        </div>
        <div class="report-actions">
            <button type="submit" name="period" value="custom" class="btn btn-primary"><?= icon('refresh') ?><span>Refresh</span></button>
            <a href="<?= e(url('/analytics/export-report.php')) ?>?period=<?= e($selectedPeriod) ?>&start=<?= e($range['start']) ?>&end=<?= e($range['end']) ?>" class="btn btn-soft">Export Report</a>
        </div>
    </div>
</form>

<section class="summary-grid" aria-label="Summary metrics">
    <article class="summary-card card-revenue">
        <div class="summary-top">
            <span class="label-small">Total Revenue</span>
            <span class="summary-icon">₱</span>
        </div>
        <p class="summary-value"><?= e(peso($summary['revenue'])) ?></p>
        <p class="summary-meta <?= $revenuePct >= 0 ? 'up' : 'down' ?>"><?= $revenuePct >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($revenuePct), 1)) ?>% vs previous period</p>
    </article>

    <article class="summary-card card-appointments">
        <div class="summary-top">
            <span class="label-small">Appointment Volume</span>
            <span class="summary-icon">⌁</span>
        </div>
        <p class="summary-value"><?= number_format((int)$summary['appointments']) ?></p>
        <p class="summary-meta <?= $appointmentPct >= 0 ? 'up' : 'down' ?>"><?= $appointmentPct >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($appointmentPct), 1)) ?>% vs previous period</p>
    </article>

    <article class="summary-card card-average">
        <div class="summary-top">
            <span class="label-small">Average Transaction Value</span>
            <span class="summary-icon">⟡</span>
        </div>
        <p class="summary-value"><?= e(peso($summary['avgTransaction'])) ?></p>
        <p class="summary-meta <?= $avgPct >= 0 ? 'up' : 'down' ?>"><?= $avgPct >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($avgPct), 1)) ?>% vs previous period</p>
    </article>

    <article class="summary-card card-utilization">
        <div class="summary-top">
            <span class="label-small">Room Utilization</span>
            <span class="summary-icon">◫</span>
        </div>
        <p class="summary-value"><?= e(number_format((float)$utilization, 1)) ?>%</p>
        <p class="summary-meta">Based on booked rooms / total rooms</p>
    </article>

    <article class="summary-card card-memberships">
        <div class="summary-top">
            <span class="label-small">Active Memberships</span>
            <span class="summary-icon">◎</span>
        </div>
        <p class="summary-value"><?= number_format((int)$summary['activeMemberships']) ?></p>
        <p class="summary-meta">Current active member base</p>
    </article>
</section>

<section class="analytics-shell">
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
                <?php $statusPalette = ['completed' => '#173D30', 'confirmed' => '#C4B58D', 'pending' => '#A3AA91', 'cancelled' => '#B58A78', 'no_show' => '#85877F']; ?>
                <?php foreach ($distribution['labels'] as $index => $label): ?>
                    <?php $swatchKey = strtolower((string)$label); ?>
                    <li>
                        <span class="legend-swatch" style="background: <?= e($statusPalette[$swatchKey] ?? '#173D30') ?>"></span>
                        <span><?= e(ucfirst($label)) ?></span>
                        <strong><?= number_format((int)($distribution['values'][$index] ?? 0)) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </article>

    <article class="card chart-card wide">
        <div class="card-head">
            <div>
                <p class="label-small">Revenue overview</p>
                <h2 class="card-title">Financial trend</h2>
            </div>
            <span class="chip-total"><?= e(peso($summary['revenue'])) ?></span>
        </div>
        <div class="chart-box large">
            <canvas id="revenueTrendChart" data-chart='<?= e(json_encode($trend)) ?>'></canvas>
        </div>
    </article>
</section>

<section class="bottom-grid">
    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Top services</p>
                <h2 class="card-title">Revenue by treatment</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($services): ?>
                        <?php foreach ($services as $row): ?>
                            <tr>
                                <td><?= e((string)($row['service_name'] ?? 'Unknown')) ?></td>
                                <td><?= (int)($row['bookings'] ?? 0) ?></td>
                                <td><?= e(peso((float)($row['revenue'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="empty-cell">No service data available for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card data-card">
        <div class="card-head">
            <div>
                <p class="label-small">Room performance</p>
                <h2 class="card-title">Suite utilization</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Suite</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rooms): ?>
                        <?php foreach ($rooms as $row): ?>
                            <tr>
                                <td><?= e((string)($row['room_name'] ?? 'Unknown')) ?></td>
                                <td><?= (int)($row['bookings'] ?? 0) ?></td>
                                <td><?= e(peso((float)($row['revenue'] ?? 0))) ?></td>
                                <td><?= e((string)($row['room_status'] ?? 'available')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="empty-cell">No room data available for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
