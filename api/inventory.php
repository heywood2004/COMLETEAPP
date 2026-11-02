<?php
/**
 * Inventory API
 * GET    – list items (+ low stock), optional category filter
 * POST   – create item | stock in/out/adjust | consume for room
 * PUT    – update item
 * DELETE – soft-deactivate item
 */
require_once __DIR__ . '/auth.php';

/**
 * When included from housekeeping/checkin for helpers only, set:
 *   define('SKIP_INVENTORY_ROUTER', true);
 * before require_once this file.
 */

function invFetchItem(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? LIMIT 1");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function invAdjustStock(
    PDO $pdo,
    int $itemId,
    string $txnType,
    float $qty,
    ?int $roomId,
    ?int $bookingId,
    ?int $userId,
    string $reference = '',
    string $notes = ''
): array {
    if ($qty == 0.0) {
        throw new Exception('Quantity cannot be zero.');
    }
    if (!in_array($txnType, ['in', 'out', 'adjust', 'consume'], true)) {
        throw new Exception('Invalid transaction type.');
    }

    $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new Exception('Inventory item not found or inactive.');
    }

    $current = (float) $item['quantity'];
    if ($txnType === 'in') {
        $delta = abs($qty);
        $newBal = $current + $delta;
    } elseif ($txnType === 'out' || $txnType === 'consume') {
        $delta = -abs($qty);
        $newBal = $current + $delta;
        if ($newBal < -0.0001) {
            throw new Exception("Insufficient stock for {$item['name']}. Available: {$current} {$item['unit']}.");
        }
    } else { // adjust = set absolute
        $newBal = $qty;
        $delta = $newBal - $current;
    }

    $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?")
        ->execute([round($newBal, 2), $itemId]);

    $pdo->prepare("
        INSERT INTO inventory_transactions
            (item_id, room_id, booking_id, txn_type, quantity, balance_after, reference, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $itemId,
        $roomId,
        $bookingId,
        $txnType,
        round($delta, 2),
        round($newBal, 2),
        $reference !== '' ? $reference : null,
        $notes !== '' ? $notes : null,
        $userId
    ]);

    return [
        'item_id' => $itemId,
        'name' => $item['name'],
        'delta' => round($delta, 2),
        'balance' => round($newBal, 2),
        'unit' => $item['unit'],
        'low_stock' => $newBal <= (float) $item['reorder_level'],
    ];
}

/**
 * Consume room-setup items for a given trigger (ready|cleaning|checkout)
 */
