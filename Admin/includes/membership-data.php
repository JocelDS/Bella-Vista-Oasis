<?php
declare(strict_types=1);

require_once __DIR__ . '/appointments-data.php';

const ML_IMG_MAX = 3145728;
const ML_CATEGORIES = ['Add-on', 'Discount', 'Upgrade', 'Experience', 'Product'];
const ML_TYPES = ['earned' => 'Points Earned', 'redeemed' => 'Points Redeemed', 'bonus' => 'Bonus Points', 'adjustment' => 'Manual Adjustment', 'expired' => 'Points Expired'];
const ML_REASONS = ['Bonus points', 'Promotion', 'Service recovery', 'Correction', 'Other'];

function ml_fail(string $m, array $extra = []): array
{
    return array_merge(['ok' => false, 'error' => $m], $extra);
}

function ml_ready(PDO $pdo): bool
{
    foreach (['membership_tiers', 'loyalty_settings', 'loyalty_transactions', 'rewards', 'reward_tiers', 'reward_redemptions', 'membership_history'] as $t) {
        try {
            $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        } catch (Throwable $e) {
            return false;
        }
    }
    try {
        $pdo->query('SELECT tier_id FROM memberships LIMIT 1');
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

function ml_settings(PDO $pdo): array
{
    $s = ['peso_per_point' => 100, 'membership_term_months' => 12, 'auto_award' => 1];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM loyalty_settings')->fetchAll() as $r) {
        if (isset($s[$r['setting_key']])) {
            $s[$r['setting_key']] = (int)$r['setting_value'];
        }
    }
    if ($s['peso_per_point'] < 1) {
        $s['peso_per_point'] = 100;
    }
    if ($s['membership_term_months'] < 1) {
        $s['membership_term_months'] = 12;
    }
    return $s;
}

function ml_save_settings(PDO $pdo, array $in): array
{
    $pp   = filter_var($in['peso_per_point'] ?? '', FILTER_VALIDATE_INT);
    $term = filter_var($in['membership_term_months'] ?? '', FILTER_VALIDATE_INT);
    if ($pp === false || $pp < 1 || $pp > 100000) {
        return ml_fail('Peso per point must be a whole number from 1 to 100,000.');
    }
    if ($term === false || $term < 1 || $term > 120) {
        return ml_fail('Membership term must be 1 to 120 months.');
    }
    $auto = !empty($in['auto_award']) ? 1 : 0;
    $up = $pdo->prepare('UPDATE loyalty_settings SET setting_value = ? WHERE setting_key = ?');
    $ins = $pdo->prepare('INSERT INTO loyalty_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (['peso_per_point' => $pp, 'membership_term_months' => $term, 'auto_award' => $auto] as $k => $v) {
        $up->execute([(string)$v, $k]);
        if ($up->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT 1 FROM loyalty_settings WHERE setting_key = ?');
            $chk->execute([$k]);
            if (!$chk->fetchColumn()) {
                $ins->execute([$k, (string)$v]);
            }
        }
    }
    return ['ok' => true, 'message' => 'Membership rules saved.'];
}

function ml_lines($raw, int $max = 12): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n|\\\\n/', (string)$raw) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = mb_substr($l, 0, 90);
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function ml_valid_date($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function ml_add_months(string $date, int $months): string
{
    $d = new DateTime($date);
    $day = (int)$d->format('j');
    $d->modify('first day of this month')->modify('+' . $months . ' months');
    $last = (int)$d->format('t');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, $last));
    return $d->format('Y-m-d');
}

function ml_tier_row(array $t): array
{
    return [
        'id'         => (int)$t['id'],
        'name'       => (string)$t['tier_name'],
        'description' => (string)($t['description'] ?? ''),
        'min_spending' => (float)$t['minimum_spending'],
        'min_points' => (int)$t['minimum_points'],
        'discount'   => (float)$t['discount_percentage'],
        'multiplier' => (float)$t['points_multiplier'],
        'color'      => (string)$t['tier_color'],
        'benefits'   => ml_lines($t['benefits'] ?? ''),
        'privileges' => ml_lines($t['privileges'] ?? ''),
        'order'      => (int)$t['sort_order'],
        'status'     => (string)$t['status'],
    ];
}

function ml_tiers_raw(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT * FROM membership_tiers ORDER BY sort_order, id')->fetchAll() as $t) {
        $out[(int)$t['id']] = ml_tier_row($t);
    }
    return $out;
}

function ml_members_raw(PDO $pdo, array $tiers): array
{
    $sql = "SELECT m.id AS mid, m.customer_id, m.membership_type, m.tier_id, m.start_date, m.expiry_date, m.membership_status,
                   c.full_name, c.email, c.phone,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.customer_id = m.customer_id AND p.payment_status = 'paid') AS spent,
                   (SELECT COALESCE(SUM(l.points), 0) FROM loyalty_transactions l WHERE l.customer_id = m.customer_id AND l.status = 'completed') AS balance,
                   (SELECT COALESCE(SUM(l.points), 0) FROM loyalty_transactions l WHERE l.customer_id = m.customer_id AND l.status = 'completed' AND l.points > 0) AS lifetime
            FROM memberships m
            JOIN customers c ON c.id = m.customer_id
            WHERE m.id = (SELECT MAX(x.id) FROM memberships x WHERE x.customer_id = m.customer_id)
            ORDER BY c.full_name";
    $today = date('Y-m-d');
    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $tid = $r['tier_id'] !== null ? (int)$r['tier_id'] : 0;
        $tier = $tiers[$tid] ?? null;
        $st = strtolower((string)($r['membership_status'] ?? 'active'));
        $exp = $r['expiry_date'] ?: null;
        $derived = $st;
        if ($st === 'active' && $exp !== null && $exp < $today) {
            $derived = 'expired';
        }
        $out[] = [
            'customer_id' => (int)$r['customer_id'],
            'code'        => 'CUS-' . str_pad((string)$r['customer_id'], 4, '0', STR_PAD_LEFT),
            'name'        => (string)$r['full_name'],
            'email'       => (string)($r['email'] ?? ''),
            'phone'       => (string)($r['phone'] ?? ''),
            'tier_id'     => $tid ?: null,
            'tier'        => $tier ? $tier['name'] : (string)($r['membership_type'] ?: 'Unassigned'),
            'color'       => $tier ? $tier['color'] : '#85877F',
            'status'      => $derived,
            'start'       => $r['start_date'] ?: null,
            'expiry'      => $exp,
            'spent'       => round((float)$r['spent'], 2),
            'balance'     => (int)$r['balance'],
            'lifetime'    => (int)$r['lifetime'],
        ];
    }
    return $out;
}

