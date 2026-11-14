<?php
declare(strict_types=1);

$navItems = [
    ['Dashboard Overview',      'dashboard.php',            'grid'],
    ['Appointment Management',  'appointments.php',         'clipboard'],
    ['Appointment Calendar',    'appointment-calendar.php', 'calendar'],
    ['Room Management',         'room-management.php',      'door'],
    ['Therapist Assignment',    'therapist-assignment.php', 'user'],
    ['Customer Management',     'customers.php',            'users'],
    ['QR Check-in Management',  'qr-checkin.php',           'qr'],
    ['Membership & Loyalty',    'memberships.php',          'award'],
    ['Reports & Analytics',     'reports.php',              'chart'],
    ['Settings',                'settings.php',             'gear'],
];
$currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$sidebarName = (string)($_SESSION['admin_name'] ?? 'Administrator');
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sb-brand">
        <img src="<?= e(url('/assets/images/spa-logo.png')) ?>" alt="Bella Vista Oasis logo" class="sb-logo">
        <div>
            <p class="sb-name">Bella Vista Oasis</p>
            <p class="sb-sub">Spa &amp; Wellness</p>
        </div>
    </div>

    <nav class="sb-nav">
        <?php foreach ($navItems as [$label, $file, $ico]):
            $exists = is_file(__DIR__ . '/../' . $file);
            $active = $currentPage === $file;
            $classes = 'nav-link' . ($active ? ' is-active' : '') . ($exists ? '' : ' is-soon');
            ?>
            <a class="<?= e($classes) ?>"
               href="<?= $exists ? e(url('/' . $file)) : '#' ?>"
               <?= $active ? 'aria-current="page"' : '' ?>
               <?= $exists ? '' : 'data-soon="' . e($label) . '"' ?>>
                <?= icon($ico) ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sb-profile" data-dropdown>
        <button type="button" class="profile-btn" data-dropdown-toggle aria-haspopup="true" aria-expanded="false">
            <span class="avatar avatar-dark"><?= e(name_initials($sidebarName)) ?></span>
            <span class="profile-text">
                <span class="profile-name"><?= e($sidebarName) ?></span>
                <span class="profile-role">Administrator</span>
            </span>
            <?= icon('chevron', 'ico ico-sm') ?>
        </button>
        <div class="dropdown dropdown-up" data-dropdown-menu hidden>
            <a class="dd-item <?= is_file(__DIR__ . '/../settings.php') ? '' : 'is-soon' ?>"
               href="<?= is_file(__DIR__ . '/../settings.php') ? e(url('/settings.php')) : '#' ?>"
               <?= is_file(__DIR__ . '/../settings.php') ? '' : 'data-soon="Account Settings"' ?>>
                <?= icon('gear') ?><span>Account Settings</span>
            </a>
            <a class="dd-item" href="<?= e(BASE_URL . '/customer/landing.php') ?>" target="_blank" rel="noopener">
                <?= icon('users') ?><span>Customer Site</span>
            </a>
            <form method="post" action="<?= e(url('/auth/logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="dd-item dd-danger"><?= icon('logout') ?><span>Log Out</span></button>
            </form>
        </div>
    </div>
</aside>