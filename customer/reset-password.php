<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

$selector = (string)($_GET['s'] ?? '');
$token = (string)($_GET['t'] ?? '');
if (!cu_token_find('reset', $selector, $token)) {
    cu_flash('error', 'This reset link is invalid or has expired. Please request a new one.');
    cu_redirect('/forgot-password.php');
}

$pageTitle = 'Set a New Passcode';
$pageScripts = ['customer-register.js'];
require __DIR__ . '/includes/header.php';
?>
<main class="cu-main">
    <section class="cu-card cu-login-card" aria-labelledby="rpTitle">
        <p class="cu-eyebrow pink">Account Recovery</p>
        <h1 id="rpTitle" class="serif">Set a New Passcode</h1>
        <p class="cu-sub">Choose a new Sanctuary Passcode. Signing in on other devices will be required again afterwards.</p>

        <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div><?php endif; ?>

        <form id="resetForm" method="post" action="<?= e(cu_url('/auth/reset-password.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="s" value="<?= e($selector) ?>">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <?php foreach ([['password', 'New Passcode', 'At least 8 characters'], ['confirm_password', 'Confirm Passcode', 'Re-enter your passcode']] as [$n, $l, $p]): ?>
            <div class="cu-field">
                <label for="<?= e($n) ?>"><?= e($l) ?></label>
                <div class="cu-input">
                    <input type="password" id="<?= e($n) ?>" name="<?= e($n) ?>" autocomplete="new-password" placeholder="<?= e($p) ?>" required>
                    <button type="button" class="cu-toggle-pw" data-target="<?= e($n) ?>" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <span class="field-error" aria-live="polite"><?= e(cu_error($n)) ?></span>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="cu-btn cu-btn-primary cu-btn-lg"><span class="btn-label">Update Passcode</span></button>
        </form>
    </section>
    <?php require __DIR__ . '/includes/privilege-panel.php'; ?>
</main>
<?php
cu_clear_old();
require __DIR__ . '/includes/footer.php';
