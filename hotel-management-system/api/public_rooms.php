<?php
/**
 * Public room gallery – no login required
 * Returns room types summary + individual rooms suitable for marketing display.
 * Does not expose sensitive admin fields.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed.', null, 405);
}

try {
    $today = date('Y-m-d');

    // Check if housekeeping column exists safely
    $hasHk = false;
    try {
        $pdo->query("SELECT housekeeping_status FROM rooms LIMIT 1");
        $hasHk = true;
    } catch (Throwable $e) {
        $hasHk = false;
    }

    $hkSelect = $hasHk
        ? "COALESCE(r.housekeeping_status, 'ready') AS housekeeping_status,"
        : "'ready' AS housekeeping_status,";

    $statusCase = $hasHk
        ? "CASE
                WHEN COALESCE(r.housekeeping_status, 'ready') = 'out_of_order' THEN 'out_of_order'
                WHEN b.id IS NOT NULL THEN 'booked'
                WHEN COALESCE(r.housekeeping_status, 'ready') IN ('dirty', 'cleaning') THEN 'maintenance'
                ELSE 'available'
           END AS status"
        : "CASE
                WHEN b.id IS NOT NULL THEN 'booked'
                ELSE 'available'
           END AS status";

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.room_number,
            r.room_type,
            r.price,
            r.capacity,
            r.description,
            {$hkSelect}
            {$statusCase}
        FROM rooms r
        LEFT JOIN bookings b
            ON b.room_id = r.id
            AND b.status = 'active'
            AND b.check_in <= ?
            AND b.check_out > ?
        ORDER BY r.price ASC, r.room_number ASC
    ");
    $stmt->execute([$today, $today]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate by room type for gallery cards
    $byType = [];
    foreach ($rooms as $room) {
        $type = $room['room_type'] ?: 'Standard';
        if (!isset($byType[$type])) {
            $byType[$type] = [
                'room_type' => $type,
                'count' => 0,
                'available_count' => 0,
                'min_price' => (float) $room['price'],
                'max_price' => (float) $room['price'],
                'capacity' => (int) $room['capacity'],
                'description' => $room['description'] ?: '',
                'sample_room_number' => $room['room_number'],
            ];
        }
        $byType[$type]['count']++;
        if ($room['status'] === 'available') {
            $byType[$type]['available_count']++;
        }
        $price = (float) $room['price'];
        if ($price < $byType[$type]['min_price']) $byType[$type]['min_price'] = $price;
        if ($price > $byType[$type]['max_price']) $byType[$type]['max_price'] = $price;
        if ((int) $room['capacity'] > $byType[$type]['capacity']) {
            $byType[$type]['capacity'] = (int) $room['capacity'];
        }
        if (!$byType[$type]['description'] && $room['description']) {
            $byType[$type]['description'] = $room['description'];
        }
    }

    $types = array_values($byType);
    usort($types, fn($a, $b) => $a['min_price'] <=> $b['min_price']);

    $availableToday = count(array_filter($rooms, fn($r) => $r['status'] === 'available'));

    jsonResponse(true, 'Public room gallery loaded.', [
        'rooms' => $rooms,
        'types' => $types,
        'stats' => [
            'total_rooms' => count($rooms),
            'available_today' => $availableToday,
            'room_types' => count($types),
        ],
    ], 200);

} catch (Throwable $e) {
    jsonResponse(false, 'Unable to load rooms.', $e->getMessage(), 500);
}
