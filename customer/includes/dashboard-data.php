<?php
declare(strict_types=1);
/*
 * My Sanctuary dashboard data layer. Reservation rules (cancellation policy, late
 * reschedule, slot validation) come from the Admin booking engine so both portals agree.
 */
require_once __DIR__ . '/../../Admin/includes/booking-rules.php';
require_once __DIR__ . '/loyalty-data.php';

/* The Admin booking rules format amounts with peso(), which lives in the admin dashboard file. */
if (!function_exists('peso')) {
    function peso($value, ?int $decimals = null): string
    {
        $n = (float)$value;
        if ($decimals === null) {
            $decimals = abs($n - round($n)) < 0.005 ? 0 : 2;
        }
        return '₱' . number_format($n, $decimals);
    }
}

const CD_STATUS = ['completed' => 'Completed', 'confirmed' => 'Confirmed', 'pending' => 'Pending', 'cancelled' => 'Cancelled', 'no_show' => 'No Show'];
const CD_PAYMENT = ['paid' => 'Paid', 'deposit' => 'Deposit Paid', 'pending' => 'Payment Pending', 'refunded' => 'Refunded'];

function cd_code(int $id): string { return 'BVO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }
function cd_money(float $n): string { return '₱' . (floor($n) == $n ? number_format($n) : number_format($n, 2)); }
function cd_date(string $d): string { return date('D, M j, Y', strtotime($d)); }
function cd_time(string $t): string { return date('g:i A', strtotime($t)); }
function cd_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($a . $b);
}

const CD_SELECT = "SELECT a.id, a.service_id, a.room_id, a.therapist_id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status,
        a.total_amount, a.guest_count, a.downpayment_amount, a.cancelled_at, a.cancellation_forfeited,
        s.service_name, s.description AS service_description, s.room_code, s.duration,
        r.room_name, t.full_name AS therapist_name,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.appointment_id = a.id AND p.payment_status IN ('paid', 'deposit')) AS paid
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN rooms r ON r.id = a.room_id
        LEFT JOIN therapists t ON t.id = a.therapist_id";

function cd_is_future(array $a): bool
{
    return strtotime($a['appointment_date'] . ' ' . $a['end_time']) > time();
}

function cd_can_manage(array $a): bool
{
    return in_array($a['appointment_status'], ['pending', 'confirmed'], true) && strtotime($a['appointment_date'] . ' ' . $a['start_time']) > time();
}

function cd_upcoming(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare(CD_SELECT . " WHERE a.customer_id = ? AND a.appointment_status IN ('pending', 'confirmed') ORDER BY a.appointment_date, a.start_time");
    $st->execute([$cid]);
    return array_values(array_filter($st->fetchAll(), 'cd_is_future'));
}

function cd_history(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare(CD_SELECT . " WHERE a.customer_id = ? ORDER BY a.appointment_date DESC, a.start_time DESC");
    $st->execute([$cid]);
    return array_values(array_filter($st->fetchAll(), fn($a) => !(in_array($a['appointment_status'], ['pending', 'confirmed'], true) && cd_is_future($a))));
}

function cd_find(PDO $pdo, int $id, int $cid, bool $lock = false): ?array
{
    $st = $pdo->prepare(CD_SELECT . ' WHERE a.id = ? AND a.customer_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $cid]);
    return $st->fetch() ?: null;
}

function cd_balance(array $a): float
{
    if (!in_array($a['appointment_status'], ['pending', 'confirmed'], true)) return 0.0;
    if ($a['payment_status'] === 'paid') return 0.0;
    return max(0.0, (float)$a['total_amount'] - (float)$a['paid']);
}

function cd_view(array $a): array
{
    $canManage = cd_can_manage($a);
    return [
        'id' => (int)$a['id'], 'code' => cd_code((int)$a['id']), 'service' => $a['service_name'], 'description' => (string)$a['service_description'],
        'date' => cd_date($a['appointment_date']), 'dateIso' => $a['appointment_date'],
        'time' => cd_time($a['start_time']) . ' – ' . cd_time($a['end_time']),
        'therapist' => $a['therapist_name'] ?: 'To be assigned', 'room' => $a['room_name'] ?: 'To be assigned',
        'status' => CD_STATUS[$a['appointment_status']] ?? ucfirst($a['appointment_status']), 'statusKey' => $a['appointment_status'],
        'payment' => CD_PAYMENT[$a['payment_status']] ?? ucfirst((string)$a['payment_status']),
        'total' => cd_money((float)$a['total_amount']), 'paid' => cd_money((float)$a['paid']), 'balance' => cd_money(cd_balance($a)),
        'guests' => (int)$a['guest_count'], 'canManage' => $canManage,
    ];
}

function cd_featured_service(PDO $pdo, int $cid): ?array
{
    $st = $pdo->prepare('SELECT s.id, s.service_name, s.room_code, s.description, s.duration, s.price, (SELECT COUNT(*) FROM appointments a WHERE a.customer_id = ? AND a.service_id = s.id) AS tried FROM services s ORDER BY tried ASC, s.price DESC, s.id LIMIT 1');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

function cd_progress(PDO $pdo, ?array $member, int $cid): array
{
    if (!$member || !$member['tier_id']) return ['text' => 'Not yet enrolled in a membership', 'percent' => 0, 'show' => false];
    $tiers = array_values(cl_tiers($pdo));
    $cur = null; $next = null;
    foreach ($tiers as $t) {
        if ((int)$t['id'] === (int)$member['tier_id']) $cur = $t;
    }
    foreach ($tiers as $t) {
        if ($cur && (int)$t['sort_order'] > (int)$cur['sort_order'] && (float)$t['minimum_spending'] > 0) { $next = $t; break; }
    }
    if (!$next) return ['text' => 'You have reached our highest tier', 'percent' => 100, 'show' => true];
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE customer_id = ? AND payment_status = 'paid'");
    $st->execute([$cid]);
    $spent = (float)$st->fetchColumn();
    $need = (float)$next['minimum_spending'];
    $pct = max(3, min(100, (int)floor($spent / $need * 100)));
    $left = max(0.0, $need - $spent);
    return ['text' => ($left > 0 ? cd_money($left) . ' more in treatments to reach ' . $next['tier_name'] : 'Ready for ' . $next['tier_name'] . '. Ask the concierge'), 'percent' => $pct, 'show' => true];
}