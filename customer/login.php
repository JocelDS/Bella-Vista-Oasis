<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

if (cu_logged_in()) cu_redirect('/dashboard.php');

$pageTitle = 'Return to Your Sanctuary';
$pageScripts = ['customer-login.js'];
$devLink = $_SESSION['cu_dev_link'] ?? null;
unset($_SESSION['cu_dev_link']);
require __DIR__ . '/includes/header.php';
?>
<main class="cu-main">
    <section class="cu-card cu-login-card" aria-labelledby="loginTitle">
        <div class="cu-switch" role="tablist" aria-label="Access type">
            <button type="button" class="active" role="tab" aria-selected="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>Patron &amp; Guest Access</button>
            <a href="<?= e(ADMIN_URL . '/auth/login.php') ?>" role="tab" aria-selected="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg>Admin &amp; Concierge</a>
        </div>

        <p class="cu-eyebrow pink">Welcome to Serenity</p>
        <h1 id="loginTitle" class="serif">Return to Your Sanctuary</h1>
        <p class="cu-sub">Access your bespoke treatment bookings, loyalty points balance, and contactless QR passes.</p>

        <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($devLink): ?><div class="cu-alert cu-alert-info" role="status">Local development: email is not configured, so here is your link. <a href="<?= e($devLink) ?>">Open sign-in link</a></div><?php endif; ?>

        <form id="loginForm" method="post" action="<?= e(cu_url('/auth/login-process.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="cu-field">
                <div class="cu-label-row"><label for="identifier">Email or Membership ID</label><span class="hint">e.g. BV-000001</span></div>
                <div class="cu-input"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                    <input type="text" id="identifier" name="identifier" value="<?= e(cu_old('identifier')) ?>" autocomplete="username" placeholder="you@example.com or BV-000001" maxlength="190" required>
                </div>
                <span class="field-error" aria-live="polite"><?= e(cu_error('identifier')) ?></span>
            </div>
            <div class="cu-field">
                <div class="cu-label-row"><label for="password">Sanctuary Passcode</label><a href="<?= e(cu_url('/forgot-password.php')) ?>" class="cu-link">Forgot Sanctuary Password?</a></div>
                <div class="cu-input"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                    <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your passcode" required>
                    <button type="button" class="cu-toggle-pw" data-target="password" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <span class="field-error" aria-live="polite"><?= e(cu_error('password')) ?></span>
            </div>
            <div class="cu-row-between">
                <label class="cu-check"><input type="checkbox" name="remember" value="1"><span>Remember this device for <?= (int)CUSTOMER_REMEMBER_DAYS ?> days</span></label>
                <button type="button" class="cu-link cu-link-btn" id="magicToggle">Send Magic Link</button>
            </div>
            <button type="submit" class="cu-btn cu-btn-primary cu-btn-lg"><span class="btn-label">Sign In to Sanctuary</span><svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></button>
        </form>

        <form id="magicForm" class="cu-magic" method="post" action="<?= e(cu_url('/auth/otp-process.php')) ?>" hidden novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <label for="magicEmail">Email a one-time sign-in link</label>
            <div class="cu-magic-row">
                <input type="email" id="magicEmail" name="email" placeholder="Your account email" autocomplete="email" maxlength="190" required>
                <button type="submit" class="cu-btn cu-btn-soft"><span class="btn-label">Send Link</span></button>
            </div>
            <span class="field-error" aria-live="polite"></span>
            <p class="cu-note">The link works once and expires in <?= (int)CUSTOMER_MAGIC_MINUTES ?> minutes.</p>
        </form>

        <div class="cu-enroll">
            <div><strong>First time at Bella Vista?</strong><p>Create your guest profile and receive <?= (int)CUSTOMER_WELCOME_POINTS ?> welcome points.</p></div>
            <a class="cu-btn cu-btn-soft" href="<?= e(cu_url('/register.php')) ?>">Enroll Guest</a>
        </div>
    </section>
    <?php require __DIR__ . '/includes/privilege-panel.php'; ?>
</main>
<?php
cu_clear_old();
require __DIR__ . '/includes/footer.php';