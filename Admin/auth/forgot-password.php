<?php
require_once __DIR__ . '/../config/database.php';

$error = $message = $devLink = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, full_name FROM administrators WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE admin_id = ? AND used_at IS NULL')->execute([now(), $admin['id']]);
            $pdo->prepare('INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (?,?,?)')
                ->execute([$admin['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + RESET_TOKEN_MINUTES * 60)]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $link = $scheme . '://' . $_SERVER['HTTP_HOST'] . url('/auth/reset-password.php') . '?token=' . $token;

            @mail($email, 'Bella Vista Oasis password reset',
                "Hello {$admin['full_name']},\n\nUse this link to reset your password (valid for " . RESET_TOKEN_MINUTES . " minutes):\n{$link}\n\nIf you did not request this, ignore this email.");

            if (SHOW_RESET_LINK) $devLink = $link;
        }
        // Same message either way so emails can't be probed
        $message = 'If that email belongs to an administrator, a reset link has been sent. It expires in ' . RESET_TOKEN_MINUTES . ' minutes.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | Bella Vista Oasis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/auth.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-shell">
    <?php auth_panel(); ?>
    <section class="form-panel">
        <div class="form-wrap">
            <p class="label-small">Password Recovery</p>
            <h2>Reset Password</h2>
            <p class="sub">Enter your administrator email and we'll send a reset link.</p>

            <?php if ($message): ?><div class="alert alert-ok" role="status"><?= e($message) ?></div><?php endif; ?>
            <?php if ($devLink): ?><div class="alert alert-dev"><strong>Local dev link:</strong><br><a href="<?= e($devLink) ?>"><?= e($devLink) ?></a></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

            <form method="post" action="" novalidate data-validate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">Administrator Email Address <span class="req">*</span></label>
                    <div class="input"><?= ICON_MAIL ?>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>" data-rule="email" placeholder="name@bellavista-oasis.com" required>
                    </div>
                    <span class="field-error" aria-live="polite"></span>
                </div>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <span class="btn-text">Send Reset Link</span><?= ICON_ARROW ?><span class="spinner" aria-hidden="true"></span>
                </button>
                <p class="back-link"><a href="<?= e(url('/auth/login.php')) ?>" class="link-small">Back to sign in</a></p>
            </form>
        </div>
        <?php auth_footer(); ?>
    </section>
</div>
<script src="<?= e(url('/assets/js/auth.js')) ?>"></script>
</body>
</html>