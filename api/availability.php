<?php
/**
 * Date-range room availability for guests
 * GET ?check_in=YYYY-MM-DD&check_out=YYYY-MM-DD
 */
require_once __DIR__ . '/auth.php';

requireLogin();

$checkIn  = trim((string) ($_GET['check_in'] ?? ''));
$checkOut = trim((string) ($_GET['check_out'] ?? ''));

if ($checkIn === '' || $checkOut === '') {
    // Fall back to "available today" list via rooms logic
    $checkIn  = date('Y-m-d');
    $checkOut = date('Y-m-d', strtotime('+1 day'));
}

if (!isValidDate($checkIn) || !isValidDate($checkOut)) {
    jsonResponse(false, 'Invalid dates.', null, 400);
}
if (strtotime($checkOut) <= strtotime($checkIn)) {
    jsonResponse(false, 'Check-out must be after check-in.', null, 400);
}
if (strtotime($checkIn) < strtotime(date('Y-m-d'))) {
    jsonResponse(false, 'Check-in cannot be in the past.', null, 400);
}

try {
    $nights = max(1, (int) (new DateTime($checkIn))->diff(new DateTime($checkOut))->days);

    // Rooms with no overlapping active booking in the range, and not out of order
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.room_number,
            r.room_type,
            r.price,
            r.capacity,
            r.description,
            COALESCE(r.housekeeping_status, 'ready') AS housekeeping_status,
            CASE
                WHEN COALESCE(r.housekeeping_status, 'ready') = 'out_of_order' THEN 'out_of_order'
                WHEN EXISTS (
                    SELECT 1 FROM bookings b
                    WHERE b.room_id = r.id
                      AND b.status = 'active'
                      AND b.check_in < ?
                      AND b.check_out > ?
                ) THEN 'booked'
                WHEN COALESCE(r.housekeeping_status, 'ready') IN ('dirty', 'cleaning') THEN 'maintenance'
                ELSE 'available'
            END AS status
        FROM rooms r
        ORDER BY r.room_number ASC
    ");
    $stmt->execute([$checkOut, $checkIn]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $available = [];
    foreach ($all as $room) {
        $room['nights'] = $nights;
        $room['estimated_total'] = round($nights * (float) $room['price'], 2);
        $room['check_in'] = $checkIn;
        $room['check_out'] = $checkOut;
        if ($room['status'] === 'available') {
            $available[] = $room;
        }
    }

    jsonResponse(true, 'Availability loaded.', [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'available_count' => count($available),
        'rooms' => $available,
        'all_rooms' => $all,
    ], 200);
} catch (Throwable $e) {
    jsonResponse(false, $e->getMessage(), null, 500);
}
