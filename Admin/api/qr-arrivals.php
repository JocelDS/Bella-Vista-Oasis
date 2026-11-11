<?php
declare(strict_types=1);

// GET date, filter, q, page, per -> Today's Arrival Monitor rows + filter counts
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/qr-checkin-data.php';

[$pdo] = qr_api('GET');

qr_guard_errors(static function () use ($pdo): array {
    $date = appt_parse_date((string)($_GET['date'] ?? '')) ?? date('Y-m-d');
    return ['ok' => true] + qr_arrivals(
        $pdo,
        $date,
        (string)($_GET['filter'] ?? 'all'),
        (string)($_GET['q'] ?? ''),
        (int)($_GET['page'] ?? 1),
        (int)($_GET['per'] ?? QR_PER_PAGE)
    );
});