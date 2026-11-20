<?php
declare(strict_types=1);
/*
 * Customer QR pass helpers. Everything here sits on top of the existing QR check-in system
 * (admin/includes/qr-checkin-data.php), so the Admin QR Check-In page verifies the very same tokens.
 */
require_once __DIR__ . '/booking-bridge.php';
require_once __DIR__ . '/lib/phpqrcode.php';

/** One of the customer's own appointments, with everything the pass needs. Null if it is not theirs. */
function qrp_load(PDO $pdo, int $customerId, int $id): ?array
{
    cu_booking_ready($pdo);
    $st = $pdo->prepare(
        'SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.appointment_status, a.payment_status, a.total_amount,
                a.guest_count, a.membership_discount, s.service_name, s.duration, r.room_name, r.room_type,
                t.full_name AS therapist, t.specialization, c.full_name AS customer, c.membership_code,
                (SELECT MAX(k.checked_in_at) FROM appointment_checkins k WHERE k.appointment_id = a.id AND k.checkin_status = \'checked_in\') AS checked_in_at
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN customers c ON c.id = a.customer_id
         LEFT JOIN rooms r ON r.id = a.room_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.id = ? AND a.customer_id = ?'
    );
    $st->execute([$id, $customerId]);
    $a = $st->fetch();
    return $a ?: null;
}

function qrp_ref(int $id): string
{
    return 'BVO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** Is a payload still an active, unused, unexpired token for this appointment? */
function qrp_token_active(PDO $pdo, int $apptId, string $payload): bool
{
    if (!str_starts_with($payload, QR_PREFIX)) return false;
    $token = substr($payload, strlen(QR_PREFIX));
    if (!preg_match(QR_TOKEN_RE, $token)) return false;
    $st = $pdo->prepare(
        "SELECT 1 FROM appointment_checkins
         WHERE appointment_id = ? AND checkin_token_hash = ? AND checkin_status = 'pending'
           AND verification_status IN ('pending','verified') AND token_expires_at > ? LIMIT 1"
    );
    $st->execute([$apptId, qr_token_hash($token), qr_now()->format('Y-m-d H:i:s')]);
    return (bool)$st->fetchColumn();
}

/**
 * The pass for one appointment.
 * state: active | checked_in | pending | cancelled | closed | ended | error
 * Only a confirmed appointment (or a pending one when the spa allows it) ever gets a QR code.
 * The token is stored hashed, so the readable code is kept in the guest's session and reused until it expires.
 */
function qrp_pass(PDO $pdo, array $a): array
{
    $st = (string)$a['appointment_status'];
    if (!empty($a['checked_in_at'])) {
        return ['state' => 'checked_in', 'message' => 'You were checked in on ' . date('M j, g:i A', strtotime((string)$a['checked_in_at'])) . '. Enjoy your sanctuary.'];
    }
    if ($st === 'cancelled') return ['state' => 'cancelled', 'message' => 'This reservation was cancelled, so it has no QR pass.'];
    if (in_array($st, ['no_show', 'completed'], true)) return ['state' => 'closed', 'message' => 'This reservation is ' . str_replace('_', ' ', $st) . ' and cannot be used to check in.'];
    if (qr_dt((string)$a['appointment_date'], (string)$a['end_time']) <= qr_now()) return ['state' => 'ended', 'message' => 'This session has ended, so the pass has expired.'];

    if (!qr_ready($pdo)) return ['state' => 'error', 'message' => 'QR check-in is not set up yet. Please contact the concierge.'];
    $s = qr_settings($pdo);
    if (!($st === 'confirmed' || ($st === 'pending' && $s['allow_pending']))) {
        return ['state' => 'pending', 'message' => 'Your reservation is awaiting confirmation. Your QR pass appears here as soon as the spa confirms it.'];
    }

    $id = (int)$a['id'];
    $cached = $_SESSION['qr_pass'][$id] ?? null;
    if (is_array($cached) && qrp_token_active($pdo, $id, (string)$cached['payload'])) {
        return ['state' => 'active', 'payload' => (string)$cached['payload'], 'expires_label' => (string)$cached['expires_label']];
    }
    try {
        $g = qr_generate($pdo, $id, null);
    } catch (Throwable $e) {
        error_log('[BVO qr pass] ' . $e->getMessage());
        return ['state' => 'error', 'message' => 'We could not prepare your QR pass right now. Please try again.'];
    }
    if (!$g['ok']) return ['state' => 'error', 'message' => (string)$g['error']];
    $_SESSION['qr_pass'][$id] = ['payload' => $g['payload'], 'expires_label' => $g['expires_label']];
    return ['state' => 'active', 'payload' => (string)$g['payload'], 'expires_label' => (string)$g['expires_label']];
}

/** QR matrix rows of '0'/'1' from the PHP QR library (no external service). */
function qrp_matrix(string $payload): array
{
    return QRcode::text($payload, false, QR_ECLEVEL_M);
}

/** System palette: purple finder squares, purple-to-pink fade across the data modules. */
function qrp_is_finder(int $r, int $c, int $n): bool
{
    return ($r < 7 && $c < 7) || ($r < 7 && $c >= $n - 7) || ($r >= $n - 7 && $c < 7);
}

/** @return array{0: float, 1: float, 2: float} RGB 0..1 for one module */
function qrp_tone(int $r, int $c, int $n): array
{
    if (qrp_is_finder($r, $c, $n)) {
        return [152 / 255, 107 / 255, 194 / 255];
    }
    $t = ($r + $c) / (2 * max(1, $n - 1));
    return [(152 + 73 * $t) / 255, (107 + 32 * $t) / 255, (194 - 9 * $t) / 255];
}

function qrp_svg(array $matrix, string $label = 'Reservation QR code'): string
{
    $n = count($matrix);
    $m = 3;
    $fd = '';
    $dd = '';
    foreach ($matrix as $y => $row) {
        $len = strlen($row);
        for ($x = 0; $x < $len; $x++) {
            if ($row[$x] !== '1') continue;
            $seg = 'M' . ($x + $m) . ' ' . ($y + $m) . 'h1v1h-1z';
            if (qrp_is_finder((int)$y, $x, $n)) $fd .= $seg; else $dd .= $seg;
        }
    }
    $size = $n + 2 * $m;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . e($label) . '" shape-rendering="crispEdges">'
        . '<defs><linearGradient id="qrg" gradientUnits="userSpaceOnUse" x1="' . $m . '" y1="' . $m . '" x2="' . ($n + $m) . '" y2="' . ($n + $m) . '"><stop offset="0" stop-color="#986BC2"/><stop offset="1" stop-color="#E18BB9"/></linearGradient></defs>'
        . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path d="' . $dd . '" fill="url(#qrg)"/><path d="' . $fd . '" fill="#986BC2"/></svg>';
}

/** The window in which the guest can arrive, from the real appointment time and the spa's check-in policy. */
function qrp_window(PDO $pdo, array $a): array
{
    $s = qr_ready($pdo) ? qr_settings($pdo) : ['early' => 60, 'late' => 30];
    $arrive = (int)cu_setting('arrival_minutes_before', '15');
    $start = qr_dt((string)$a['appointment_date'], (string)$a['start_time']);
    return [
        'opens'  => $start->modify('-' . (int)$s['early'] . ' minutes'),
        'closes' => $start->modify('+' . (int)$s['late'] . ' minutes'),
        'by'     => $start->modify('-' . $arrive . ' minutes'),
        'start'  => $start,
        'early'  => (int)$s['early'],
        'late'   => (int)$s['late'],
        'arrive' => $arrive,
    ];
}

function qrp_address(): array
{
    return array_values(array_filter([cu_setting('address_line1', ''), cu_setting('address_line2', ''), cu_setting('address_line3', '')], static fn($v) => trim((string)$v) !== ''));
}

/* ---------- Calendar file ---------- */

function qrp_ics_escape(string $v): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $v);
}