function invConsumeRoomSetup(PDO $pdo, int $roomId, string $trigger, ?int $userId, ?int $bookingId = null): array
{
    $roomStmt = $pdo->prepare("SELECT id, room_number, room_type FROM rooms WHERE id = ? LIMIT 1");
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        return ['consumed' => [], 'skipped' => [], 'errors' => ['Room not found']];
    }

    $setup = $pdo->prepare("
        SELECT s.*, i.name, i.unit, i.quantity AS stock
        FROM room_setup_items s
        INNER JOIN inventory_items i ON i.id = s.item_id
        WHERE s.is_active = 1 AND i.is_active = 1
          AND s.auto_consume_on = ?
          AND (s.room_type IS NULL OR s.room_type = '' OR s.room_type = ?)
    ");
    $setup->execute([$trigger, $room['room_type']]);
    $rows = $setup->fetchAll(PDO::FETCH_ASSOC);

    $consumed = [];
    $errors = [];
    foreach ($rows as $row) {
        $qty = (float) $row['quantity_per_room'];
        if ($qty <= 0) continue;
        try {
            $result = invAdjustStock(
                $pdo,
                (int) $row['item_id'],
                'consume',
                $qty,
                $roomId,
                $bookingId,
                $userId,
                'HK-' . strtoupper($trigger) . '-R' . $room['room_number'],
                "Auto consume on {$trigger} for room {$room['room_number']}"
            );
            $consumed[] = $result;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    return ['consumed' => $consumed, 'errors' => $errors, 'room' => $room['room_number']];
}


// ---------- HTTP router (only when this file is the API entry) ----------
if (!defined('SKIP_INVENTORY_ROUTER') || !SKIP_INVENTORY_ROUTER) {

if (function_exists('requireCsrf')) {
    requireCsrf();
}
requirePermission('inventory');
$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET ----------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        if ($action === 'transactions') {
            $itemId = (int) ($_GET['item_id'] ?? 0);
            $sql = "
                SELECT t.*, i.name AS item_name, i.unit,
                       r.room_number, u.name AS recorded_by_name
                FROM inventory_transactions t
                INNER JOIN inventory_items i ON i.id = t.item_id
                LEFT JOIN rooms r ON r.id = t.room_id
                LEFT JOIN users u ON u.id = t.recorded_by
            ";
            $params = [];
            if ($itemId > 0) {
                $sql .= " WHERE t.item_id = ? ";
                $params[] = $itemId;
            }
            $sql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(true, 'Transactions loaded.', $stmt->fetchAll(PDO::FETCH_ASSOC), 200);
        }

        if ($action === 'setup') {
            $stmt = $pdo->query("
                SELECT s.*, i.name AS item_name, i.unit, i.category
                FROM room_setup_items s
                INNER JOIN inventory_items i ON i.id = s.item_id
                ORDER BY s.auto_consume_on, i.name
            ");
            jsonResponse(true, 'Setup rules loaded.', $stmt->fetchAll(PDO::FETCH_ASSOC), 200);
        }

        // default list
        $cat = trim((string) ($_GET['category'] ?? ''));
        $sql = "SELECT * FROM inventory_items WHERE is_active = 1";
        $params = [];
        if ($cat !== '') {
            $sql .= " AND category = ?";
            $params[] = $cat;
        }
        $sql .= " ORDER BY category, name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$it) {
            $it['low_stock'] = (float) $it['quantity'] <= (float) $it['reorder_level'];
            $it['id'] = (int) $it['id'];
        }
        unset($it);

        $low = array_values(array_filter($items, fn($i) => $i['low_stock']));
        jsonResponse(true, 'Inventory loaded.', [
            'items' => $items,
            'low_stock' => $low,
            'low_count' => count($low),
        ], 200);
    } catch (Throwable $e) {
        jsonResponse(false, $e->getMessage(), null, 500);
    }
}

// ---------- POST ----------
if ($method === 'POST') {
    $user = requirePermission('inventory');
    $input = getJsonInput();
    $action = strtolower((string) ($input['action'] ?? 'create'));

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($action === 'create') {
            $name = trim((string) ($input['name'] ?? ''));
            $category = strtolower(trim((string) ($input['category'] ?? 'other')));
            $unit = trim((string) ($input['unit'] ?? 'pcs')) ?: 'pcs';
            $qty = (float) ($input['quantity'] ?? 0);
            $reorder = (float) ($input['reorder_level'] ?? 0);
            $cost = (float) ($input['unit_cost'] ?? 0);
            $allowedCat = ['linen','amenity','cleaning','minibar','maintenance','other'];
            if ($name === '') throw new Exception('Item name is required.');
            if (!in_array($category, $allowedCat, true)) $category = 'other';

            $pdo->prepare("
                INSERT INTO inventory_items (name, category, unit, quantity, reorder_level, unit_cost)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$name, $category, $unit, $qty, $reorder, $cost]);
            $id = (int) $pdo->lastInsertId();
            if ($qty != 0) {
                invAdjustStock($pdo, $id, 'adjust', $qty, null, null, (int)$user['id'], 'OPENING', 'Opening balance');
            }
            $pdo->commit();
            jsonResponse(true, 'Item created.', invFetchItem($pdo, $id), 201);
        }

        if (in_array($action, ['in', 'out', 'adjust', 'consume'], true)) {
            $itemId = (int) ($input['item_id'] ?? 0);
            $qty = (float) ($input['quantity'] ?? 0);
            $roomId = !empty($input['room_id']) ? (int) $input['room_id'] : null;
            $notes = trim((string) ($input['notes'] ?? ''));
            $ref = trim((string) ($input['reference'] ?? ''));
            if ($itemId <= 0) throw new Exception('Item ID required.');
            $result = invAdjustStock($pdo, $itemId, $action, $qty, $roomId, null, (int)$user['id'], $ref, $notes);
            $pdo->commit();
            jsonResponse(true, 'Stock updated.', $result, 200);
        }

        if ($action === 'consume_setup') {
            $roomId = (int) ($input['room_id'] ?? 0);
            $trigger = strtolower((string) ($input['trigger'] ?? 'ready'));
            if ($roomId <= 0) throw new Exception('Room ID required.');
            if (!in_array($trigger, ['ready', 'cleaning', 'checkout'], true)) {
                throw new Exception('Invalid trigger.');
            }
            $result = invConsumeRoomSetup($pdo, $roomId, $trigger, (int)$user['id']);
            $pdo->commit();
            $msg = count($result['consumed'])
                ? 'Inventory consumed for room setup.'
                : 'No setup items configured for this trigger.';
            if (!empty($result['errors'])) {
                $msg .= ' Warnings: ' . implode('; ', $result['errors']);
            }
            jsonResponse(true, $msg, $result, 200);
        }

        if ($action === 'add_setup') {
            $itemId = (int) ($input['item_id'] ?? 0);
            $qty = (float) ($input['quantity_per_room'] ?? 1);
            $roomType = trim((string) ($input['room_type'] ?? ''));
            $when = strtolower((string) ($input['auto_consume_on'] ?? 'ready'));
            if ($itemId <= 0) throw new Exception('Item required.');
            if (!in_array($when, ['ready', 'cleaning', 'checkout'], true)) $when = 'ready';
            $pdo->prepare("
                INSERT INTO room_setup_items (item_id, room_type, quantity_per_room, auto_consume_on)
                VALUES (?, ?, ?, ?)
            ")->execute([$itemId, $roomType !== '' ? $roomType : null, $qty, $when]);
            $pdo->commit();
            jsonResponse(true, 'Setup rule added.', null, 201);
        }

        throw new Exception('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $e->getMessage(), null, 400);
    }
}

// ---------- PUT ----------
if ($method === 'PUT') {
    $input = getJsonInput();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, 'Invalid item ID.', null, 400);
    try {
        $pdo->prepare("
            UPDATE inventory_items SET
                name = ?, category = ?, unit = ?,
                reorder_level = ?, unit_cost = ?, notes = ?
            WHERE id = ?
        ")->execute([
            trim((string)($input['name'] ?? '')),
            strtolower(trim((string)($input['category'] ?? 'other'))),
            trim((string)($input['unit'] ?? 'pcs')),
            (float)($input['reorder_level'] ?? 0),
            (float)($input['unit_cost'] ?? 0),
            trim((string)($input['notes'] ?? '')) ?: null,
            $id
        ]);
        jsonResponse(true, 'Item updated.', invFetchItem($pdo, $id), 200);
    } catch (Throwable $e) {
        jsonResponse(false, $e->getMessage(), null, 400);
    }
}

// ---------- DELETE (soft) ----------
if ($method === 'DELETE') {
    $input = getJsonInput();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) jsonResponse(false, 'Invalid item ID.', null, 400);
    $pdo->prepare("UPDATE inventory_items SET is_active = 0 WHERE id = ?")->execute([$id]);
    jsonResponse(true, 'Item deactivated.', null, 200);
}

jsonResponse(false, 'Method not allowed.', null, 405);

} // end SKIP_INVENTORY_ROUTER guard