function ml_range(array $p): array
{
    $from = ml_valid_date($p['from'] ?? '');
    $to   = ml_valid_date($p['to'] ?? '');
    if ($from && $to && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function ml_stats(PDO $pdo, array $members, ?string $from, ?string $to): array
{
    $w = ["status = 'completed'"];
    $a = [];
    if ($from) {
        $w[] = 'DATE(created_at) >= ?';
        $a[] = $from;
    }
    if ($to) {
        $w[] = 'DATE(created_at) <= ?';
        $a[] = $to;
    }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS issued,
                                COALESCE(SUM(CASE WHEN transaction_type = 'redeemed' THEN -points ELSE 0 END), 0) AS redeemed
                         FROM loyalty_transactions WHERE $where");
    $st->execute($a);
    $r = $st->fetch();
    $issued = (int)$r['issued'];
    $redeemed = (int)$r['redeemed'];
    $active = 0;
    foreach ($members as $m) {
        if ($m['status'] === 'active') {
            $active++;
        }
    }
    return [
        'total_members' => count($members),
        'active_members' => $active,
        'issued' => $issued,
        'redeemed' => $redeemed,
        'rate' => $issued > 0 ? round($redeemed / $issued * 100, 1) : 0.0,
        'outstanding' => array_sum(array_column($members, 'balance')),
    ];
}

function ml_reward_row(PDO $pdo, array $r, array $tierSet): array
{
    $tiers = $tierSet[(int)$r['id']] ?? [];
    return [
        'id'        => (int)$r['id'],
        'name'      => (string)$r['reward_name'],
        'description' => (string)($r['description'] ?? ''),
        'image'     => $r['reward_image'] ?: null,
        'category'  => (string)$r['reward_category'],
        'points'    => (int)$r['required_points'],
        'quantity'  => $r['available_quantity'] === null ? null : (int)$r['available_quantity'],
        'redeemed'  => (int)$r['redeemed_count'],
        'max_member' => $r['maximum_per_member'] === null ? null : (int)$r['maximum_per_member'],
        'from'      => $r['valid_from'] ?: null,
        'until'     => $r['valid_until'] ?: null,
        'min_tier'  => $r['minimum_tier_id'] === null ? null : (int)$r['minimum_tier_id'],
        'tiers'     => $tiers,
        'status'    => (string)$r['status'],
    ];
}

function ml_rewards(PDO $pdo): array
{
    $set = [];
    foreach ($pdo->query('SELECT reward_id, tier_id FROM reward_tiers')->fetchAll() as $x) {
        $set[(int)$x['reward_id']][] = (int)$x['tier_id'];
    }
    $out = [];
    foreach ($pdo->query("SELECT * FROM rewards ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, required_points, id")->fetchAll() as $r) {
        $out[] = ml_reward_row($pdo, $r, $set);
    }
    return $out;
}

function ml_reward_block(array $rw, ?array $member, array $tiers, int $usedByMember, ?string $today = null): ?string
{
    $today = $today ?? date('Y-m-d');
    if ($rw['status'] !== 'active') {
        return 'Reward is inactive';
    }
    if ($rw['from'] && $rw['from'] > $today) {
        return 'Not yet available';
    }
    if ($rw['until'] && $rw['until'] < $today) {
        return 'Reward has expired';
    }
    if ($rw['quantity'] !== null && $rw['quantity'] <= 0) {
        return 'Out of stock';
    }
    if ($member) {
        $tid = (int)($member['tier_id'] ?? 0);
        if ($rw['tiers'] && !in_array($tid, $rw['tiers'], true)) {
            return 'Not available for this tier';
        }
        if ($rw['min_tier'] !== null) {
            $need = $tiers[$rw['min_tier']]['order'] ?? 0;
            $have = $tiers[$tid]['order'] ?? -1;
            if ($have < $need) {
                return 'Requires ' . ($tiers[$rw['min_tier']]['name'] ?? 'a higher tier') . ' or above';
            }
        }
        if ($rw['max_member'] !== null && $usedByMember >= $rw['max_member']) {
            return 'Redemption limit reached';
        }
        if ($member['balance'] < $rw['points']) {
            return 'Needs ' . number_format($rw['points'] - $member['balance']) . ' more points';
        }
    }
    return null;
}

function ml_overview(PDO $pdo, array $p): array
{
    [$from, $to] = ml_range($p);
    $tiers   = ml_tiers_raw($pdo);
    $members = ml_members_raw($pdo, $tiers);
    $counts  = [];
    foreach ($members as $m) {
        if ($m['status'] === 'active' && $m['tier_id']) {
            $counts[$m['tier_id']] = ($counts[$m['tier_id']] ?? 0) + 1;
        }
    }
    $tl = [];
    foreach ($tiers as $t) {
        $t['members'] = $counts[$t['id']] ?? 0;
        $tl[] = $t;
    }
    return [
        'ok'       => true,
        'stats'    => ml_stats($pdo, $members, $from, $to),
        'tiers'    => $tl,
        'rewards'  => ml_rewards($pdo),
        'settings' => ml_settings($pdo),
    ];
}

function ml_members(PDO $pdo, array $p): array
{
    $tiers = ml_tiers_raw($pdo);
    $all = ml_members_raw($pdo, $tiers);
    $q = mb_strtolower(trim((string)($p['q'] ?? '')));
    $tier = (int)($p['tier'] ?? 0);
    $status = (string)($p['status'] ?? '');
    $rows = [];
    foreach ($all as $m) {
        if ($tier && $m['tier_id'] !== $tier) {
            continue;
        }
        if ($status !== '' && $m['status'] !== $status) {
            continue;
        }
        if ($q !== '' && mb_strpos(mb_strtolower($m['name'] . ' ' . $m['code'] . ' ' . $m['email']), $q) === false) {
            continue;
        }
        $rows[] = $m;
    }
    return ['ok' => true, 'members' => $rows, 'total' => count($rows)];
}

function ml_customers(PDO $pdo, string $q): array
{
    $q = trim($q);
    $sql = 'SELECT id, full_name, email FROM customers';
    $a = [];
    if ($q !== '') {
        $sql .= ' WHERE full_name LIKE ? OR email LIKE ?';
        $a = ['%' . $q . '%', '%' . $q . '%'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY full_name LIMIT 30');
    $st->execute($a);
    $tiers = ml_tiers_raw($pdo);
    $byC = [];
    foreach (ml_members_raw($pdo, $tiers) as $m) {
        $byC[$m['customer_id']] = $m;
    }
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $m = $byC[(int)$c['id']] ?? null;
        $out[] = [
            'id' => (int)$c['id'],
            'name' => (string)$c['full_name'],
            'email' => (string)($c['email'] ?? ''),
            'tier' => $m ? $m['tier'] : null,
            'tier_id' => $m ? $m['tier_id'] : null,
            'status' => $m ? $m['status'] : null,
            'balance' => $m ? $m['balance'] : 0,
        ];
    }
    return ['ok' => true, 'customers' => $out];
}

function ml_ledger_sql(array $p, array &$args): string
{
    $w = ['1 = 1'];
    $type = (string)($p['type'] ?? '');
    if ($type !== '' && isset(ML_TYPES[$type])) {
        $w[] = 'l.transaction_type = ?';
        $args[] = $type;
    }
    $status = (string)($p['status'] ?? '');
    if (in_array($status, ['completed', 'pending', 'cancelled', 'reversed'], true)) {
        $w[] = 'l.status = ?';
        $args[] = $status;
    }
    $tier = (int)($p['tier'] ?? 0);
    if ($tier) {
        $w[] = '(SELECT m.tier_id FROM memberships m WHERE m.customer_id = l.customer_id ORDER BY m.id DESC LIMIT 1) = ?';
        $args[] = $tier;
    }
    [$from, $to] = ml_range($p);
    if ($from) {
        $w[] = 'DATE(l.created_at) >= ?';
        $args[] = $from;
    }
    if ($to) {
        $w[] = 'DATE(l.created_at) <= ?';
        $args[] = $to;
    }
    $cust = (int)($p['customer'] ?? 0);
    if ($cust) {
        $w[] = 'l.customer_id = ?';
        $args[] = $cust;
    }
    $q = trim((string)($p['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(c.full_name LIKE ? OR l.transaction_code LIKE ? OR l.description LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    return implode(' AND ', $w);
}

function ml_tx_map(array $r): array
{
    $pts = (int)$r['points'];
    return [
        'id' => (int)$r['id'],
        'code' => (string)$r['transaction_code'],
        'customer_id' => (int)$r['customer_id'],
        'member' => (string)$r['full_name'],
        'tier' => (string)($r['tier_name'] ?? '') ?: '–',
        'type' => (string)$r['transaction_type'],
        'type_label' => ML_TYPES[$r['transaction_type']] ?? ucfirst((string)$r['transaction_type']),
        'earned' => $pts > 0 ? $pts : 0,
        'redeemed' => $pts < 0 ? -$pts : 0,
        'description' => (string)($r['description'] ?? ''),
        'date' => (string)$r['created_at'],
        'status' => (string)$r['status'],
    ];
}

function ml_ledger(PDO $pdo, array $p): array
{
    $args = [];
    $where = ml_ledger_sql($p, $args);
    $per = max(5, min(50, (int)($p['per'] ?? 10)));
    $page = max(1, (int)($p['page'] ?? 1));
    $dir = strtolower((string)($p['sort'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM loyalty_transactions l JOIN customers c ON c.id = l.customer_id WHERE $where");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $sql = "SELECT l.*, c.full_name,
                   (SELECT COALESCE(t.tier_name, m.membership_type) FROM memberships m LEFT JOIN membership_tiers t ON t.id = m.tier_id WHERE m.customer_id = l.customer_id ORDER BY m.id DESC LIMIT 1) AS tier_name
            FROM loyalty_transactions l JOIN customers c ON c.id = l.customer_id
            WHERE $where ORDER BY l.created_at $dir, l.id $dir LIMIT $per OFFSET " . (($page - 1) * $per);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = array_map('ml_tx_map', $st->fetchAll());
    return ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
}

function ml_tx_detail(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT l.*, c.full_name, c.email, a.full_name AS admin_name,
                   (SELECT COALESCE(t.tier_name, m.membership_type) FROM memberships m LEFT JOIN membership_tiers t ON t.id = m.tier_id WHERE m.customer_id = l.customer_id ORDER BY m.id DESC LIMIT 1) AS tier_name
            FROM loyalty_transactions l JOIN customers c ON c.id = l.customer_id LEFT JOIN administrators a ON a.id = l.processed_by WHERE l.id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return ml_fail('Transaction not found.');
    }
    $d = ml_tx_map($r);
    $d['notes'] = (string)($r['notes'] ?? '');
    $d['admin'] = (string)($r['admin_name'] ?? '') ?: 'System';
    $d['email'] = (string)($r['email'] ?? '');
    $d['reference'] = null;
    if ($r['reference_type'] === 'payment' && $r['reference_id']) {
        $q = $pdo->prepare('SELECT p.amount, p.payment_method, p.payment_date, p.appointment_id FROM payments p WHERE p.id = ?');
        $q->execute([(int)$r['reference_id']]);
        if ($x = $q->fetch()) {
            $d['reference'] = ['label' => 'Payment', 'text' => 'Appointment ' . appt_code((int)$x['appointment_id']) . ' · ' . peso($x['amount']) . ' · ' . (string)$x['payment_method']];
        }
    } elseif ($r['reference_type'] === 'redemption' && $r['reference_id']) {
        $q = $pdo->prepare('SELECT d.redemption_reference, d.redemption_status, w.reward_name FROM reward_redemptions d JOIN rewards w ON w.id = d.reward_id WHERE d.id = ?');
        $q->execute([(int)$r['reference_id']]);
        if ($x = $q->fetch()) {
            $d['reference'] = ['label' => 'Redemption', 'text' => $x['redemption_reference'] . ' · ' . $x['reward_name'] . ' · ' . $x['redemption_status']];
        }
    }
    $d['reversible'] = $r['status'] === 'completed' && $r['transaction_type'] !== 'expired';
    return ['ok' => true, 'tx' => $d];
}

function ml_member(PDO $pdo, int $cid): ?array
{
    $tiers = ml_tiers_raw($pdo);
    foreach (ml_members_raw($pdo, $tiers) as $m) {
        if ($m['customer_id'] === $cid) {
            return $m;
        }
    }
    return null;
}

function ml_customer(PDO $pdo, int $cid): ?array
{
    $st = $pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

function ml_member_detail(PDO $pdo, int $cid): array
{
    $c = ml_customer($pdo, $cid);
    if (!$c) {
        return ml_fail('Customer not found.');
    }
    $tiers = ml_tiers_raw($pdo);
    $m = ml_member($pdo, $cid);
    $sum = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS earned,
        COALESCE(SUM(CASE WHEN transaction_type = 'redeemed' THEN -points ELSE 0 END), 0) AS redeemed,
        COALESCE(SUM(CASE WHEN transaction_type = 'adjustment' THEN points ELSE 0 END), 0) AS adjust,
        COALESCE(SUM(points), 0) AS balance
        FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed'");
    $sum->execute([$cid]);
    $s = $sum->fetch();
    $sp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE customer_id = ? AND payment_status = 'paid'");
    $sp->execute([$cid]);
    $spent = round((float)$sp->fetchColumn(), 2);
    $led = $pdo->prepare("SELECT l.*, ? AS full_name, NULL AS tier_name FROM loyalty_transactions l WHERE l.customer_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT 100");
    $led->execute([$c['full_name'], $cid]);
    $ledger = array_map('ml_tx_map', $led->fetchAll());
    $rd = $pdo->prepare("SELECT d.redemption_reference, d.points_used, d.redemption_status, d.redeemed_at, w.reward_name FROM reward_redemptions d JOIN rewards w ON w.id = d.reward_id WHERE d.customer_id = ? ORDER BY d.id DESC LIMIT 50");
    $rd->execute([$cid]);
    $hs = $pdo->prepare("SELECT h.*, a.full_name AS admin_name FROM membership_history h LEFT JOIN administrators a ON a.id = h.changed_by WHERE h.customer_id = ? ORDER BY h.id DESC LIMIT 50");
    $hs->execute([$cid]);
    $hist = [];
    foreach ($hs->fetchAll() as $h) {
        $hist[] = [
            'type' => (string)$h['change_type'],
            'from' => $h['previous_tier_id'] ? ($tiers[(int)$h['previous_tier_id']]['name'] ?? '–') : '–',
            'to' => $h['new_tier_id'] ? ($tiers[(int)$h['new_tier_id']]['name'] ?? '–') : '–',
            'by' => (string)($h['admin_name'] ?? '') ?: 'System',
            'reason' => (string)($h['change_reason'] ?? ''),
            'date' => (string)$h['created_at'],
        ];
    }
    $next = null;
    if ($m && $m['tier_id']) {
        $cur = $tiers[$m['tier_id']]['order'] ?? 0;
        foreach ($tiers as $t) {
            if ($t['status'] === 'active' && $t['order'] > $cur) {
                $next = ['name' => $t['name'], 'min_spending' => $t['min_spending'], 'min_points' => $t['min_points'], 'spend_gap' => max(0, $t['min_spending'] - $spent), 'points_gap' => max(0, $t['min_points'] - (int)$s['earned'])];
                break;
            }
        }
    }
    return [
        'ok' => true,
        'customer' => ['id' => (int)$c['id'], 'name' => (string)$c['full_name'], 'email' => (string)($c['email'] ?? ''), 'phone' => (string)($c['phone'] ?? '')],
        'member' => $m,
        'totals' => ['earned' => (int)$s['earned'], 'redeemed' => (int)$s['redeemed'], 'adjust' => (int)$s['adjust'], 'balance' => (int)$s['balance'], 'spent' => $spent],
        'ledger' => $ledger,
        'redemptions' => $rd->fetchAll(),
        'history' => $hist,
        'next' => $next,
        'tiers' => array_values($tiers),
    ];
}

function ml_eligible_rewards(PDO $pdo, int $cid): array
{
    $m = ml_member($pdo, $cid);
    if (!$m) {
        return ml_fail('This customer has no membership.');
    }
    $tiers = ml_tiers_raw($pdo);
    $used = [];
    $st = $pdo->prepare("SELECT reward_id, COUNT(*) AS n FROM reward_redemptions WHERE customer_id = ? AND redemption_status = 'completed' GROUP BY reward_id");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $used[(int)$r['reward_id']] = (int)$r['n'];
    }
    $out = [];
    foreach (ml_rewards($pdo) as $rw) {
        if ($rw['status'] !== 'active') {
            continue;
        }
        $rw['block'] = ml_reward_block($rw, $m, $tiers, $used[$rw['id']] ?? 0);
        $out[] = $rw;
    }
    return ['ok' => true, 'member' => $m, 'rewards' => $out];
}

function ml_tx_code(): string
{
    return 'LT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function ml_insert_tx(PDO $pdo, int $cid, string $type, int $points, ?string $refType, ?int $refId, string $desc, ?string $notes, ?int $admin, ?string $createdAt = null): int
{
    $cols = 'transaction_code, customer_id, transaction_type, points, reference_type, reference_id, description, notes, status, processed_by';
    $vals = [ml_tx_code(), $cid, $type, $points, $refType, $refId, mb_substr($desc, 0, 250), $notes, 'completed', $admin];
    $ph = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
    if ($createdAt !== null) {
        $cols .= ', created_at';
        $vals[] = $createdAt;
        $ph .= ', ?';
    }
    $pdo->prepare("INSERT INTO loyalty_transactions ($cols) VALUES ($ph)")->execute($vals);
    return (int)$pdo->lastInsertId();
}

function ml_lock_customer(PDO $pdo, int $cid): bool
{
    $st = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
    $st->execute([$cid]);
    return (bool)$st->fetchColumn();
}

function ml_balance(PDO $pdo, int $cid): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM loyalty_transactions WHERE customer_id = ? AND status = 'completed'");
    $st->execute([$cid]);
    return (int)$st->fetchColumn();
}

function ml_sync_earnings(PDO $pdo, ?int $admin = null): array
{
    $set = ml_settings($pdo);
    $tiers = ml_tiers_raw($pdo);
    $members = [];
    foreach (ml_members_raw($pdo, $tiers) as $m) {
        if ($m['status'] === 'active') {
            $members[$m['customer_id']] = $m;
        }
    }
    if (!$members) {
        return ['ok' => true, 'awarded' => 0, 'points' => 0];
    }
    $sql = "SELECT p.id AS pid, p.customer_id, p.amount, p.payment_date, a.id AS aid
            FROM payments p JOIN appointments a ON a.id = p.appointment_id
            WHERE p.payment_status = 'paid' AND a.appointment_status = 'completed'
              AND NOT EXISTS (SELECT 1 FROM loyalty_transactions l WHERE l.reference_type = 'payment' AND l.reference_id = p.id AND l.transaction_type = 'earned')
            ORDER BY p.id";
    $n = 0;
    $total = 0;
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $cid = (int)$r['customer_id'];
        $m = $members[$cid] ?? null;
        if (!$m) {
            continue;
        }
        $pdate = $r['payment_date'] ? substr((string)$r['payment_date'], 0, 10) : null;
        if ($m['start'] && $pdate && $pdate < $m['start']) {
            continue;
        }
        $mult = $m['tier_id'] ? ($tiers[$m['tier_id']]['multiplier'] ?? 1.0) : 1.0;
        $pts = (int)floor(((float)$r['amount'] / $set['peso_per_point']) * $mult);
        if ($pts < 1) {
            continue;
        }
        try {
            $pdo->beginTransaction();
            ml_insert_tx($pdo, $cid, 'earned', $pts, 'payment', (int)$r['pid'], 'Completed appointment ' . appt_code((int)$r['aid']) . ' · ' . $m['tier'] . ' ' . rtrim(rtrim(number_format($mult, 2), '0'), '.') . 'x', null, $admin, $r['payment_date'] ?: null);
            $pdo->commit();
            $n++;
            $total += $pts;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
    return ['ok' => true, 'awarded' => $n, 'points' => $total];
}

function ml_save_tier(PDO $pdo, array $in, ?int $id): array
{
    $name = trim((string)($in['tier_name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        return ml_fail('Tier name must be 2 to 60 characters.');
    }
    $dup = $pdo->prepare('SELECT id FROM membership_tiers WHERE LOWER(tier_name) = LOWER(?) AND id <> ?');
    $dup->execute([$name, (int)$id]);
    if ($dup->fetchColumn()) {
        return ml_fail('A tier with this name already exists.');
    }
    $desc = trim((string)($in['description'] ?? ''));
    if (mb_strlen($desc) > 255) {
        return ml_fail('Description is too long (max 255 characters).');
    }
    $spend = $in['minimum_spending'] ?? '';
    $spend = ($spend === '' || $spend === null) ? 0.0 : (is_numeric($spend) ? (float)$spend : -1.0);
    if ($spend < 0 || $spend > 99999999) {
        return ml_fail('Minimum spending must be a positive amount.');
    }
    $pts = ($in['minimum_points'] ?? '') === '' ? 0 : filter_var($in['minimum_points'], FILTER_VALIDATE_INT);
    if ($pts === false || $pts < 0 || $pts > 100000000) {
        return ml_fail('Minimum points must be a whole number, zero or more.');
    }
    $disc = ($in['discount_percentage'] ?? '') === '' ? 0.0 : (is_numeric($in['discount_percentage']) ? (float)$in['discount_percentage'] : -1.0);
    if ($disc < 0 || $disc > 100) {
        return ml_fail('Discount must be between 0 and 100 percent.');
    }
    $mul = ($in['points_multiplier'] ?? '') === '' ? 1.0 : (is_numeric($in['points_multiplier']) ? (float)$in['points_multiplier'] : 0.0);
    if ($mul < 0.1 || $mul > 10) {
        return ml_fail('Points multiplier must be between 0.1 and 10.');
    }
    $color = trim((string)($in['tier_color'] ?? ''));
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return ml_fail('Choose a valid tier color.');
    }
    $status = (string)($in['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ml_fail('Invalid status.');
    }
    $ben = implode("\n", ml_lines($in['benefits'] ?? ''));
    $prv = implode("\n", ml_lines($in['privileges'] ?? ''));
    if ($id === null) {
        $ord = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM membership_tiers')->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO membership_tiers (tier_name, description, minimum_spending, minimum_points, discount_percentage, points_multiplier, tier_color, benefits, privileges, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $desc, $spend, $pts, $disc, $mul, $color, $ben, $prv, $ord, $status]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => $name . ' tier created.'];
    }
    $ex = $pdo->prepare('SELECT id FROM membership_tiers WHERE id = ?');
    $ex->execute([$id]);
    if (!$ex->fetchColumn()) {
        return ml_fail('Tier not found.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE membership_tiers SET tier_name = ?, description = ?, minimum_spending = ?, minimum_points = ?, discount_percentage = ?, points_multiplier = ?, tier_color = ?, benefits = ?, privileges = ?, status = ? WHERE id = ?')
            ->execute([$name, $desc, $spend, $pts, $disc, $mul, $color, $ben, $prv, $status, $id]);
        $pdo->prepare('UPDATE memberships SET membership_type = ? WHERE tier_id = ?')->execute([$name, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id, 'message' => $name . ' tier updated.'];
}

function ml_set_tier_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ml_fail('Invalid status.');
    }
    $st = $pdo->prepare('UPDATE membership_tiers SET status = ? WHERE id = ?');
    $st->execute([$status, $id]);
    $chk = $pdo->prepare('SELECT tier_name FROM membership_tiers WHERE id = ?');
    $chk->execute([$id]);
    $n = $chk->fetchColumn();
    if ($n === false) {
        return ml_fail('Tier not found.');
    }
    return ['ok' => true, 'message' => $n . ($status === 'active' ? ' tier activated.' : ' tier deactivated. Existing members keep their membership.')];
}

function ml_image_dir(): string
{
    return __DIR__ . '/../assets/uploads/rewards';
}

function ml_store_image(array $f, ?callable $isUploaded = null, ?callable $mover = null): array
{
    $isUploaded = $isUploaded ?? 'is_uploaded_file';
    $mover      = $mover ?? 'move_uploaded_file';
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ml_fail('The image is too large (max 3 MB).');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ml_fail('The image could not be uploaded. Please try again.');
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !$isUploaded($tmp)) {
        return ml_fail('Invalid image upload.');
    }
    if ((int)($f['size'] ?? 0) > ML_IMG_MAX || (int)filesize($tmp) > ML_IMG_MAX) {
        return ml_fail('The image is too large (max 3 MB).');
    }
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ml_fail('Image must be a JPG, JPEG, PNG or WEBP file.');
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $tmp);
        finfo_close($fi);
    }
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime]) || @getimagesize($tmp) === false) {
        return ml_fail('Image must be a valid JPG, PNG or WEBP file.');
    }
    $dir = ml_image_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ml_fail('The image folder could not be created.');
    }
    $name = 'rw_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    if (!$mover($tmp, $dir . '/' . $name)) {
        return ml_fail('The image could not be saved.');
    }
    return ['ok' => true, 'file' => $name];
}

function ml_delete_image(?string $name): void
{
    if ($name !== null && preg_match('/^rw_[a-f0-9]{16}\.(jpg|png|webp)$/', $name)) {
        $p = ml_image_dir() . '/' . $name;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

function ml_save_reward(PDO $pdo, array $in, array $files, ?int $id, ?callable $isUploaded = null, ?callable $mover = null): array
{
    $name = trim((string)($in['reward_name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return ml_fail('Reward name must be 2 to 120 characters.');
    }
    $dup = $pdo->prepare('SELECT id FROM rewards WHERE LOWER(reward_name) = LOWER(?) AND id <> ?');
    $dup->execute([$name, (int)$id]);
    if ($dup->fetchColumn()) {
        return ml_fail('A reward with this name already exists.');
    }
    $desc = trim((string)($in['description'] ?? ''));
    if (mb_strlen($desc) > 255) {
        return ml_fail('Description is too long (max 255 characters).');
    }
    $cat = trim((string)($in['reward_category'] ?? ''));
    if ($cat === '' || mb_strlen($cat) > 40) {
        return ml_fail('Choose a reward category.');
    }
    $pts = filter_var($in['required_points'] ?? '', FILTER_VALIDATE_INT);
    if ($pts === false || $pts < 1 || $pts > 10000000) {
        return ml_fail('Required points must be a whole number of 1 or more.');
    }
    $qty = null;
    if (trim((string)($in['available_quantity'] ?? '')) !== '') {
        $qty = filter_var($in['available_quantity'], FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 0 || $qty > 1000000) {
            return ml_fail('Available quantity must be a whole number, zero or more. Leave blank for unlimited.');
        }
    }
    $max = null;
    if (trim((string)($in['maximum_per_member'] ?? '')) !== '') {
        $max = filter_var($in['maximum_per_member'], FILTER_VALIDATE_INT);
        if ($max === false || $max < 1 || $max > 100000) {
            return ml_fail('Maximum per member must be 1 or more. Leave blank for no limit.');
        }
    }
    $from = ml_valid_date($in['valid_from'] ?? '');
    $to   = ml_valid_date($in['valid_until'] ?? '');
    if (trim((string)($in['valid_from'] ?? '')) !== '' && !$from) {
        return ml_fail('Valid From is not a valid date.');
    }
    if (trim((string)($in['valid_until'] ?? '')) !== '' && !$to) {
        return ml_fail('Valid Until is not a valid date.');
    }
    if ($from && $to && $to < $from) {
        return ml_fail('Valid Until must be on or after Valid From.');
    }
    $tiers = ml_tiers_raw($pdo);
    $minTier = null;
    if (trim((string)($in['minimum_tier_id'] ?? '')) !== '') {
        $minTier = (int)$in['minimum_tier_id'];
        if (!isset($tiers[$minTier])) {
            return ml_fail('Minimum membership level is invalid.');
        }
    }
    $elig = [];
    foreach ((array)($in['eligible_tiers'] ?? []) as $t) {
        $t = (int)$t;
        if (!isset($tiers[$t])) {
            return ml_fail('One of the eligible tiers is invalid.');
        }
        $elig[$t] = $t;
    }
    $status = (string)($in['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ml_fail('Invalid status.');
    }
    $old = null;
    if ($id !== null) {
        $ex = $pdo->prepare('SELECT reward_image FROM rewards WHERE id = ?');
        $ex->execute([$id]);
        $old = $ex->fetch();
        if (!$old) {
            return ml_fail('Reward not found.');
        }
    }
    $image = $old ? ($old['reward_image'] ?: null) : null;
    $newImage = null;
    $f = $files['reward_image'] ?? null;
    if ($f && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = ml_store_image($f, $isUploaded, $mover);
        if (empty($up['ok'])) {
            return $up;
        }
        $newImage = $up['file'];
        $image = $newImage;
    } elseif (!empty($in['remove_image'])) {
        $image = null;
    }
    try {
        $pdo->beginTransaction();
        if ($id === null) {
            $pdo->prepare('INSERT INTO rewards (reward_name, description, reward_image, reward_category, required_points, available_quantity, maximum_per_member, valid_from, valid_until, minimum_tier_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $desc, $image, $cat, $pts, $qty, $max, $from, $to, $minTier, $status]);
            $id = (int)$pdo->lastInsertId();
            $msg = $name . ' reward created.';
        } else {
            $pdo->prepare('UPDATE rewards SET reward_name = ?, description = ?, reward_image = ?, reward_category = ?, required_points = ?, available_quantity = ?, maximum_per_member = ?, valid_from = ?, valid_until = ?, minimum_tier_id = ?, status = ? WHERE id = ?')
                ->execute([$name, $desc, $image, $cat, $pts, $qty, $max, $from, $to, $minTier, $status, $id]);
            $msg = $name . ' reward updated.';
        }
        $pdo->prepare('DELETE FROM reward_tiers WHERE reward_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO reward_tiers (reward_id, tier_id) VALUES (?, ?)');
        foreach ($elig as $t) {
            $ins->execute([$id, $t]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newImage) {
            ml_delete_image($newImage);
        }
        throw $e;
    }
    if ($old && ($old['reward_image'] ?? null) && $old['reward_image'] !== $image) {
        ml_delete_image($old['reward_image']);
    }
    return ['ok' => true, 'id' => $id, 'message' => $msg];
}

function ml_set_reward_status(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ml_fail('Invalid status.');
    }
    $chk = $pdo->prepare('SELECT reward_name FROM rewards WHERE id = ?');
    $chk->execute([$id]);
    $n = $chk->fetchColumn();
    if ($n === false) {
        return ml_fail('Reward not found.');
    }
    $pdo->prepare('UPDATE rewards SET status = ? WHERE id = ?')->execute([$status, $id]);
    return ['ok' => true, 'message' => $n . ($status === 'active' ? ' is now active.' : ' is now inactive.')];
}

function ml_adjust_points(PDO $pdo, array $in, int $admin): array
{
    $cid = (int)($in['customer_id'] ?? 0);
    $kind = (string)($in['adjust_type'] ?? '');
    $pts = filter_var($in['points'] ?? '', FILTER_VALIDATE_INT);
    $reason = trim((string)($in['reason'] ?? ''));
    $notes = trim((string)($in['notes'] ?? ''));
    if ($cid < 1) {
        return ml_fail('Select a customer.');
    }
    if (!in_array($kind, ['add', 'deduct'], true)) {
        return ml_fail('Select an adjustment type.');
    }
    if ($pts === false || $pts < 1 || $pts > 1000000) {
        return ml_fail('Points must be a whole number from 1 to 1,000,000.');
    }
    if (!in_array($reason, ML_REASONS, true)) {
        return ml_fail('Select a reason.');
    }
    if (mb_strlen($notes) > 500) {
        return ml_fail('Notes are too long (max 500 characters).');
    }
    $pdo->beginTransaction();
    try {
        if (!ml_lock_customer($pdo, $cid)) {
            $pdo->rollBack();
            return ml_fail('Customer not found.');
        }
        $m = ml_member($pdo, $cid);
        if (!$m) {
            $pdo->rollBack();
            return ml_fail('This customer has no membership. Enroll them in a tier first.');
        }
        $bal = ml_balance($pdo, $cid);
        if ($kind === 'deduct' && $bal < $pts) {
            $pdo->rollBack();
            return ml_fail('Not enough points. Current balance is ' . number_format($bal) . '.');
        }
        $signed = $kind === 'add' ? $pts : -$pts;
        $type = ($kind === 'add' && $reason === 'Bonus points') ? 'bonus' : 'adjustment';
        $id = ml_insert_tx($pdo, $cid, $type, $signed, null, null, ($kind === 'add' ? 'Points added' : 'Points deducted') . ' · ' . $reason, $notes !== '' ? $notes : null, $admin);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id, 'balance' => $bal + $signed, 'message' => ($kind === 'add' ? 'Added ' : 'Deducted ') . number_format($pts) . ' points. New balance: ' . number_format($bal + $signed) . '.'];
}

function ml_change_tier(PDO $pdo, array $in, int $admin): array
{
    $cid = (int)($in['customer_id'] ?? 0);
    $tid = (int)($in['tier_id'] ?? 0);
    $reason = trim((string)($in['reason'] ?? ''));
    if ($cid < 1) {
        return ml_fail('Select a customer.');
    }
    if (mb_strlen($reason) > 200) {
        return ml_fail('Reason is too long (max 200 characters).');
    }
    $tiers = ml_tiers_raw($pdo);
    if (!isset($tiers[$tid])) {
        return ml_fail('Select a membership tier.');
    }
    $new = $tiers[$tid];
    if ($new['status'] !== 'active') {
        return ml_fail($new['name'] . ' is inactive and cannot be assigned.');
    }
    if (!ml_customer($pdo, $cid)) {
        return ml_fail('Customer not found.');
    }
    $cur = ml_member($pdo, $cid);
    if ($cur && $cur['tier_id'] === $tid && $cur['status'] === 'active') {
        return ml_fail('Customer is already in the ' . $new['name'] . ' tier.');
    }
    $d = ml_member_detail($pdo, $cid);
    $spent = $d['totals']['spent'];
    $life = $d['totals']['earned'];
    $qualified = $spent >= $new['min_spending'] && $life >= $new['min_points'];
    $upgrade = !$cur || !$cur['tier_id'] || $new['order'] > ($tiers[$cur['tier_id']]['order'] ?? 0);
    if ($upgrade && !$qualified && empty($in['override'])) {
        return ml_fail('Customer does not meet the ' . $new['name'] . ' requirements. Confirm the override to continue.', ['needs_override' => true]);
    }
    if ($upgrade && !$qualified && $reason === '') {
        return ml_fail('A reason is required when overriding qualification.');
    }
    $set = ml_settings($pdo);
    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        if (!ml_lock_customer($pdo, $cid)) {
            $pdo->rollBack();
            return ml_fail('Customer not found.');
        }
        if (!$cur) {
            $pdo->prepare('INSERT INTO memberships (customer_id, membership_type, start_date, expiry_date, membership_status, tier_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $new['name'], $today, ml_add_months($today, $set['membership_term_months']), 'active', $tid]);
            $type = 'enroll';
        } else {
            $mid = (int)$pdo->query('SELECT MAX(id) FROM memberships WHERE customer_id = ' . $cid)->fetchColumn();
            $pdo->prepare("UPDATE memberships SET membership_type = ?, tier_id = ?, membership_status = 'active' WHERE id = ?")->execute([$new['name'], $tid, $mid]);
            $type = $upgrade ? 'upgrade' : 'downgrade';
        }
        $pdo->prepare('INSERT INTO membership_history (customer_id, previous_tier_id, new_tier_id, change_type, changed_by, change_reason) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$cid, $cur['tier_id'] ?? null, $tid, $type, $admin, $reason !== '' ? $reason : null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $word = ['enroll' => 'enrolled in', 'upgrade' => 'upgraded to', 'downgrade' => 'moved down to'][$type];
    return ['ok' => true, 'message' => 'Customer ' . $word . ' ' . $new['name'] . '.'];
}

function ml_renew(PDO $pdo, int $cid, int $admin): array
{
    $m = ml_member($pdo, $cid);
    if (!$m) {
        return ml_fail('This customer has no membership.');
    }
    $set = ml_settings($pdo);
    $today = date('Y-m-d');
    $base = ($m['expiry'] && $m['expiry'] > $today) ? $m['expiry'] : $today;
    $exp = ml_add_months($base, $set['membership_term_months']);
    $pdo->beginTransaction();
    try {
        $mid = (int)$pdo->query('SELECT MAX(id) FROM memberships WHERE customer_id = ' . $cid)->fetchColumn();
        $pdo->prepare("UPDATE memberships SET expiry_date = ?, membership_status = 'active' WHERE id = ?")->execute([$exp, $mid]);
        $pdo->prepare('INSERT INTO membership_history (customer_id, previous_tier_id, new_tier_id, change_type, changed_by, change_reason) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$cid, $m['tier_id'], $m['tier_id'], 'renew', $admin, 'Renewed until ' . $exp]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'message' => 'Membership renewed until ' . date('M j, Y', strtotime($exp)) . '.'];
}

function ml_redeem(PDO $pdo, array $in, int $admin): array
{
    $cid = (int)($in['customer_id'] ?? 0);
    $rid = (int)($in['reward_id'] ?? 0);
    $tok = (string)($in['request_token'] ?? '');
    if ($cid < 1 || $rid < 1) {
        return ml_fail('Select a customer and a reward.');
    }
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $tok)) {
        return ml_fail('Invalid redemption request. Reopen the form and try again.');
    }
    $pdo->beginTransaction();
    try {
        if (!ml_lock_customer($pdo, $cid)) {
            $pdo->rollBack();
            return ml_fail('Customer not found.');
        }
        $dupe = $pdo->prepare('SELECT redemption_reference FROM reward_redemptions WHERE request_token = ?');
        $dupe->execute([$tok]);
        if ($ref = $dupe->fetchColumn()) {
            $pdo->rollBack();
            return ml_fail('This redemption was already processed (' . $ref . ').', ['duplicate' => true]);
        }
        $m = ml_member($pdo, $cid);
        if (!$m) {
            $pdo->rollBack();
            return ml_fail('This customer has no membership.');
        }
        if ($m['status'] !== 'active') {
            $pdo->rollBack();
            return ml_fail('Membership is ' . $m['status'] . '. Renew it before redeeming.');
        }
        $rs = $pdo->prepare('SELECT * FROM rewards WHERE id = ? FOR UPDATE');
        $rs->execute([$rid]);
        $row = $rs->fetch();
        if (!$row) {
            $pdo->rollBack();
            return ml_fail('Reward not found.');
        }
        $set = [];
        foreach ($pdo->query('SELECT reward_id, tier_id FROM reward_tiers')->fetchAll() as $x) {
            $set[(int)$x['reward_id']][] = (int)$x['tier_id'];
        }
        $rw = ml_reward_row($pdo, $row, $set);
        $used = $pdo->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE customer_id = ? AND reward_id = ? AND redemption_status = 'completed'");
        $used->execute([$cid, $rid]);
        $block = ml_reward_block($rw, $m, ml_tiers_raw($pdo), (int)$used->fetchColumn());
        if ($block !== null) {
            $pdo->rollBack();
            return ml_fail($block . '.');
        }
        $ref = 'RDM-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO reward_redemptions (customer_id, reward_id, points_used, redemption_reference, request_token, redemption_status, processed_by) VALUES (?, ?, ?, ?, ?, 'completed', ?)")
            ->execute([$cid, $rid, $rw['points'], $ref, $tok, $admin]);
        $redId = (int)$pdo->lastInsertId();
        $txId = ml_insert_tx($pdo, $cid, 'redeemed', -$rw['points'], 'redemption', $redId, 'Redeemed · ' . $rw['name'], null, $admin);
        $pdo->prepare('UPDATE reward_redemptions SET transaction_id = ? WHERE id = ?')->execute([$txId, $redId]);
        $pdo->prepare('UPDATE rewards SET redeemed_count = redeemed_count + 1' . ($rw['quantity'] !== null ? ', available_quantity = available_quantity - 1' : '') . ' WHERE id = ?')->execute([$rid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'reference' => $ref, 'balance' => $m['balance'] - $rw['points'], 'message' => $rw['name'] . ' redeemed. Reference ' . $ref . '.'];
}

function ml_reverse(PDO $pdo, int $txId, string $reason, int $admin): array
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 200) {
        return ml_fail('Enter a reason (max 200 characters).');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM loyalty_transactions WHERE id = ? FOR UPDATE');
        $st->execute([$txId]);
        $tx = $st->fetch();
        if (!$tx) {
            $pdo->rollBack();
            return ml_fail('Transaction not found.');
        }
        if ($tx['status'] !== 'completed') {
            $pdo->rollBack();
            return ml_fail('Only completed transactions can be reversed.');
        }
        $cid = (int)$tx['customer_id'];
        ml_lock_customer($pdo, $cid);
        $pts = (int)$tx['points'];
        if ($pts > 0 && ml_balance($pdo, $cid) - $pts < 0) {
            $pdo->rollBack();
            return ml_fail('Cannot reverse. The member has already used these points.');
        }
        $note = trim(((string)($tx['notes'] ?? '')) . "\nReversed by admin #" . $admin . ' on ' . date('Y-m-d H:i') . ': ' . $reason);
        $pdo->prepare("UPDATE loyalty_transactions SET status = 'reversed', notes = ? WHERE id = ?")->execute([$note, $txId]);
        if ($tx['transaction_type'] === 'redeemed' && $tx['reference_type'] === 'redemption') {
            $rd = $pdo->prepare('SELECT reward_id FROM reward_redemptions WHERE id = ?');
            $rd->execute([(int)$tx['reference_id']]);
            $rwid = $rd->fetchColumn();
            $pdo->prepare("UPDATE reward_redemptions SET redemption_status = 'cancelled' WHERE id = ?")->execute([(int)$tx['reference_id']]);
            if ($rwid) {
                $pdo->prepare('UPDATE rewards SET redeemed_count = CASE WHEN redeemed_count > 0 THEN redeemed_count - 1 ELSE 0 END, available_quantity = CASE WHEN available_quantity IS NULL THEN NULL ELSE available_quantity + 1 END WHERE id = ?')->execute([(int)$rwid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'message' => 'Transaction reversed.'];
}