function qrp_ics(array $a): string
{
    $start = date('Ymd\THis', strtotime($a['appointment_date'] . ' ' . $a['start_time']));
    $end = date('Ymd\THis', strtotime($a['appointment_date'] . ' ' . $a['end_time']));
    $ref = qrp_ref((int)$a['id']);
    $loc = implode(', ', qrp_address());
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Bella Vista Oasis//Reservation//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VTIMEZONE', 'TZID:Asia/Manila', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0800', 'TZOFFSETTO:+0800', 'TZNAME:PST', 'END:STANDARD', 'END:VTIMEZONE',
        'BEGIN:VEVENT',
        'UID:' . $ref . '@bellavista-oasis',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART;TZID=Asia/Manila:' . $start,
        'DTEND;TZID=Asia/Manila:' . $end,
        'SUMMARY:' . qrp_ics_escape('Bella Vista Oasis: ' . $a['service_name']),
        'LOCATION:' . qrp_ics_escape($loc),
        'DESCRIPTION:' . qrp_ics_escape('Reservation ' . $ref . '. Please arrive ' . (int)cu_setting('arrival_minutes_before', '15') . ' minutes early and present your QR pass at reception.'),
        'STATUS:' . ($a['appointment_status'] === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE'),
        'END:VEVENT', 'END:VCALENDAR',
    ];
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 74) { $out .= substr($l, 0, 74) . "\r\n "; $l = substr($l, 74); }
        $out .= $l . "\r\n";
    }
    return $out;
}

