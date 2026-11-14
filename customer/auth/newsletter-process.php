<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';

$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
function nl_reply(bool $ok, string $msg, bool $json): never
{
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $msg]);
        exit;
    }
    cu_flash($ok ? 'success' : 'error', $msg);
    cu_redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/login.php');
if (!csrf_verify()) nl_reply(false, 'Your session expired. Please refresh and try again.', $wantsJson);

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) nl_reply(false, 'Enter a valid email address.', $wantsJson);
if (cu_rate_count('newsletter', '*', 60) >= 10) nl_reply(false, 'Please try again a little later.', $wantsJson);
cu_rate_hit('newsletter', '*');

db()->prepare('INSERT IGNORE INTO newsletter_subscribers (email, created_at) VALUES (?, ?)')->execute([$email, now()]);
nl_reply(true, 'Thank you. You are on the Wellness Gazette list.', $wantsJson);
