<?php
/**
 * Housekeeping room status API
 * GET  – list rooms with HK status (+ optional filter)
 * POST – update status for one room
 */
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


requirePermission('housekeeping');

$method = $_SERVER['REQUEST_METHOD'];
$allowed = ['ready', 'dirty', 'cleaning', 'out_of_order'];

if ($method === 'GET') {
    $filter = strtolower(trim((string) ($_GET['status'] ?? '')));
    try {
        $today = date('Y-m-d');
        $sql = "
            SELECT
                r.id,
                r.room_number,
                r.room_type,
                r.price,
                r.capacity,
                r.description,
                COALESCE(r.housekeeping_status, 'ready') AS housekeeping_status,
                r.housekeeping_notes,
                r.housekeeping_updated_at,
                CASE
                    WHEN b.id IS NOT NULL THEN 'booked'
                    ELSE 'available'
                END AS occupancy_status,
                b.id AS active_booking_id,
                b.guest_name,
                b.stay_status
            FROM rooms r
            LEFT JOIN bookings b
                ON b.room_id = r.id
                AND b.status = 'active'
                AND b.check_in <= ?
                AND b.check_out > ?
        ";
        $params = [$today, $today];
        if ($filter !== '' && in_array($filter, $allowed, true)) {
            $sql .= " WHERE COALESCE(r.housekeeping_status, 'ready') = ? ";
            $params[] = $filter;
        }
        $sql .= " ORDER BY r.room_number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Counts
        $counts = [
            'ready' => 0,
            'dirty' => 0,
            'cleaning' => 0,
            'out_of_order' => 0,
            'total' => count($rooms),
        ];
        // If filtered, recount from full set
        if ($filter !== '') {
            $all = $pdo->query("
                SELECT COALESCE(housekeeping_status, 'ready') AS s, COUNT(*) AS c
                FROM rooms GROUP BY COALESCE(housekeeping_status, 'ready')
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($allowed as $s) {
                $counts[$s] = (int) ($all[$s] ?? 0);
            }
            $counts['total'] = array_sum(array_map(fn($s) => $counts[$s], $allowed));
        } else {
            foreach ($rooms as $r) {
                $s = $r['housekeeping_status'] ?? 'ready';
                if (isset($counts[$s])) $counts[$s]++;
            }
        }

        jsonResponse(true, 'Housekeeping list loaded.', [
            'rooms' => $rooms,
            'counts' => $counts,
        ], 200);
    } catch (Throwable $e) {
        jsonResponse(false, $e->getMessage(), null, 500);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $status = strtolower(trim((string) ($input['status'] ?? '')));
    $notes  = trim((string) ($input['notes'] ?? ''));

    if (!$roomId) {
        jsonResponse(false, 'Room ID is required.', null, 400);
    }
    if (!in_array($status, $allowed, true)) {
        jsonResponse(false, 'Invalid status. Use: ready, dirty, cleaning, out_of_order.', null, 400);
    }

    try {
        $user = requirePermission('housekeeping');
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

        $stmt = $pdo->prepare("
            SELECT id, room_number, COALESCE(housekeeping_status, 'ready') AS housekeeping_status
            FROM rooms WHERE id = ? LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new Exception('Room not found.');
        }

        $old = $room['housekeeping_status'];

        $upd = $pdo->prepare("
            UPDATE rooms SET
                housekeeping_status = ?,
                housekeeping_notes = ?,
                housekeeping_updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            $status,
            $notes !== '' ? $notes : null,
            $roomId
        ]);

        try {
            $log = $pdo->prepare("
                INSERT INTO housekeeping_log (room_id, old_status, new_status, notes, changed_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $log->execute([
                $roomId,
                $old,
                $status,
                $notes !== '' ? $notes : null,
                $user['id']
            ]);
        } catch (Throwable $e) {
            // log table optional
        }

        // Auto-consume inventory when marking ready or cleaning
        $inventoryResult = null;
        if (in_array($status, ['ready', 'cleaning'], true)) {
            try {
                if (!defined('SKIP_INVENTORY_ROUTER')) {
                    define('SKIP_INVENTORY_ROUTER', true);
                }
                require_once __DIR__ . '/inventory.php';
            } catch (Throwable $e) {
                // inventory.php defines routes - load only helper via separate include
            }
        }

        // Inline consume to avoid double-routing when requiring inventory.php
        $inventoryResult = ['consumed' => [], 'errors' => []];
        if (in_array($status, ['ready', 'cleaning'], true) && function_exists('invConsumeRoomSetup')) {
            $inventoryResult = invConsumeRoomSetup($pdo, $roomId, $status, (int) $user['id']);
        } elseif (in_array($status, ['ready', 'cleaning'], true)) {
            // define consume inline if inventory helpers not loaded
            try {
                $roomInfo = $pdo->prepare("SELECT room_number, room_type FROM rooms WHERE id = ?");
                $roomInfo->execute([$roomId]);
                $ri = $roomInfo->fetch(PDO::FETCH_ASSOC);
                $setup = $pdo->prepare("
                    SELECT s.item_id, s.quantity_per_room, i.name, i.quantity AS stock, i.unit
                    FROM room_setup_items s
                    INNER JOIN inventory_items i ON i.id = s.item_id
                    WHERE s.is_active = 1 AND i.is_active = 1
                      AND s.auto_consume_on = ?
                      AND (s.room_type IS NULL OR s.room_type = '' OR s.room_type = ?)
                ");
                $setup->execute([$status, $ri['room_type'] ?? '']);
                foreach ($setup->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $need = (float) $row['quantity_per_room'];
                    $stock = (float) $row['stock'];
                    if ($need <= 0) continue;
                    if ($stock < $need) {
                        $inventoryResult['errors'][] = "Low stock: {$row['name']} (need {$need}, have {$stock})";
                        $need = $stock; // consume what's left
                    }
                    if ($need <= 0) continue;
                    $newBal = round($stock - $need, 2);
                    $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?")
                        ->execute([$newBal, $row['item_id']]);
                    $pdo->prepare("
                        INSERT INTO inventory_transactions
                          (item_id, room_id, txn_type, quantity, balance_after, reference, notes, recorded_by)
                        VALUES (?, ?, 'consume', ?, ?, ?, ?, ?)
                    ")->execute([
                        $row['item_id'], $roomId, -$need, $newBal,
                        'HK-' . strtoupper($status) . '-R' . ($ri['room_number'] ?? ''),
                        "Auto on {$status}", $user['id']
                    ]);
                    $inventoryResult['consumed'][] = [
                        'name' => $row['name'],
                        'delta' => -$need,
                        'balance' => $newBal,
                        'unit' => $row['unit'],
                    ];
                }
            } catch (Throwable $e) {
                $inventoryResult['errors'][] = $e->getMessage();
            }
        }

        $pdo->commit();

        $msg = "Room {$room['room_number']} marked as {$status}.";
        if (!empty($inventoryResult['consumed'])) {
            $msg .= ' Inventory updated (' . count($inventoryResult['consumed']) . ' item(s)).';
        }
        if (!empty($inventoryResult['errors'])) {
            $msg .= ' Stock warnings: ' . implode('; ', $inventoryResult['errors']);
        }

        jsonResponse(true, $msg, [
            'room_id' => (int) $roomId,
            'room_number' => $room['room_number'],
            'old_status' => $old,
            'status' => $status,
            'notes' => $notes,
            'inventory' => $inventoryResult,
        ], 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $e->getMessage(), null, 400);
    }
}

jsonResponse(false, 'Method not allowed.', null, 405);