/* ---------- PDF pass (plain PDF 1.4, no library needed) ---------- */

function qrp_pdf_text(string $s): string
{
    $s = str_replace('₱', 'PHP ', $s);
    $c = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    $c = $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$c);
}

function qrp_pdf(array $a, array $matrix, string $expiresLabel): string
{
    $W = 595; $H = 842;
    $c = '';
    $fill = static function (string $hex): string {
        return sprintf('%.3f %.3f %.3f rg ', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    };
    $text = static function (float $x, float $y, string $font, float $size, string $str, string $hex) use (&$c, $fill): void {
        $c .= 'BT ' . $fill($hex) . '/' . $font . ' ' . $size . ' Tf ' . round($x, 2) . ' ' . round($y, 2) . ' Td (' . qrp_pdf_text($str) . ") Tj ET\n";
    };
    $rect = static function (float $x, float $y, float $w, float $h, string $hex) use (&$c, $fill): void {
        $c .= $fill($hex) . round($x, 2) . ' ' . round($y, 2) . ' ' . round($w, 2) . ' ' . round($h, 2) . " re f\n";
    };

    $rect(0, 0, $W, $H, 'FFF7FC');
    $rect(0, $H - 12, $W, 12, '986BC2');
    $rect(0, $H - 12, $W / 2, 12, 'E18BB9');
    $rect(0, $H - 12, $W / 3, 12, '986BC2');

    $logo = __DIR__ . '/../assets/images/bella-vista-logo-pass.jpg';
    $hasLogo = is_file($logo) && ($li = @getimagesize($logo)) !== false;
    if ($hasLogo) $c .= "q 64 0 0 64 48 " . ($H - 110) . " cm /Im1 Do Q\n";
    $text(124, $H - 70, 'F3', 26, 'Bella Vista Oasis', '49364F');
    $text(124, $H - 90, 'F1', 9, 'SPA, BATH & SANCTUARY', '986BC2');

    $text(48, $H - 150, 'F2', 9, 'EXPEDITED SANCTUARY CHECK-IN', '89788D');
    $text(48, $H - 182, 'F3', 30, 'Reservation Pass', '49364F');

    $rect(48, $H - 238, 499, 34, 'FFFFFF');
    $text(60, $H - 226, 'F2', 12, 'Reference  ' . qrp_ref((int)$a['id']), '986BC2');
    $text(330, $H - 226, 'F1', 11, 'Status: ' . ucfirst(str_replace('_', ' ', (string)$a['appointment_status'])), '49364F');

    /* QR code */
    $n = count($matrix); $cell = 6.0; $mg = 3;
    $qs = ($n + 2 * $mg) * $cell;
    $qx = 48; $qy = $H - 270 - $qs;
    $rect($qx, $qy, $qs, $qs, 'FFFFFF');
    foreach ($matrix as $y => $row) {
        for ($x = 0, $len = strlen($row); $x < $len; $x++) {
            if ($row[$x] !== '1') continue;
            [$cr, $cg, $cb] = qrp_tone((int)$y, $x, $n);
            $c .= sprintf('%.3f %.3f %.3f rg ', $cr, $cg, $cb)
                . round($qx + ($x + $mg) * $cell - 0.15, 2) . ' ' . round($qy + $qs - ($y + $mg + 1) * $cell - 0.15, 2) . ' ' . ($cell + 0.3) . ' ' . ($cell + 0.3) . " re f\n";
        }
    }
    $text($qx, $qy - 16, 'F1', 9, 'Valid until ' . $expiresLabel, '89788D');

    /* Details */
    $dx = $qx + $qs + 34; $dy = $H - 290;
    $rows = [
        ['GUEST', (string)$a['customer']],
        ['TREATMENT', (string)$a['service_name']],
        ['DATE', date('l, F j, Y', strtotime((string)$a['appointment_date']))],
        ['TIME', date('g:i A', strtotime((string)$a['start_time'])) . ' - ' . date('g:i A', strtotime((string)$a['end_time']))],
        ['SUITE', (string)($a['room_name'] ?? 'To be assigned')],
        ['THERAPIST', (string)($a['therapist'] ?? 'Any Master Therapist')],
        ['GUESTS', (string)(int)$a['guest_count']],
        ['TOTAL', '₱' . number_format((float)$a['total_amount'], 2) . ' (payment ' . (string)$a['payment_status'] . ')'],
    ];
    foreach ($rows as $r) {
        $text($dx, $dy, 'F2', 8, $r[0], '89788D');
        $text($dx, $dy - 14, 'F1', 12, $r[1], '49364F');
        $dy -= 38;
    }

    $iy = min($qy - 70, $dy);
    $rect(48, $iy - 70, 499, 84, 'FCE9F2');
    $text(64, $iy - 8, 'F2', 11, 'Check-in instructions', 'B23A63');
    $text(64, $iy - 28, 'F1', 10, 'Please arrive ' . (int)cu_setting('arrival_minutes_before', '15') . ' minutes before your appointment.', '49364F');
    $text(64, $iy - 43, 'F1', 10, 'Present this QR code to reception for verification. Each code works once.', '49364F');
    $text(64, $iy - 58, 'F1', 10, 'Concierge: ' . cu_setting('contact_phone', ''), '49364F');

    $addr = implode(', ', qrp_address());
    $text(48, 54, 'F1', 9, $addr, '89788D');
    $text(48, 40, 'F1', 9, 'Bella Vista Oasis Spa & Wellness Sanctuary', '89788D');

    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >>' . ($hasLogo ? ' /XObject << /Im1 7 0 R >>' : '') . ' >> /Contents 8 0 R >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objs[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>';
    $out = "%PDF-1.4\n";
    $offsets = [];
    $emit = static function (int $i, string $body) use (&$out, &$offsets): void {
        $offsets[$i] = strlen($out);
        $out .= $i . " 0 obj\n" . $body . "\nendobj\n";
    };
    for ($i = 1; $i <= 6; $i++) $emit($i, $objs[$i]);
    if ($hasLogo) {
        $jpg = (string)file_get_contents($logo);
        $emit(7, '<< /Type /XObject /Subtype /Image /Width ' . $li[0] . ' /Height ' . $li[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . " >>\nstream\n" . $jpg . "\nendstream");
    } else {
        $emit(7, '<< >>');
    }
    $emit(8, '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "endstream");
    $xref = strlen($out);
    $out .= "xref\n0 9\n0000000000 65535 f \n";
    for ($i = 1; $i <= 8; $i++) $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $out .= "trailer\n<< /Size 9 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $out;
}