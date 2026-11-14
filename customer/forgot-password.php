<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

if (cu_logged_in()) cu_redirect('/dashboard.php');

$pageTitle = 'Forgot Sanctuary Password';
$pageScripts = ['customer-login.js'];
$devLink = $_SESSION['cu_dev_link'] ?? null;
unset($_SESSION['cu_dev_link']);
require __DIR__ . '/includes/header.php';
?>
<main class="cu-main">
    <section class="cu-card cu-login-card" aria-labelledby="fpTitle">
        <p class="cu-eyebrow pink">Account Recovery</p>
        <h1 id="fpTitle" class="serif">Restore Your Access</h1>
        <p class="cu-sub">Enter your email and we will send a link to set a new Sanctuary Passcode. Returning guests who have not set a passcode yet can use this too.</p>

        <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($devLink): ?><div class="cu-alert cu-alert-info" role="status">Local development: email is not configured, so here is your link. <a href="<?= e($devLink) ?>">Open reset link</a></div><?php endif; ?>

        <form id="forgotForm" method="post" action="<?= e(cu_url('/auth/forgot-password.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="cu-field">
                <label for="email">Email Address</label>
                <div class="cu-input">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                    <input type="email" id="email" name="email" value="<?= e(cu_old('email')) ?>" autocomplete="email" placeholder="you@example.com" maxlength="190" required>
                </div>
                <span class="field-error" aria-live="polite"><?= e(cu_error('email')) ?></span>
            </div>
            <button type="submit" class="cu-btn cu-btn-primary cu-btn-lg"><span class="btn-label">Send Reset Link</span></button>
        </form>
        <p class="cu-note center"><a class="cu-link" href="<?= e(cu_url('/login.php')) ?>">Back to sign in</a></p>
    </section>
    <?php require __DIR__ . '/includes/privilege-panel.php'; ?>
</main>
<?php
cu_clear_old();
require __DIR__ . '/includes/footer.php';
