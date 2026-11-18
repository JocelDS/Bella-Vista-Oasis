<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-auth-check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/dashboard.php');

const PROFILE_PHOTO_DIR = __DIR__ . '/../../Admin/assets/uploads/customers';
const PROFILE_PHOTO_MAX = 2097152; // 2 MB

/** Validates an uploaded photo. Returns [error|null, ['tmp'=>..,'ext'=>..]|null]. */
function profile_photo_check(): array
{
    $f = $_FILES['profile_photo'] ?? null;
    if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    $err = (int)$f['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['The photo must be 2 MB or smaller.', null];
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) return ['The photo could not be uploaded. Please try again.', null];
    if ((int)$f['size'] > PROFILE_PHOTO_MAX) return ['The photo must be 2 MB or smaller.', null];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize((string)$f['tmp_name']) === false) return ['Photo must be a JPG, PNG or WebP image.', null];
    return [null, ['tmp' => (string)$f['tmp_name'], 'ext' => $ext]];
}

function profile_photo_store(array $file): ?string
{
    if (!is_dir(PROFILE_PHOTO_DIR) && !@mkdir(PROFILE_PHOTO_DIR, 0755, true) && !is_dir(PROFILE_PHOTO_DIR)) return null;
    $ht = PROFILE_PHOTO_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "php_flag engine off\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php5\n");
    }
    $name = bin2hex(random_bytes(14)) . '.' . $file['ext'];
    return @move_uploaded_file($file['tmp'], PROFILE_PHOTO_DIR . '/' . $name) ? $name : null;
}

function profile_photo_drop(?string $name): void
{
    $name = $name ? basename($name) : '';
    if ($name !== '' && is_file(PROFILE_PHOTO_DIR . '/' . $name)) @unlink(PROFILE_PHOTO_DIR . '/' . $name);
}

function profile_done(bool $ok, string $message): never
{
    cu_flash($ok ? 'success' : 'error', $message);
    cu_redirect('/dashboard.php');
}

if (!csrf_verify()) profile_done(false, 'Your session expired. Please try again.');

$cid = (int)$currentCustomer['id'];
if (cu_rate_count('profile', (string)$cid, 60) >= 20) profile_done(false, 'Too many profile updates. Please try again later.');
cu_rate_hit('profile', (string)$cid);

$name    = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['full_name'] ?? '')));
$email   = strtolower(trim((string)($_POST['email'] ?? '')));
$phone   = cu_normalize_phone((string)($_POST['phone'] ?? ''));
$dob     = trim((string)($_POST['date_of_birth'] ?? ''));
$gender  = trim((string)($_POST['gender'] ?? ''));
$address = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['address'] ?? '')));

if (mb_strlen($name) < 2 || mb_strlen($name) > 150) profile_done(false, 'Enter your full name (2 to 150 characters).');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) profile_done(false, 'Enter a valid email address.');
if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) profile_done(false, 'Enter a valid phone number.');

$dobValue = null;
if ($dob !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$d || $d->format('Y-m-d') !== $dob) profile_done(false, 'Enter a valid date of birth.');
    if ($dob > date('Y-m-d') || $dob < date('Y-m-d', strtotime('-120 years'))) profile_done(false, 'Enter a valid date of birth.');
    $dobValue = $dob;
}

$genders = ['Female', 'Male', 'Non-binary', 'Prefer not to say'];
if ($gender !== '' && !in_array($gender, $genders, true)) profile_done(false, 'Choose a valid gender option.');
if (mb_strlen($address) > 255) profile_done(false, 'Address must be 255 characters or fewer.');

[$photoErr, $photoFile] = profile_photo_check();
if ($photoErr !== null) profile_done(false, $photoErr);
$removePhoto = ($_POST['remove_photo'] ?? '') === '1';

$pdo = db();
$oldPhoto = (string)($currentCustomer['profile_photo'] ?? '');
$newPhoto = null;
try {
    $st = $pdo->prepare('SELECT id FROM customers WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
    $st->execute([$email, $cid]);
    if ($st->fetch()) profile_done(false, 'That email address is already used by another account.');

    $photoValue = $oldPhoto !== '' ? $oldPhoto : null;
    if ($photoFile !== null) {
        $newPhoto = profile_photo_store($photoFile);
        if ($newPhoto === null) profile_done(false, 'We could not save your photo. Please try again.');
        $photoValue = $newPhoto;
    } elseif ($removePhoto) {
        $photoValue = null;
    }

    $pdo->prepare('UPDATE customers SET full_name = ?, email = ?, phone = ?, date_of_birth = ?, gender = ?, address = ?, profile_photo = ?, updated_at = ? WHERE id = ?')
        ->execute([$name, $email, $phone, $dobValue, $gender !== '' ? $gender : null, $address !== '' ? $address : null, $photoValue, now(), $cid]);
} catch (Throwable $e) {
    if ($newPhoto !== null) profile_photo_drop($newPhoto);
    error_log('[BVO customer profile] ' . $e->getMessage());
    profile_done(false, 'We could not update your profile right now. Please try again.');
}
if ($oldPhoto !== '' && ($newPhoto !== null || $removePhoto)) profile_photo_drop($oldPhoto);

$_SESSION['customer_name'] = $name;
profile_done(true, 'Your profile was updated.');