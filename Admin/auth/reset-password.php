<?php
require_once __DIR__ . '/../config/database.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$valid = null;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare(
        "SELECT pr.id, pr.admin_id FROM password_resets pr
         JOIN administrators a ON a.id = pr.admin_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? AND a.status = 'active'"
    );
    $stmt->execute([hash('sha256', $token), now()]);
    $valid = $stmt->fetch() ?: null;
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['confirm'] ?? '');
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!password_is_strong($pw)) {
        $error = 'Password must be at least 8 characters with upper-case, lower-case and a number.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE administrators SET password = ?, remember_token = NULL WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $valid['admin_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE admin_id = ? AND used_at IS NULL')
            ->execute([now(), $valid['admin_id']]);
        $pdo->commit();
        $_SESSION['flash'] = 'Password updated. Sign in with your new password.';
        redirect('/auth/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create New Password | Bella Vista Oasis</title>
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
            <h2>New Password</h2>
            <?php if (!$valid): ?>
                <div class="alert alert-error" role="alert">This reset link is invalid or has expired.</div>
                <p class="back-link"><a href="<?= e(url('/auth/forgot-password.php')) ?>" class="link-small">Request a new link</a></p>
            <?php else: ?>
                <p class="sub">Choose a strong password for your account.</p>
                <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" action="" novalidate data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="field">
                        <label for="password">New Password <span class="req">*</span></label>
                        <div class="input"><?= ICON_LOCK ?>
                            <input type="password" id="password" name="password" autocomplete="new-password" data-rule="strong" required>
                            <button type="button" class="toggle-pw" data-target="password" aria-label="Show password"><?= ICON_EYE ?></button>
                        </div>
                        <span class="field-error" aria-live="polite"></span>
                    </div>
                    <div class="field">
                        <label for="confirm">Confirm Password <span class="req">*</span></label>
                        <div class="input"><?= ICON_LOCK ?>
                            <input type="password" id="confirm" name="confirm" autocomplete="new-password" data-rule="match:password" required>
                            <button type="button" class="toggle-pw" data-target="confirm" aria-label="Show password"><?= ICON_EYE ?></button>
                        </div>
                        <span class="field-error" aria-live="polite"></span>
                    </div>
                    <button type="submit" class="btn-primary" id="submitBtn">
                        <span class="btn-text">Update Password</span><?= ICON_ARROW ?><span class="spinner" aria-hidden="true"></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php auth_footer(); ?>
    </section>
</div>
<script src="<?= e(url('/assets/js/auth.js')) ?>"></script>
</body>
</html>