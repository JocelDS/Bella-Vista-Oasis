<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer-session.php';
require_once __DIR__ . '/../includes/loyalty-data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cu_redirect('/loyalty-rewards.php');
if (!cu_logged_in()) {
    cu_flash('error', 'Please sign in to redeem rewards.');
    cu_redirect('/login.php');
}
if (!csrf_verify()) {
    cu_flash('error', 'Your session expired. Please try again.');
    cu_redirect('/loyalty-rewards.php#rewards');
}

$cid = (int)$_SESSION['customer_id'];
if (cu_rate_count('redeem', (string)$cid, 60) >= 15) {
    cu_flash('error', 'Too many redemption attempts. Please try again later.');
    cu_redirect('/loyalty-rewards.php#rewards');
}
cu_rate_hit('redeem', (string)$cid);

$result = cl_redeem(db(), $cid, (int)($_POST['reward_id'] ?? 0), (string)($_POST['request_token'] ?? ''));
cu_flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
cu_redirect('/loyalty-rewards.php#' . ($result['ok'] ? 'history' : 'rewards'));