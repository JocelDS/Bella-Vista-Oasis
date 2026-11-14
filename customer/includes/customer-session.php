<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

function cu_url(string $path = ''): string { return BASE_URL . CUSTOMER_URL_SUFFIX . $path; }
function cu_redirect(string $path): never { header('Location: ' . cu_url($path)); exit; }
function cu_is_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

function cu_flash(string $type, string $message): void { $_SESSION['cu_flash'] = ['type' => $type, 'message' => $message]; }
function cu_take_flash(): ?array
{
    $f = $_SESSION['cu_flash'] ?? null;
    unset($_SESSION['cu_flash']);
    return $f;
}
function cu_old(string $key): string { return (string)($_SESSION['cu_old'][$key] ?? ''); }
function cu_set_old(array $data): void { $_SESSION['cu_old'] = $data; }
function cu_clear_old(): void { unset($_SESSION['cu_old'], $_SESSION['cu_errors']); }
function cu_error(string $key): string { return (string)($_SESSION['cu_errors'][$key] ?? ''); }
function cu_set_errors(array $errors): void { $_SESSION['cu_errors'] = $errors; }

function cu_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM spa_configuration') as $r) {
                $cache[$r['setting_key']] = (string)$r['setting_value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    $v = trim($cache[$key] ?? '');
    return $v !== '' ? $v : $default;
}

function cu_rate_bucket(string $action, string $subject): string
{
    return hash('sha256', $action . '|' . strtolower($subject) . '|' . client_ip());
}
function cu_rate_count(string $action, string $subject, int $minutes): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM customer_rate_limits WHERE action = ? AND bucket = ? AND created_at > ?');
    $st->execute([$action, cu_rate_bucket($action, $subject), date('Y-m-d H:i:s', time() - $minutes * 60)]);
    return (int)$st->fetchColumn();
}
function cu_rate_hit(string $action, string $subject): void
{
    db()->prepare('INSERT INTO customer_rate_limits (action, bucket, created_at) VALUES (?, ?, ?)')
        ->execute([$action, cu_rate_bucket($action, $subject), now()]);
    if (random_int(1, 25) === 1) {
        db()->prepare('DELETE FROM customer_rate_limits WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    }
}
function cu_rate_clear(string $action, string $subject): void
{
    db()->prepare('DELETE FROM customer_rate_limits WHERE action = ? AND bucket = ?')
        ->execute([$action, cu_rate_bucket($action, $subject)]);
}

function cu_token_create(int $customerId, string $purpose, int $minutes): array
{
    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO customer_tokens (customer_id, purpose, selector, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$customerId, $purpose, $selector, hash('sha256', $token), date('Y-m-d H:i:s', time() + $minutes * 60), now()]);
    return [$selector, $token];
}
function cu_token_find(string $purpose, string $selector, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM customer_tokens WHERE purpose = ? AND selector = ? AND used_at IS NULL AND expires_at > ?');
    $st->execute([$purpose, $selector, now()]);
    $row = $st->fetch();
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $token))) return null;
    return $row;
}
function cu_token_burn(int $id): void
{
    db()->prepare('UPDATE customer_tokens SET used_at = ? WHERE id = ?')->execute([now(), $id]);
}

function cu_set_remember_cookie(int $customerId): void
{
    [$selector, $token] = cu_token_create($customerId, 'remember', CUSTOMER_REMEMBER_DAYS * 1440);
    setcookie(CUSTOMER_REMEMBER_COOKIE, $selector . ':' . $token, [
        'expires'  => time() + CUSTOMER_REMEMBER_DAYS * 86400,
        'path'     => cu_url(),
        'secure'   => cu_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function cu_clear_remember_cookie(): void
{
    if (!empty($_COOKIE[CUSTOMER_REMEMBER_COOKIE]) && str_contains($_COOKIE[CUSTOMER_REMEMBER_COOKIE], ':')) {
        [$selector] = explode(':', $_COOKIE[CUSTOMER_REMEMBER_COOKIE], 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            db()->prepare("DELETE FROM customer_tokens WHERE purpose = 'remember' AND selector = ?")->execute([$selector]);
        }
    }
    setcookie(CUSTOMER_REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => cu_url(), 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[CUSTOMER_REMEMBER_COOKIE]);
}

function cu_start_session(array $customer): void
{
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$customer['id'];
    $_SESSION['customer_name'] = (string)$customer['full_name'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE customers SET last_login_at = ? WHERE id = ?')->execute([now(), $customer['id']]);
}

function cu_restore_from_cookie(): bool
{
    if (!empty($_SESSION['customer_id'])) return true;
    $raw = (string)($_COOKIE[CUSTOMER_REMEMBER_COOKIE] ?? '');
    if (!str_contains($raw, ':')) return false;
    [$selector, $token] = explode(':', $raw, 2);
    $row = cu_token_find('remember', $selector, $token);
    if (!$row) {
        cu_clear_remember_cookie();
        return false;
    }
    $st = db()->prepare("SELECT * FROM customers WHERE id = ? AND customer_status = 'active'");
    $st->execute([$row['customer_id']]);
    $customer = $st->fetch();
    if (!$customer) {
        cu_clear_remember_cookie();
        return false;
    }
    cu_token_burn((int)$row['id']);
    cu_start_session($customer);
    cu_set_remember_cookie((int)$customer['id']);
    return true;
}

function cu_logged_in(): bool { return cu_restore_from_cookie(); }

function cu_deliver_link(string $email, string $subject, string $intro, string $link): ?string
{
    $body = $intro . "\r\n\r\n" . $link . "\r\n\r\nIf you did not request this, you can ignore this message.";
    $headers = 'From: Bella Vista Oasis <' . cu_setting('contact_email', 'no-reply@localhost') . ">\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($email, $subject, $body, $headers);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (in_array($ip, ['127.0.0.1', '::1'], true)) {
        return $link;
    }
    return null;
}

function cu_absolute(string $path): string
{
    $scheme = cu_is_https() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

function cu_normalize_phone(string $p): string { return trim(preg_replace('/\s+/', ' ', $p)); }
