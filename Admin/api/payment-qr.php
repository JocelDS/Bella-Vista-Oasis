<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

api_guard();

const PQ_DIR   = __DIR__ . '/../assets/uploads/payment-qr';
const PQ_SLUGS = ['GCash' => 'gcash', 'Maya' => 'maya', 'Bank Transfer' => 'bank'];
const PQ_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

function pq_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pq_find(string $slug): ?string
{
    foreach (PQ_TYPES as $ext) {
        $path = PQ_DIR . '/' . $slug . '.' . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function pq_url(string $path): string
{
    return url('/assets/uploads/payment-qr/' . basename($path)) . '?v=' . (string)filemtime($path);
}

function pq_clear(string $slug): void
{
    foreach (PQ_TYPES as $ext) {
        $path = PQ_DIR . '/' . $slug . '.' . $ext;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

try {
    $channel = trim((string)($_REQUEST['channel'] ?? ''));
    if (!isset(PQ_SLUGS[$channel])) {
        pq_out(['ok' => false, 'error' => 'Choose GCash, Maya or Bank Transfer.'], 422);
    }
    $slug = PQ_SLUGS[$channel];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $path = pq_find($slug);
        pq_out(['ok' => true, 'channel' => $channel, 'url' => $path ? pq_url($path) : null]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pq_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if (!csrf_verify()) {
        pq_out(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }

    $action = (string)($_POST['action'] ?? 'upload');

    if ($action === 'remove') {
        pq_clear($slug);
        pq_out(['ok' => true, 'channel' => $channel, 'url' => null, 'message' => $channel . ' QR removed.']);
    }

    if ($action !== 'upload') {
        pq_out(['ok' => false, 'error' => 'Unknown action.'], 422);
    }

    $file = $_FILES['qr'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        pq_out(['ok' => false, 'error' => 'Choose a QR image to upload.'], 422);
    }
    if ((int)$file['size'] > 3 * 1024 * 1024) {
        pq_out(['ok' => false, 'error' => 'The QR image must be 3 MB or smaller.'], 422);
    }

    $info = @getimagesize((string)$file['tmp_name']);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (!isset(PQ_TYPES[$mime])) {
        pq_out(['ok' => false, 'error' => 'Upload a PNG, JPG or WebP image.'], 422);
    }

    if (!is_dir(PQ_DIR) && !mkdir(PQ_DIR, 0755, true) && !is_dir(PQ_DIR)) {
        pq_out(['ok' => false, 'error' => 'Unable to create the QR folder.'], 500);
    }
    $guard = PQ_DIR . '/.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "php_flag engine off\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php5\n");
    }

    pq_clear($slug);
    $target = PQ_DIR . '/' . $slug . '.' . PQ_TYPES[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        pq_out(['ok' => false, 'error' => 'Unable to save the QR image.'], 500);
    }

    pq_out(['ok' => true, 'channel' => $channel, 'url' => pq_url($target), 'message' => $channel . ' QR saved.']);
} catch (Throwable $e) {
    error_log('[BVO payment qr] ' . $e->getMessage());
    pq_out(['ok' => false, 'error' => 'Unable to process the QR right now. Please try again.'], 500);
}