<?php
declare(strict_types=1);
/*
 * Customer-side loyalty data and redemption. Uses the same tables and rules as the
 * Admin membership module (memberships, membership_tiers, rewards, reward_tiers,
 * reward_redemptions, loyalty_transactions) so both portals always agree.
 */

const CL_TYPE_LABELS = [
    'earned' => 'Points Earned', 'redeemed' => 'Reward Redemption', 'bonus' => 'Bonus Points',
    'adjustment' => 'Points Adjustment', 'expired' => 'Points Expired',
];

function cl_lines($raw): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $l) {
        $l = trim($l);
        if ($l !== '') $out[] = $l;
    }
    return $out;
}

function cl_balance(PDO $pdo, int $cid): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed'");
    $st->execute([$cid]);
    return (int)$st->fetchColumn();
}

function cl_totals(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS earned,
                                COALESCE(SUM(CASE WHEN transaction_type = 'redeemed' THEN -points ELSE 0 END), 0) AS redeemed,
                                COALESCE(SUM(points), 0) AS balance
                         FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed'");
    $st->execute([$cid]);
    $r = $st->fetch();
    return ['earned' => (int)$r['earned'], 'redeemed' => (int)$r['redeemed'], 'balance' => (int)$r['balance']];
}

function cl_tiers(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT id, tier_name, sort_order, discount_percentage, points_multiplier, tier_color, benefits, privileges FROM membership_tiers WHERE status = 'active' ORDER BY sort_order, id") as $t) {
        $out[(int)$t['id']] = $t;
    }
    return $out;
}

function cl_member(PDO $pdo, int $cid): ?array
{
    $st = $pdo->prepare('SELECT m.id, m.membership_type, m.tier_id, m.start_date, m.expiry_date, m.membership_status FROM memberships m WHERE m.customer_id = ? ORDER BY m.id DESC LIMIT 1');
    $st->execute([$cid]);
    $m = $st->fetch();
    if (!$m) return null;
    $status = strtolower((string)($m['membership_status'] ?: 'active'));
    if ($status === 'active' && $m['expiry_date'] && $m['expiry_date'] < date('Y-m-d')) $status = 'expired';
    $m['status'] = $status;
    $m['tier_id'] = $m['tier_id'] !== null ? (int)$m['tier_id'] : null;
    return $m;
}

function cl_rewards(PDO $pdo): array
{
    $tierSet = [];
    foreach ($pdo->query('SELECT reward_id, tier_id FROM reward_tiers') as $x) $tierSet[(int)$x['reward_id']][] = (int)$x['tier_id'];
    $out = [];
    foreach ($pdo->query("SELECT * FROM rewards WHERE status = 'active' ORDER BY required_points, id") as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'name' => (string)$r['reward_name'], 'description' => (string)($r['description'] ?? ''),
            'image' => $r['reward_image'] ?: null, 'category' => (string)$r['reward_category'], 'points' => (int)$r['required_points'],
            'quantity' => $r['available_quantity'] === null ? null : (int)$r['available_quantity'],
            'max_member' => $r['maximum_per_member'] === null ? null : (int)$r['maximum_per_member'],
            'from' => $r['valid_from'] ?: null, 'until' => $r['valid_until'] ?: null,
            'min_tier' => $r['minimum_tier_id'] === null ? null : (int)$r['minimum_tier_id'],
            'tiers' => $tierSet[(int)$r['id']] ?? [], 'status' => (string)$r['status'],
        ];
    }
    return $out;
}

function cl_used_counts(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT reward_id, COUNT(*) AS n FROM reward_redemptions WHERE customer_id = ? AND redemption_status = 'completed' GROUP BY reward_id");
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['reward_id']] = (int)$r['n'];
    return $out;
}

