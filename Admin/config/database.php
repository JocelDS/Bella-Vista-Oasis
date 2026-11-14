<?php
declare(strict_types=1);

/* ---------- Settings (edit for your XAMPP setup) ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'bella_vista_oasis');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/bella-vista-oasis');   
define('ADMIN_URL', BASE_URL . '/admin');   
define('MAX_ATTEMPTS', 5);                 
define('LOCK_MINUTES', 15);
define('RESET_TOKEN_MINUTES', 60);
define('REMEMBER_DAYS', 30);
define('SHOW_RESET_LINK', false);            

date_default_timezone_set('Asia/Manila');

/* ---------- Session ---------- */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    session_name('BVO_ADMIN_SESSION');      
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => ADMIN_URL,            
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------- Database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Check config/database.php and that MySQL is running.');
        }
    }
    return $pdo;
}

/* ---------- Helpers ---------- */
function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function url(string $path): string { return ADMIN_URL . $path; }  // path relative to /admin
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

function password_is_strong(string $p): bool
{
    return strlen($p) >= 8 && preg_match('/[A-Z]/', $p) && preg_match('/[a-z]/', $p) && preg_match('/\d/', $p);
}

/* ---------- Remember-me cookie ---------- */
function set_remember_cookie(int $adminId): void
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE administrators SET remember_token = ? WHERE id = ?')
        ->execute([hash('sha256', $token), $adminId]);
    setcookie('bvo_admin_remember', $adminId . ':' . $token, [
        'expires'  => time() + REMEMBER_DAYS * 86400,
        'path'     => ADMIN_URL,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function clear_remember_cookie(): void
{
    setcookie('bvo_admin_remember', '', ['expires' => time() - 3600, 'path' => ADMIN_URL, 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE['bvo_admin_remember']);
}
function start_admin_session(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']    = (int)$admin['id'];
    $_SESSION['admin_name']  = $admin['full_name'];
    $_SESSION['admin_role']  = $admin['role'];
    $_SESSION['csrf']        = bin2hex(random_bytes(32));
}
function restore_from_cookie(): bool
{
    if (!empty($_SESSION['admin_id'])) return true;
    if (empty($_COOKIE['bvo_admin_remember']) || !str_contains($_COOKIE['bvo_admin_remember'], ':')) return false;
    [$id, $token] = explode(':', $_COOKIE['bvo_admin_remember'], 2);
    $stmt = db()->prepare("SELECT * FROM administrators WHERE id = ? AND status = 'active' AND remember_token IS NOT NULL");
    $stmt->execute([(int)$id]);
    $admin = $stmt->fetch();
    if ($admin && hash_equals($admin['remember_token'], hash('sha256', $token))) {
        start_admin_session($admin);
        return true;
    }
    clear_remember_cookie();
    return false;
}

/* ---------- Shared auth page pieces ---------- */
function auth_panel(): void { ?>
<aside class="brand-panel">
    <div class="brand-content">
        <div class="brand-top">
            <img src="<?= e(url('/assets/images/spa-logo.png')) ?>" alt="Bella Vista Oasis logo" class="brand-logo">
            <div>
                <p class="label-small gold">Spa Sanctuary Console</p>
                <p class="system-name">Oasis Operations Hub</p>
            </div>
        </div>
        <div class="brand-bottom">
            <h1>Bella Vista Oasis</h1>
            <p class="tagline">Where Serenity Meets Exceptional Care. Architectural tranquility meets sovereign management architecture.</p>
        </div>
    </div>
</aside>
<?php }

function auth_footer(): void { ?>
<footer class="auth-footer">
    <span class="label-small">Authorized<br>Personnel Only</span>
    <span class="copy">&copy; 2026 Bella Vista Oasis Spa.<br>All Rights Reserved.</span>
    <span class="label-small support">Support &bull;<br>Ext. 104</span>
</footer>
<?php }

const ICON_MAIL = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
const ICON_LOCK = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
const ICON_EYE  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const ICON_ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>';

/* ---------- Room images (uploaded in Room Management, shown to customers) ---------- */
define('ROOM_UPLOAD_DIR', dirname(__DIR__, 2) . '/uploads/rooms');
define('ROOM_UPLOAD_URL', BASE_URL . '/uploads/rooms');
define('ROOM_IMAGE_MAX_BYTES', 2 * 1024 * 1024);

/** Adds room_details.image_path if the database was imported without it. Runs once per request. */
function room_images_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM room_details LIKE 'image_path'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE room_details ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL');
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[BVO room images] ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function room_image_url(?string $file): ?string
{
    $file = basename((string)$file);
    return $file !== '' ? ROOM_UPLOAD_URL . '/' . rawurlencode($file) : null;
}

/** First uploaded image per room code (SR, CS, FL ...), as ['SR' => 'file.jpg']. */
function room_images_by_code(PDO $pdo): array
{
    $map = [];
    if (!room_images_ready($pdo)) return $map;
    try {
        foreach ($pdo->query("SELECT r.room_name, d.image_path FROM rooms r JOIN room_details d ON d.room_id = r.id WHERE d.image_path IS NOT NULL AND d.image_path <> '' ORDER BY r.id")->fetchAll() as $row) {
            $code = strtoupper(explode('-', (string)$row['room_name'])[0]);
            if (!isset($map[$code]) && is_file(ROOM_UPLOAD_DIR . '/' . basename((string)$row['image_path']))) {
                $map[$code] = (string)$row['image_path'];
            }
        }
    } catch (Throwable $e) {
    }
    return $map;
}