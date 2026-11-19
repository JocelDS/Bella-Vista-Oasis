<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';
require_once __DIR__ . '/../includes/account-data.php';

api_guard();

function acc_out(array $res, int $failCode = 422): never
{
    if (empty($res['ok'])) {
        http_response_code($failCode);
    }
    echo json_encode($res);
    exit;
}

try {
    $pdo = db();
    if (!acc_ready($pdo)) {
        acc_out(['ok' => false, 'error' => 'Account tables could not be created. Check the MySQL user permissions.'], 500);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        acc_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if (!csrf_verify()) {
        acc_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
    $adminId = (int)($_SESSION['admin_id'] ?? 0);

    switch ((string)($_POST['action'] ?? '')) {
        case 'save_all':
            $payload = json_decode((string)($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) {
                acc_out(['ok' => false, 'error' => 'Invalid request.'], 400);
            }
            [$err, $clean] = acc_validate($pdo, $adminId, $payload);
            if ($err) {
                acc_out(['ok' => false, 'error' => reset($err), 'fields' => $err]);
            }
            acc_out(acc_save_all($pdo, $adminId, $clean));

        case 'change_password':
            acc_out(acc_change_password(
                $pdo,
                $adminId,
                (string)($_POST['current'] ?? ''),
                (string)($_POST['new'] ?? ''),
                (string)($_POST['confirm'] ?? '')
            ));

        case 'revoke_devices':
            acc_out(acc_revoke_devices($pdo, $adminId));

        case 'upload_avatar':
            $f = $_FILES['avatar'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                acc_out(['ok' => false, 'error' => 'Choose an image to upload.']);
            }
            if ((int)$f['size'] > 2 * 1024 * 1024) {
                acc_out(['ok' => false, 'error' => 'The image must be 2 MB or smaller.']);
            }
            $info = @getimagesize((string)$f['tmp_name']);
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($types[$info[2]])) {
                acc_out(['ok' => false, 'error' => 'Use a JPG, PNG or WebP image.']);
            }
            $dir = acc_avatar_dir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                acc_out(['ok' => false, 'error' => 'The upload folder is not writable.'], 500);
            }
            $name = 'admin-' . $adminId . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
            if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) {
                acc_out(['ok' => false, 'error' => 'Unable to store the image.'], 500);
            }
            $old = $pdo->prepare('SELECT avatar_path FROM admin_profiles WHERE admin_id = ?');
            $old->execute([$adminId]);
            $oldFile = basename((string)$old->fetchColumn());
            $pdo->prepare('INSERT INTO admin_profiles (admin_id, avatar_path) VALUES (?, ?) ON DUPLICATE KEY UPDATE avatar_path = VALUES(avatar_path)')
                ->execute([$adminId, $name]);
            if ($oldFile !== '' && $oldFile !== $name && is_file($dir . '/' . $oldFile)) {
                @unlink($dir . '/' . $oldFile);
            }
            acc_log($pdo, $adminId, 'photo_changed', 'Profile photo updated.');
            acc_out(['ok' => true, 'message' => 'Profile photo updated.', 'url' => acc_avatar_url($name)]);

        case 'remove_avatar':
            $old = $pdo->prepare('SELECT avatar_path FROM admin_profiles WHERE admin_id = ?');
            $old->execute([$adminId]);
            $oldFile = basename((string)$old->fetchColumn());
            $pdo->prepare('UPDATE admin_profiles SET avatar_path = NULL WHERE admin_id = ?')->execute([$adminId]);
            if ($oldFile !== '' && is_file(acc_avatar_dir() . '/' . $oldFile)) {
                @unlink(acc_avatar_dir() . '/' . $oldFile);
            }
            acc_log($pdo, $adminId, 'photo_removed', 'Profile photo removed.');
            acc_out(['ok' => true, 'message' => 'Profile photo removed.', 'initials' => name_initials((string)($_SESSION['admin_name'] ?? ''))]);

        default:
            acc_out(['ok' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('[BVO account] ' . $e->getMessage());
    acc_out(['ok' => false, 'error' => 'Unable to complete that right now.'], 500);
}