/** Returns null when the reward can be redeemed, otherwise the reason it cannot. */
function cl_block(array $rw, ?array $member, int $balance, array $tiers, int $used): ?string
{
    $today = date('Y-m-d');
    if ($rw['status'] !== 'active') return 'Reward is unavailable';
    if ($rw['from'] && $rw['from'] > $today) return 'Not yet available';
    if ($rw['until'] && $rw['until'] < $today) return 'Reward has expired';
    if ($rw['quantity'] !== null && $rw['quantity'] <= 0) return 'Out of stock';
    if (!$member) return 'Membership required';
    if ($member['status'] !== 'active') return 'Membership ' . $member['status'];
    $tid = (int)($member['tier_id'] ?? 0);
    if ($rw['tiers'] && !in_array($tid, $rw['tiers'], true)) return 'Not available for your tier';
    if ($rw['min_tier'] !== null) {
        $need = (int)($tiers[$rw['min_tier']]['sort_order'] ?? 0);
        $have = isset($tiers[$tid]) ? (int)$tiers[$tid]['sort_order'] : -1;
        if ($have < $need) return 'Requires ' . ($tiers[$rw['min_tier']]['tier_name'] ?? 'a higher tier') . ' or above';
    }
    if ($rw['max_member'] !== null && $used >= $rw['max_member']) return 'Redemption limit reached';
    if ($balance < $rw['points']) return 'Needs ' . number_format($rw['points'] - $balance) . ' more points';
    return null;
}

/** Newest-first ledger rows with the running balance after each one. */
function cl_history(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT transaction_type, points, reference_type, description, created_at FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed' ORDER BY created_at ASC, id ASC");
    $st->execute([$cid]);
    $run = 0;
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $run += (int)$r['points'];
        $r['balance'] = $run;
        $rows[] = $r;
    }
    return array_reverse($rows);
}

function cl_tx_label(array $r): string
{
    if ($r['transaction_type'] === 'earned' && $r['reference_type'] === 'payment') return 'Appointment Completed';
    return CL_TYPE_LABELS[$r['transaction_type']] ?? ucfirst((string)$r['transaction_type']);
}

function cl_redeem(PDO $pdo, int $cid, int $rewardId, string $token): array
{
    if ($rewardId < 1) return ['ok' => false, 'error' => 'Please choose a reward.'];
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) return ['ok' => false, 'error' => 'This request is no longer valid. Please reload the page and try again.'];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND customer_status = 'active' FOR UPDATE");
        $lock->execute([$cid]);
        if (!$lock->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Your account could not be verified.'];
        }
        $dupe = $pdo->prepare('SELECT redemption_reference FROM reward_redemptions WHERE request_token = ?');
        $dupe->execute([$token]);
        if ($ref = $dupe->fetchColumn()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'This redemption was already processed (' . $ref . ').'];
        }
        $member = cl_member($pdo, $cid);
        $tiers = cl_tiers($pdo);
        $rs = $pdo->prepare("SELECT * FROM rewards WHERE id = ? FOR UPDATE");
        $rs->execute([$rewardId]);
        if (!$rs->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That reward could not be found.'];
        }
        $rw = null;
        foreach (cl_rewards($pdo) as $x) if ($x['id'] === $rewardId) $rw = $x;
        if (!$rw) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'That reward is no longer available.'];
        }
        $used = cl_used_counts($pdo, $cid)[$rewardId] ?? 0;
        $balance = cl_balance($pdo, $cid);
        $block = cl_block($rw, $member, $balance, $tiers, $used);
        if ($block !== null) {
            $pdo->rollBack();
            if (str_starts_with($block, 'Needs ')) {
                return ['ok' => false, 'error' => 'Not enough tranquility points. Continue enjoying your sanctuary experiences to earn more rewards.'];
            }
            return ['ok' => false, 'error' => $block . '.'];
        }
        $ref = 'RDM-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO reward_redemptions (customer_id, reward_id, points_used, redemption_reference, request_token, redemption_status, processed_by) VALUES (?, ?, ?, ?, ?, 'completed', NULL)")
            ->execute([$cid, $rewardId, $rw['points'], $ref, $token]);
        $redId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO loyalty_transactions (transaction_code, customer_id, transaction_type, points, reference_type, reference_id, description, notes, status, processed_by) VALUES (?, ?, 'redeemed', ?, 'redemption', ?, ?, NULL, 'completed', NULL)")
            ->execute(['LT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), $cid, -$rw['points'], $redId, mb_substr('Redeemed · ' . $rw['name'], 0, 250)]);
        $txId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE reward_redemptions SET transaction_id = ? WHERE id = ?')->execute([$txId, $redId]);
        $pdo->prepare('UPDATE rewards SET redeemed_count = redeemed_count + 1' . ($rw['quantity'] !== null ? ', available_quantity = available_quantity - 1' : '') . ' WHERE id = ?')->execute([$rewardId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'We could not complete the redemption. Please try again.'];
    }
    return ['ok' => true, 'message' => $rw['name'] . ' redeemed. Reference ' . $ref . '. Your remaining balance is ' . number_format($balance - $rw['points']) . ' points.'];
}