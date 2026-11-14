<?php
require_once __DIR__ . '/../config/database.php';

if (restore_from_cookie()) redirect('/dashboard.php');

$error = '';
$email = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $ip       = client_ip();

    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE email = ? AND ip_address = ?');
        $stmt->execute([$email, $ip]);
        $att = $stmt->fetch();

        if ($att && $att['locked_until'] && $att['locked_until'] > now()) {
            $mins = max(1, (int)ceil((strtotime($att['locked_until']) - time()) / 60));
            $error = "Too many failed attempts. Try again in {$mins} minute(s).";
        } else {
            if ($att && $att['locked_until']) { // lock expired: reset counter
                $pdo->prepare('UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE id = ?')->execute([$att['id']]);
                $att['attempts'] = 0;
            }

            $stmt = $pdo->prepare("SELECT * FROM administrators WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            // Always run a hash check so timing doesn't reveal whether the email exists
            $hash = $admin['password'] ?? '$2y$12$yeVpqsXeHDUx.LB9AunXquvOWK6NZkg0uaHVqsJFNMb0UZEScFQta';
            $ok = password_verify($password, $hash) && $admin;

            if ($ok) {
                $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')->execute([$email, $ip]);
                if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE administrators SET password = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
                }
                start_admin_session($admin);
                if ($remember) set_remember_cookie((int)$admin['id']); else clear_remember_cookie();
                redirect('/dashboard.php');
            }

            // Record failure
            $count = ($att['attempts'] ?? 0) + 1;
            $lock  = $count >= MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
            if ($att) {
                $pdo->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = ?, locked_until = ? WHERE id = ?')
                    ->execute([$count, now(), $lock, $att['id']]);
            } else {
                $pdo->prepare('INSERT INTO login_attempts (email, ip_address, attempts, last_attempt, locked_until) VALUES (?,?,?,?,?)')
                    ->execute([$email, $ip, $count, now(), $lock]);
            }
            $error = $lock
                ? 'Too many failed attempts. This account is locked for ' . LOCK_MINUTES . ' minutes.'
                : 'Incorrect email address or password. ' . (MAX_ATTEMPTS - $count) . ' attempt(s) left.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | Bella Vista Oasis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-shell">
    <?php auth_panel(); ?>
    <section class="form-panel">
        <div class="form-wrap">
            <p class="label-small">Management Portal</p>
            <h2>Welcome Back</h2>
            <p class="sub">Sign in to access your spa management console.</p>

            <?php if ($notice): ?><div class="alert alert-ok" role="status"><?= e($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

            <form id="loginForm" method="post" action="" novalidate data-validate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">Administrator Email Address <span class="req">*</span></label>
                    <div class="input"><?= ICON_MAIL ?>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" data-rule="email" placeholder="name@bellavista-oasis.com" required>
                    </div>
                    <span class="field-error" aria-live="polite"></span>
                </div>
                <div class="field">
                    <div class="label-row">
                        <label for="password">Password <span class="req">*</span></label>
                        <a href="<?= e(url('/auth/forgot-password.php')) ?>" class="link-small">Forgot Password?</a>
                    </div>
                    <div class="input"><?= ICON_LOCK ?>
                        <input type="password" id="password" name="password" autocomplete="current-password" data-rule="required" placeholder="Enter your password" required>
                        <button type="button" class="toggle-pw" data-target="password" aria-label="Show password"><?= ICON_EYE ?></button>
                    </div>
                    <span class="field-error" aria-live="polite"></span>
                </div>
                <label class="check">
                    <input type="checkbox" name="remember" value="1">
                    <span>Remember Me on this browser</span>
                </label>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <span class="btn-text">Sign In to Dashboard</span><?= ICON_ARROW ?><span class="spinner" aria-hidden="true"></span>
                </button>
            </form>
        </div>
        <?php auth_footer(); ?>
    </section>
</div>
<script src="<?= e(url('/assets/js/auth.js')) ?>"></script>
</body>
</html>