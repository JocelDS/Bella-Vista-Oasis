<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard-data.php';

api_guard();

function addon_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function addon_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function addon_columns(PDO $pdo): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM service_addons')->fetchAll() as $row) {
        $columns[(string)$row['Field']] = true;
    }
    return $columns;
}

try {
    $pdo = db();
    $columns = addon_columns($pdo);

    foreach (['id', 'addon_name', 'price', 'commissionable', 'is_active'] as $required) {
        if (!isset($columns[$required])) {
            addon_response([
                'ok' => false,
                'error' => 'The service_addons table is missing the required column: ' . $required,
                'setup_required' => true,
            ], 409);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $descriptionExpr = isset($columns['description'])
            ? "COALESCE(description, '') AS description"
            : "'' AS description";
        $sortExpr = isset($columns['sort_order']) ? 'sort_order' : 'id';

        $rows = $pdo->query(
            "SELECT id, addon_name, {$descriptionExpr}, price, commissionable, is_active,
                    {$sortExpr} AS sort_order
             FROM service_addons
             ORDER BY {$sortExpr} ASC, addon_name ASC, id ASC"
        )->fetchAll();

        $addons = array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['addon_name'],
                'description' => (string)($row['description'] ?? ''),
                'price' => (float)$row['price'],
                'commissionable' => (int)$row['commissionable'] === 1,
                'active' => (int)$row['is_active'] === 1,
                'sort_order' => (int)($row['sort_order'] ?? 0),
            ];
        }, $rows);

        addon_response(['ok' => true, 'addons' => $addons]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        addon_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    if (!csrf_verify()) {
        addon_response(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $name = trim((string)($_POST['addon_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $priceRaw = trim((string)($_POST['price'] ?? ''));
        $price = filter_var($priceRaw, FILTER_VALIDATE_FLOAT);
        $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $commissionable = isset($_POST['commissionable']) ? 1 : 0;
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || addon_length($name) > 120) {
            addon_response(['ok' => false, 'error' => 'Enter an add-on name (up to 120 characters).'], 422);
        }
        if (addon_length($description) > 500) {
            addon_response(['ok' => false, 'error' => 'Description must be 500 characters or fewer.'], 422);
        }
        if ($price === false || !is_finite((float)$price) || $price < 0 || $price > 99999999.99) {
            addon_response(['ok' => false, 'error' => 'Enter a valid price between ₱0 and ₱99,999,999.99.'], 422);
        }
        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 9999) {
            addon_response(['ok' => false, 'error' => 'Display order must be a whole number from 0 to 9999.'], 422);
        }

        if ($action === 'create') {
            $fields = ['addon_name', 'price', 'commissionable', 'is_active'];
            $values = [$name, $price, $commissionable, $active];

            if (isset($columns['description'])) {
                $fields[] = 'description';
                $values[] = $description;
            }
            if (isset($columns['sort_order'])) {
                $fields[] = 'sort_order';
                $values[] = $sortOrder;
            }

            $placeholders = array_fill(0, count($fields), '?');
            // created_at exists in the user's original table; provide it explicitly
            // in case the column does not have a DEFAULT CURRENT_TIMESTAMP value.
            if (isset($columns['created_at'])) {
                $fields[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            $fieldSql = implode(', ', $fields);
            $placeholderSql = implode(', ', $placeholders);
            $stmt = $pdo->prepare("INSERT INTO service_addons ({$fieldSql}) VALUES ({$placeholderSql})");
            $stmt->execute($values);

            addon_response([
                'ok' => true,
                'message' => 'Spa ritual add-on created successfully.',
                'id' => (int)$pdo->lastInsertId(),
                'description_saved' => isset($columns['description']),
                'sort_order_saved' => isset($columns['sort_order']),
            ]);
        }

        if ($id < 1) {
            addon_response(['ok' => false, 'error' => 'Invalid add-on.'], 422);
        }

        $sets = ['addon_name = ?', 'price = ?', 'commissionable = ?', 'is_active = ?'];
        $values = [$name, $price, $commissionable, $active];

        if (isset($columns['description'])) {
            $sets[] = 'description = ?';
            $values[] = $description;
        }
        if (isset($columns['sort_order'])) {
            $sets[] = 'sort_order = ?';
            $values[] = $sortOrder;
        }

        $values[] = $id;
        $stmt = $pdo->prepare('UPDATE service_addons SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);

        addon_response([
            'ok' => true,
            'message' => 'Spa ritual add-on updated successfully.',
            'description_saved' => isset($columns['description']),
            'sort_order_saved' => isset($columns['sort_order']),
        ]);
    }

    if ($action === 'set_active') {
        if ($id < 1) {
            addon_response(['ok' => false, 'error' => 'Invalid add-on.'], 422);
        }
        $active = ((string)($_POST['is_active'] ?? '0') === '1') ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE service_addons SET is_active = ? WHERE id = ?');
        $stmt->execute([$active, $id]);
        addon_response(['ok' => true, 'message' => $active ? 'Add-on activated.' : 'Add-on deactivated.']);
    }

    addon_response(['ok' => false, 'error' => 'Unknown action.'], 422);
} catch (Throwable $e) {
    error_log('[BVO service add-ons] ' . $e->getMessage());
    addon_response(['ok' => false, 'error' => 'Unable to process the add-on request. Check the PHP error log for details.'], 500);
}
