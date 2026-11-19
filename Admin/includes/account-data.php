<?php
declare(strict_types=1);

/* Account & Sanctuary Settings — data layer (existing PDO connection, existing administrators table). */

const ACC_IDLE_OPTIONS    = [0, 5, 15, 30, 60];
const ACC_DENSITY_OPTIONS = ['comfortable' => 'Calming (Comfortable)', 'compact' => 'Compact (Dense)'];
const ACC_LANGUAGES       = ['English (Philippines)', 'Filipino (Sanctuary Format)', 'Spanish', 'Mandarin', 'Korean', 'Japanese'];
const ACC_ALERTS = [
    'alert_vip'            => ['High-Value Guest VIP Arrivals', 'Priority', 'Instant alert when a VIP or high-tier member is about to arrive for a reservation.'],
    'alert_sanitation'     => ['Cabana Sanitization & Air Cycle', '', 'Reminder when a suite finishes its cleaning gap and its air cycle is due.'],
    'alert_reconciliation' => ['Shift Reversals & Daily Reconciliations', '', 'Notice when a therapist shift is reversed or the daily payment reconciliation is ready.'],
    'alert_dispatch'       => ['Therapist Dispatch Emergency / Unplanned Overtime', '', 'Escalation when a session needs an emergency therapist or runs into overtime.'],
];
const ACC_PREF_DEFAULTS = [
    'two_factor'           => '0',
    'idle_minutes'         => '0',
    'density'              => 'comfortable',
    'alert_vip'            => '1',
    'alert_sanitation'     => '1',
    'alert_reconciliation' => '1',
    'alert_dispatch'       => '1',
];
const ACC_MODULES = [
    ['Dashboard Overview', 'dashboard.php'], ['Appointment Management', 'appointments.php'], ['Appointment Calendar', 'appointment-calendar.php'],
    ['Payment Management', 'payments.php'], ['Services Management', 'services.php'], ['Room Management', 'room-management.php'],
    ['Therapist Assignment', 'therapist-assignment.php'], ['Customer Management', 'customers.php'], ['QR Check-in Management', 'qr-checkin.php'],
    ['Membership & Loyalty', 'memberships.php'], ['Reports & Analytics', 'reports.php'], ['System Configuration', 'settings.php'],
];

