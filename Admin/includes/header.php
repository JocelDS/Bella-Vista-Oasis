<?php
declare(strict_types=1);

require_once __DIR__ . '/../assets/icons/icons.php';

$pageTitle   = $pageTitle ?? 'Dashboard Overview';
$crumb       = $crumb ?? 'Dashboard';
$headerName  = (string)($_SESSION['admin_name'] ?? 'Administrator');
$notifList   = $notifications ?? [];
$latestNotif = $notifList ? (int)$notifList[0]['id'] : 0;
$accIdle     = '0';
$accDensity  = 'comfortable';
try {
    if (!empty($_SESSION['admin_id'])) {
        $accSt = db()->prepare("SELECT pref_key, pref_value FROM admin_preferences WHERE admin_id = ? AND pref_key IN ('idle_minutes', 'density')");
        $accSt->execute([(int)$_SESSION['admin_id']]);
        foreach ($accSt->fetchAll() as $accRow) {
            if ($accRow['pref_key'] === 'idle_minutes' && in_array((int)$accRow['pref_value'], [0, 5, 15, 30, 60], true)) { $accIdle = (string)(int)$accRow['pref_value']; }
            if ($accRow['pref_key'] === 'density' && in_array($accRow['pref_value'], ['comfortable', 'compact'], true)) { $accDensity = $accRow['pref_value']; }
        }
    }
} catch (Throwable $e) {
    // admin_preferences is created the first time Account Settings is opened.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> | Bella Vista Oasis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('/assets/css/dashboard.css')) ?>?v=<?= e((string)@filemtime(__DIR__ . '/../assets/css/dashboard.css')) ?>">
    <?php $dpCss = __DIR__ . '/../assets/css/date-picker.css'; ?>
    <link rel="stylesheet" href="<?= e(url('/assets/css/date-picker.css')) ?>?v=<?= e(is_file($dpCss) ? (string)filemtime($dpCss) : '1') ?>">
    <?php $extraStyles = $extraStyles ?? []; foreach ($extraStyles as $href): ?>
        <link rel="stylesheet" href="<?= e((string)$href) ?>">
    <?php endforeach; ?>
</head>
<body class="dash-body" data-api="<?= e(url('/api')) ?>" data-idle="<?= e($accIdle) ?>" data-density="<?= e($accDensity) ?>">
<div class="scrim" id="scrim" hidden></div>
<div class="app">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn menu-btn" id="menuBtn" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <?= icon('menu') ?>
            </button>

            <div class="tb-left">
                <p class="label-small"><?= e($pageTitle) ?></p>
                <p class="tb-date"><?= e(date('l, F j, Y')) ?></p>
                <p class="crumb"><a href="<?= e(url('/dashboard.php')) ?>">Home</a> <span>/</span> <?= e($crumb) ?></p>
            </div>

            <div class="tb-right">
                <form class="search" role="search" action="<?= e(url('/dashboard.php')) ?>" method="get" id="globalSearch">
                    <?= icon('search') ?>
                    <input type="search" id="globalSearchInput" placeholder="Search guests, bookings, suites..." aria-label="Search guests, bookings, suites">
                </form>

                <div class="dd-wrap" data-dropdown>
                    <button type="button" class="icon-btn bell" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notifications" id="bellBtn" data-latest="<?= $latestNotif ?>">
                        <?= icon('bell') ?>
                        <span class="bell-dot" id="bellDot" hidden></span>
                    </button>
                    <div class="dropdown dropdown-notif" data-dropdown-menu hidden>
                        <p class="dd-title">Notifications</p>
                        <?php if (!$notifList): ?>
                            <p class="dd-empty">You're all caught up.</p>
                        <?php else: foreach ($notifList as $n): ?>
                            <div class="notif-item">
                                <p class="notif-title"><?= e((string)$n['title']) ?></p>
                                <?php if (!empty($n['message'])): ?><p class="notif-msg"><?= e((string)$n['message']) ?></p><?php endif; ?>
                                <p class="notif-time"><?= e(dash_time_ago($n['created_at'] ?? null)) ?></p>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="dd-wrap" data-dropdown>
                    <button type="button" class="profile-mini" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                        <span class="avatar avatar-dark"><?= e(name_initials($headerName)) ?></span>
                        <?= icon('chevron', 'ico ico-sm') ?>
                    </button>
                    <div class="dropdown" data-dropdown-menu hidden>
                        <div class="dd-head">
                            <p class="profile-name"><?= e($headerName) ?></p>
                            <p class="profile-role">Administrator</p>
                        </div>
                        <a class="dd-item" href="<?= e(url('/account-settings.php')) ?>">
                            <?= icon('gear') ?><span>Account Settings</span>
                        </a>
                        <form method="post" action="<?= e(url('/auth/logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dd-item dd-danger"><?= icon('logout') ?><span>Log Out</span></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="content">