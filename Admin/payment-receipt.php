<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/billing-data.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
$rc  = null;
if ($id > 0 && bill_ready($pdo)) {
    try {
        $rc = bill_receipt($pdo, $id);
    } catch (Throwable $e) {
        error_log('[BVO receipt] ' . $e->getMessage());
    }
}
if (!$rc) {
    http_response_code(404);
    exit('Receipt not found.');
}
$width = ($_GET['w'] ?? '80') === '58' ? '58mm' : '80mm';
$auto  = ($_GET['print'] ?? '') === '1';
$money = static fn(float $v): string => '₱' . number_format($v, 2);
$title = $rc['status'] === 'pending' ? 'SUITE CHARGE SLIP' : 'OFFICIAL RECEIPT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= e($rc['invoice']) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/payments.css')) ?>">
    <style>@page { size: <?= e($width) ?> auto; margin: 0; } .slip { width: <?= e($width) ?>; }</style>
</head>
<body class="slip-body">
<div class="slip-tools">
    <button type="button" onclick="window.print()">Print</button>
    <a href="?id=<?= $id ?>&amp;w=58">58mm</a>
    <a href="?id=<?= $id ?>&amp;w=80">80mm</a>
</div>
<div class="slip" id="slip">
    <div class="slip-center">
        <img src="<?= e(url('/assets/images/spa-logo.png')) ?>" alt="Bella Vista Oasis" class="slip-logo">
        <p class="slip-brand">BELLA VISTA OASIS</p>
        <p class="slip-sub">Spa &amp; Wellness</p>
        <p class="slip-title"><?= e($title) ?></p>
    </div>
    <hr>
    <dl class="slip-grid">
        <dt>Invoice</dt><dd><?= e($rc['invoice']) ?></dd>
        <dt>Date</dt><dd><?= e($rc['date']) ?></dd>
        <dt>Guest</dt><dd><?= e($rc['guest']) ?></dd>
        <dt>Reservation</dt><dd><?= e($rc['ref']) ?></dd>
    </dl>
    <hr>
    <p class="slip-line"><span><?= e($rc['service']) ?><?= $rc['duration'] ? ' (' . (int)$rc['duration'] . ' min)' : '' ?></span><span><?= e($money($rc['base'])) ?></span></p>
    <?php foreach ($rc['charges'] as $c): $neg = $c['charge_type'] === 'discount'; ?>
        <p class="slip-line"><span><?= e((string)$c['label']) ?></span><span><?= $neg ? '-' : '' ?><?= e($money((float)$c['amount'])) ?></span></p>
    <?php endforeach; ?>
    <hr>
    <p class="slip-line"><span>Gross Amount</span><span><?= e($money($rc['gross'])) ?></span></p>
    <?php if ($rc['discount'] > 0): ?><p class="slip-line"><span>Discount</span><span>-<?= e($money($rc['discount'])) ?></span></p><?php endif; ?>
    <p class="slip-line"><span>Deposit</span><span><?= e($money($rc['deposit'])) ?></span></p>
    <p class="slip-line slip-strong"><span>Amount Paid</span><span><?= e($money($rc['paid'])) ?></span></p>
    <p class="slip-line"><span>Remaining Balance</span><span><?= e($money($rc['balance'])) ?></span></p>
    <hr>
    <p class="slip-line"><span>Payment Method</span><span><?= e($rc['method']) ?></span></p>
    <?php if ($rc['ext_ref'] !== ''): ?><p class="slip-line"><span>Reference</span><span><?= e($rc['ext_ref']) ?></span></p><?php endif; ?>
    <p class="slip-line"><span>Processed by</span><span><?= e($rc['admin']) ?></span></p>
    <hr>
    <p class="slip-center slip-foot">Thank you for visiting Bella Vista Oasis.</p>
    <?php if ($rc['status'] === 'pending'): ?><p class="slip-center slip-foot">Authorized suite charge. Not yet settled.</p><?php endif; ?>
</div>
<?php if ($auto): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script><?php endif; ?>
</body>
</html>