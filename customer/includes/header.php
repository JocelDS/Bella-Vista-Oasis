<?php
$pageTitle = $pageTitle ?? 'Bella Vista Oasis';
$activeNav = $activeNav ?? '';
$flash = cu_take_flash();
$contactEmail = cu_setting('contact_email');
$contactPhone = cu_setting('contact_phone');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Bella Vista Oasis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(cu_url('/assets/css/customer-login.css')) ?>">
    <link rel="stylesheet" href="<?= e(cu_url('/assets/css/customer-register.css')) ?>">
    <link rel="stylesheet" href="<?= e(cu_url('/assets/css/customer-responsive.css')) ?>">
    <?php foreach (($pageStyles ?? []) as $css): ?><link rel="stylesheet" href="<?= e(cu_url('/assets/css/' . $css)) ?>"><?php endforeach; ?>
</head>
<body>
<div class="cu-topbar">
    <div class="cu-topbar-in">
        <span><?php $l1 = cu_setting('address_line1'); $l3 = cu_setting('address_line3'); echo e(trim($l1 . ($l1 !== '' && $l3 !== '' ? ' · ' : '') . $l3)); ?></span>
        <span class="cu-topbar-contact">
            <?php if ($contactPhone !== ''): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a><?php endif; ?>
            <?php if ($contactEmail !== ''): ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a><?php endif; ?>
        </span>
    </div>
</div>
<header class="cu-nav">
    <div class="cu-nav-in">
        <a class="cu-brand" href="<?= e(cu_url('/landing.php')) ?>">
            <img src="<?= e(cu_url('/assets/images/bella-vista-logo.png')) ?>" alt="Bella Vista Oasis Spa logo" width="46" height="46">
            <span><strong>Bella Vista</strong><small>Oasis Spa &amp; Sanctuary</small></span>
        </a>
        <button class="cu-nav-toggle" type="button" aria-expanded="false" aria-controls="cuNav" aria-label="Open menu"><span></span><span></span><span></span></button>
        <nav id="cuNav" class="cu-nav-links" aria-label="Main">
            <a href="<?= e(cu_url('/landing.php')) ?>" class="<?= $activeNav === 'home' ? 'active' : '' ?>">Home</a>
            <a href="<?= e(cu_url('/services.php')) ?>" class="<?= $activeNav === 'services' ? 'active' : '' ?>">Services &amp; Rates</a>
            <a href="<?= e(cu_url('/book-appointment.php')) ?>" class="<?= $activeNav === 'book' ? 'active' : '' ?>">Book Appointment</a>
            <a href="<?= e(cu_url('/qr-checkin.php')) ?>" class="<?= $activeNav === 'qr' ? 'active' : '' ?>">QR Check-In</a>
            <a href="<?= e(cu_url('/loyalty-rewards.php')) ?>" class="<?= $activeNav === 'loyalty' ? 'active' : '' ?>">Loyalty &amp; Rewards</a>
            <?php if ($contactEmail !== ''): ?><a href="mailto:<?= e($contactEmail) ?>">Help / Support</a><?php endif; ?>
            <?php if (!empty($_SESSION['customer_id'])): ?>
                <a class="cu-nav-cta" href="<?= e(cu_url('/dashboard.php')) ?>">My Sanctuary</a>
            <?php else: ?>
                <a class="cu-nav-cta" href="<?= e(cu_url('/register.php')) ?>">Enroll Guest</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<div id="cuToast" class="cu-toast" role="status" aria-live="polite"></div>