/** Creates the three account tables inside bella_vista_oasis when they are missing. Runs once per request. */
function acc_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_profiles (
            admin_id INT NOT NULL PRIMARY KEY,
            designation VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            extension VARCHAR(10) NULL,
            languages VARCHAR(255) NULL,
            avatar_path VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_preferences (
            admin_id INT NOT NULL,
            pref_key VARCHAR(60) NOT NULL,
            pref_value VARCHAR(255) NOT NULL,
            PRIMARY KEY (admin_id, pref_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(80) NOT NULL,
            detail VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_admin_created (admin_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ok = true;
    } catch (Throwable $e) {
        error_log('[BVO account] ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function acc_log(PDO $pdo, int $adminId, string $action, string $detail = ''): void
{
    try {
        $pdo->prepare('INSERT INTO admin_audit_log (admin_id, action, detail, ip_address, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId, $action, mb_substr($detail, 0, 255), client_ip(), now()]);
    } catch (Throwable $e) {
        error_log('[BVO account log] ' . $e->getMessage());
    }
}

function acc_load(PDO $pdo, int $adminId): array
{
    $st = $pdo->prepare('SELECT id, full_name, email, role, status, created_at FROM administrators WHERE id = ?');
    $st->execute([$adminId]);
    $admin = $st->fetch() ?: [];

    $st = $pdo->prepare('SELECT designation, phone, extension, languages, avatar_path FROM admin_profiles WHERE admin_id = ?');
    $st->execute([$adminId]);
    $p = $st->fetch() ?: [];

    $prefs = ACC_PREF_DEFAULTS;
    $st = $pdo->prepare('SELECT pref_key, pref_value FROM admin_preferences WHERE admin_id = ?');
    $st->execute([$adminId]);
    foreach ($st->fetchAll() as $r) {
        if (array_key_exists($r['pref_key'], $prefs)) {
            $prefs[$r['pref_key']] = (string)$r['pref_value'];
        }
    }

    $langs = array_values(array_filter(array_map('trim', explode(',', (string)($p['languages'] ?? '')))));
    if (!$langs) {
        $langs = [ACC_LANGUAGES[0]];
    }

    return [
        'admin' => $admin,
        'profile' => [
            'designation' => (string)($p['designation'] ?? ''),
            'phone'       => (string)($p['phone'] ?? ''),
            'extension'   => (string)($p['extension'] ?? ''),
            'languages'   => $langs,
            'avatar'      => (string)($p['avatar_path'] ?? ''),
        ],
        'prefs' => $prefs,
    ];
}

function acc_avatar_dir(): string { return __DIR__ . '/../assets/uploads/admins'; }

function acc_avatar_url(?string $file): ?string
{
    $file = basename((string)$file);
    if ($file === '' || !is_file(acc_avatar_dir() . '/' . $file)) {
        return null;
    }
    return url('/assets/uploads/admins/' . rawurlencode($file));
}

/** @return array{0: array<string,string>, 1: array<string,mixed>} [errors, clean] */
function acc_validate(PDO $pdo, int $adminId, array $in): array
{
    $err = [];
    $pr = is_array($in['profile'] ?? null) ? $in['profile'] : [];
    $pf = is_array($in['prefs'] ?? null) ? $in['prefs'] : [];

    $name = trim((string)($pr['full_name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $err['full_name'] = 'Enter your full legal name (2–120 characters).';
    }
    $email = strtolower(trim((string)($pr['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $err['email'] = 'Enter a valid email address.';
    } else {
        $st = $pdo->prepare('SELECT id FROM administrators WHERE email = ? AND id <> ?');
        $st->execute([$email, $adminId]);
        if ($st->fetch()) {
            $err['email'] = 'That email already belongs to another administrator.';
        }
    }
    $designation = trim((string)($pr['designation'] ?? ''));
    if (mb_strlen($designation) > 120) {
        $err['designation'] = 'Role title is too long (120 characters max).';
    }
    $phone = trim((string)($pr['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{5,40}$/', $phone)) {
        $err['phone'] = 'Use digits, spaces, +, - or parentheses only.';
    }
    $ext = trim((string)($pr['extension'] ?? ''));
    if ($ext !== '' && !preg_match('/^\d{1,10}$/', $ext)) {
        $err['extension'] = 'Extension must be digits only.';
    }
    $langs = [];
    foreach ((array)($pr['languages'] ?? []) as $l) {
        if (in_array($l, ACC_LANGUAGES, true) && !in_array($l, $langs, true)) {
            $langs[] = $l;
        }
    }
    if (!$langs) {
        $err['languages'] = 'Select at least one language.';
    }

    $prefs = [];
    foreach (['two_factor', 'alert_vip', 'alert_sanitation', 'alert_reconciliation', 'alert_dispatch'] as $k) {
        $prefs[$k] = !empty($pf[$k]) && $pf[$k] !== '0' ? '1' : '0';
    }
    $idle = (int)($pf['idle_minutes'] ?? 0);
    if (!in_array($idle, ACC_IDLE_OPTIONS, true)) {
        $err['idle_minutes'] = 'Choose one of the listed timeouts.';
    }
    $prefs['idle_minutes'] = (string)$idle;
    $density = (string)($pf['density'] ?? 'comfortable');
    if (!isset(ACC_DENSITY_OPTIONS[$density])) {
        $err['density'] = 'Choose a console mode from the list.';
    }
    $prefs['density'] = $density;

    return [$err, [
        'full_name' => $name, 'email' => $email, 'designation' => $designation, 'phone' => $phone,
        'extension' => $ext, 'languages' => $langs, 'prefs' => $prefs,
        'confirm_password' => (string)($in['confirm_password'] ?? ''),
    ]];
}

function acc_save_all(PDO $pdo, int $adminId, array $c): array
{
    $cur = acc_load($pdo, $adminId);
    $a = $cur['admin'];
    if (!$a) {
        return ['ok' => false, 'error' => 'Account not found.'];
    }

    $emailChanged = strtolower((string)$a['email']) !== $c['email'];
    if ($emailChanged) {
        $st = $pdo->prepare('SELECT password FROM administrators WHERE id = ?');
        $st->execute([$adminId]);
        $hash = (string)$st->fetchColumn();
        if ($c['confirm_password'] === '' || !password_verify($c['confirm_password'], $hash)) {
            return ['ok' => false, 'error' => 'Confirm your master passphrase to change the login email.', 'need_password' => true];
        }
    }

    $changes = [];
    if ($a['full_name'] !== $c['full_name'])                          { $changes[] = 'name'; }
    if ($emailChanged)                                                { $changes[] = 'login email'; }
    if ($cur['profile']['designation'] !== $c['designation'])         { $changes[] = 'role title'; }
    if ($cur['profile']['phone'] !== $c['phone'])                     { $changes[] = 'phone'; }
    if ($cur['profile']['extension'] !== $c['extension'])             { $changes[] = 'extension'; }
    if ($cur['profile']['languages'] !== $c['languages'])             { $changes[] = 'languages'; }
    foreach ($c['prefs'] as $k => $v) {
        if (($cur['prefs'][$k] ?? '') !== $v) {
            $changes[] = $k;
        }
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE administrators SET full_name = ?, email = ? WHERE id = ?')->execute([$c['full_name'], $c['email'], $adminId]);
        $pdo->prepare('INSERT INTO admin_profiles (admin_id, designation, phone, extension, languages) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE designation = VALUES(designation), phone = VALUES(phone), extension = VALUES(extension), languages = VALUES(languages)')
            ->execute([$adminId, $c['designation'] ?: null, $c['phone'] ?: null, $c['extension'] ?: null, implode(',', $c['languages'])]);
        $ps = $pdo->prepare('INSERT INTO admin_preferences (admin_id, pref_key, pref_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)');
        foreach ($c['prefs'] as $k => $v) {
            $ps->execute([$adminId, $k, $v]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BVO account save] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to save your changes right now.'];
    }

    $_SESSION['admin_name'] = $c['full_name'];
    if ($changes) {
        acc_log($pdo, $adminId, 'profile_updated', 'Updated: ' . implode(', ', $changes));
    }
    return ['ok' => true, 'message' => $changes ? 'Account settings saved.' : 'Nothing to change.', 'name' => $c['full_name'], 'initials' => name_initials($c['full_name'])];
}

function acc_change_password(PDO $pdo, int $adminId, string $current, string $new, string $confirm): array
{
    $st = $pdo->prepare('SELECT password FROM administrators WHERE id = ?');
    $st->execute([$adminId]);
    $hash = (string)$st->fetchColumn();
    if ($current === '' || !password_verify($current, $hash)) {
        return ['ok' => false, 'error' => 'Your current passphrase is incorrect.', 'field' => 'current'];
    }
    if (!password_is_strong($new)) {
        return ['ok' => false, 'error' => 'Use at least 8 characters with an uppercase letter, a lowercase letter and a number.', 'field' => 'new'];
    }
    if ($new !== $confirm) {
        return ['ok' => false, 'error' => 'The new passphrases do not match.', 'field' => 'confirm'];
    }
    if (password_verify($new, $hash)) {
        return ['ok' => false, 'error' => 'Choose a passphrase different from the current one.', 'field' => 'new'];
    }
    // Rotating the passphrase also drops every remembered-device token.
    $pdo->prepare('UPDATE administrators SET password = ?, remember_token = NULL WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]), $adminId]);
    clear_remember_cookie();
    acc_log($pdo, $adminId, 'passphrase_rotated', 'Master sanctuary passphrase changed.');
    return ['ok' => true, 'message' => 'Passphrase updated. Remembered devices were signed out.'];
}

function acc_revoke_devices(PDO $pdo, int $adminId): array
{
    $pdo->prepare('UPDATE administrators SET remember_token = NULL WHERE id = ?')->execute([$adminId]);
    clear_remember_cookie();
    acc_log($pdo, $adminId, 'devices_revoked', 'Remembered-device sign-ins revoked.');
    return ['ok' => true, 'message' => 'Remembered devices were signed out. This browser stays signed in until you log out.'];
}

function acc_has_remembered(PDO $pdo, int $adminId): bool
{
    $st = $pdo->prepare('SELECT remember_token IS NOT NULL FROM administrators WHERE id = ?');
    $st->execute([$adminId]);
    return (bool)$st->fetchColumn();
}

function acc_last_event(PDO $pdo, int $adminId, string $action): ?string
{
    $st = $pdo->prepare('SELECT created_at FROM admin_audit_log WHERE admin_id = ? AND action = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$adminId, $action]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

function acc_browser(string $ua): string
{
    $os = preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iOS/i', $ua) ? 'iOS' : (preg_match('/Mac OS/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Unknown OS'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    return $br . ' on ' . $os;
}

/** Account audit entries plus this admin's configuration changes, newest first. */
function acc_audit(PDO $pdo, int $adminId, int $limit = 60): array
{
    $rows = [];
    $st = $pdo->prepare('SELECT action, detail, ip_address, created_at FROM admin_audit_log WHERE admin_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([$adminId]);
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['when' => $r['created_at'], 'what' => ucwords(str_replace('_', ' ', (string)$r['action'])), 'detail' => (string)$r['detail'], 'ip' => (string)$r['ip_address']];
    }
    try {
        $st = $pdo->prepare('SELECT setting_name, old_value, new_value, changed_at FROM configuration_logs WHERE admin_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$adminId]);
        foreach ($st->fetchAll() as $r) {
            $rows[] = ['when' => $r['changed_at'], 'what' => 'Configuration · ' . $r['setting_name'], 'detail' => trim((string)$r['old_value'] . ' → ' . (string)$r['new_value']), 'ip' => ''];
        }
    } catch (Throwable $e) {
    }
    usort($rows, static fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
    return array_slice($rows, 0, $limit);
}

function acc_when(?string $ts): string
{
    return $ts ? date('M j, Y · g:i A', strtotime($ts)) : '—';
}