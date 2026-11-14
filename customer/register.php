<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/customer-session.php';

if (cu_logged_in()) cu_redirect('/dashboard.php');

$pageTitle = 'Enroll as a Guest';
$pageScripts = ['customer-register.js'];
require __DIR__ . '/includes/header.php';

function reg_field(string $name, string $label, string $type, string $auto, string $ph, bool $password = false): void
{
    $val = $password ? '' : cu_old($name);
    ?>
    <div class="cu-field">
        <label for="<?= e($name) ?>"><?= e($label) ?></label>
        <div class="cu-input">
            <input type="<?= e($type) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>" autocomplete="<?= e($auto) ?>" placeholder="<?= e($ph) ?>" required>
            <?php if ($password): ?><button type="button" class="cu-toggle-pw" data-target="<?= e($name) ?>" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button><?php endif; ?>
        </div>
        <span class="field-error" aria-live="polite"><?= e(cu_error($name)) ?></span>
    </div>
    <?php
}
?>
<main class="cu-main">
    <section class="cu-card cu-login-card" aria-labelledby="regTitle">
        <p class="cu-eyebrow pink">First time at Bella Vista?</p>
        <h1 id="regTitle" class="serif">Create Your Guest Profile</h1>
        <p class="cu-sub">Create your guest profile and receive <?= (int)CUSTOMER_WELCOME_POINTS ?> welcome points.</p>

        <?php if ($flash): ?><div class="cu-alert cu-alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div><?php endif; ?>

        <form id="registerForm" method="post" action="<?= e(cu_url('/auth/register-process.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php
            reg_field('full_name', 'Full Name', 'text', 'name', 'Your full name');
            reg_field('email', 'Email Address', 'email', 'email', 'you@example.com');
            reg_field('phone', 'Phone Number', 'tel', 'tel', '0917 000 0000');
            reg_field('password', 'Password', 'password', 'new-password', 'At least 8 characters', true);
            reg_field('confirm_password', 'Confirm Password', 'password', 'new-password', 'Re-enter your password', true);
            ?>
            <button type="submit" class="cu-btn cu-btn-primary cu-btn-lg"><span class="btn-label">Create Guest Profile</span><svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></button>
        </form>
        <div class="cu-enroll">
            <div><strong>Already enrolled?</strong><p>Return to your sanctuary with your email or Membership ID.</p></div>
            <a class="cu-btn cu-btn-soft" href="<?= e(cu_url('/login.php')) ?>">Sign In</a>
        </div>
    </section>
    <?php require __DIR__ . '/includes/privilege-panel.php'; ?>
</main>
<?php
cu_clear_old();
require __DIR__ . '/includes/footer.php';
