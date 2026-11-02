<?php
/**
 * Simple occupancy & revenue reports (admin only)
 */
require_once __DIR__ . '/auth.php';

requirePermission('reports');

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to   = trim((string) ($_GET['to'] ?? date('Y-m-d')));

if (!isValidDate($from) || !isValidDate($to)) {
    jsonResponse(false, 'Invalid date range.', null, 400);
}
if ($from > $to) {
    jsonResponse(false, 'From date must be before to date.', null, 400);
}

try {
    $today = date('Y-m-d');

    // Room counts
    $totalRooms = (int) $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

    $bookedToday = (int) $pdo->prepare("
        SELECT COUNT(DISTINCT room_id) FROM bookings
        WHERE status = 'active'
          AND check_in <= ? AND check_out > ?
    ")->execute([$today, $today]) || true;
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT room_id) FROM bookings
        WHERE status = 'active' AND check_in <= ? AND check_out > ?
    ");
    $stmt->execute([$today, $today]);
    $bookedToday = (int) $stmt->fetchColumn();
    $availableToday = max(0, $totalRooms - $bookedToday);
    $occupancyToday = $totalRooms > 0 ? round(($bookedToday / $totalRooms) * 100, 1) : 0;

    // In-house
    $inHouse = (int) $pdo->query("
        SELECT COUNT(*) FROM bookings
        WHERE status = 'active' AND stay_status = 'checked_in'
    ")->fetchColumn();

    // Arrivals / departures today
    $arrivals = (int) $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE status = 'active' AND check_in = ? AND stay_status = 'not_arrived'
    ")->execute([$today]) || true;
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE status = 'active' AND check_in = ? AND COALESCE(stay_status,'not_arrived') = 'not_arrived'
    ");
    $st->execute([$today]);
    $arrivalsToday = (int) $st->fetchColumn();

    $st = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE status = 'active' AND check_out = ? AND stay_status = 'checked_in'
    ");
    $st->execute([$today]);
    $departuresToday = (int) $st->fetchColumn();

    // Bookings in range
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $st->execute([$from, $to]);
    $bookingsInRange = (int) $st->fetchColumn();

    $st = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE status = 'cancelled'
          AND COALESCE(cancelled_at, created_at) >= ?
          AND COALESCE(cancelled_at, created_at) < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $st->execute([$from, $to]);
    $cancellationsInRange = (int) $st->fetchColumn();

    // Revenue from payments in range
    $st = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total_collected,
            COUNT(*) AS payment_count
        FROM payments
        WHERE paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $st->execute([$from, $to]);
    $pay = $st->fetch(PDO::FETCH_ASSOC);

    // Outstanding balances (open invoices)
    $outstanding = (float) $pdo->query("
        SELECT COALESCE(SUM(balance_due), 0) FROM invoices
        WHERE status IN ('unpaid', 'partially_paid')
    ")->fetchColumn();

    // Invoice totals issued in range
    $st = $pdo->prepare("
        SELECT
            COALESCE(SUM(total_amount), 0) AS invoiced,
            COALESCE(SUM(amount_paid), 0) AS paid_on_invoices,
            COALESCE(SUM(balance_due), 0) AS balance_on_invoices
        FROM invoices
        WHERE issued_at >= ? AND issued_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $st->execute([$from, $to]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);

    // Revenue by payment method
    $st = $pdo->prepare("
        SELECT payment_method, COALESCE(SUM(amount), 0) AS total
        FROM payments
        WHERE paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $st->execute([$from, $to]);
    $byMethod = $st->fetchAll(PDO::FETCH_ASSOC);

    // Occupancy by day in range (sample up to 62 days)
    $days = [];
    $cursor = new DateTime($from);
    $end = new DateTime($to);
    $guard = 0;
    $occStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT room_id) FROM bookings
        WHERE status = 'active' AND check_in <= ? AND check_out > ?
    ");
    while ($cursor <= $end && $guard < 62) {
        $d = $cursor->format('Y-m-d');
        $occStmt->execute([$d, $d]);
        $booked = (int) $occStmt->fetchColumn();
        $days[] = [
            'date' => $d,
            'booked_rooms' => $booked,
            'occupancy_pct' => $totalRooms > 0 ? round(($booked / $totalRooms) * 100, 1) : 0,
        ];
        $cursor->modify('+1 day');
        $guard++;
    }

    // Top room types by booking count in range
    $st = $pdo->prepare("
        SELECT r.room_type, COUNT(b.id) AS bookings
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.created_at >= ? AND b.created_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY r.room_type
        ORDER BY bookings DESC
        LIMIT 10
    ");
    $st->execute([$from, $to]);
    $topTypes = $st->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Report generated.', [
        'range' => ['from' => $from, 'to' => $to],
        'today' => [
            'date' => $today,
            'total_rooms' => $totalRooms,
            'booked_rooms' => $bookedToday,
            'available_rooms' => $availableToday,
            'occupancy_pct' => $occupancyToday,
            'in_house' => $inHouse,
            'arrivals' => $arrivalsToday,
            'departures' => $departuresToday,
        ],
        'period' => [
            'bookings' => $bookingsInRange,
            'cancellations' => $cancellationsInRange,
            'payments_collected' => (float) $pay['total_collected'],
            'payment_count' => (int) $pay['payment_count'],
            'invoiced_total' => (float) $inv['invoiced'],
            'invoiced_paid' => (float) $inv['paid_on_invoices'],
            'invoiced_balance' => (float) $inv['balance_on_invoices'],
            'outstanding_all' => $outstanding,
        ],
        'by_payment_method' => $byMethod,
        'occupancy_by_day' => $days,
        'top_room_types' => $topTypes,
    ], 200);

} catch (Throwable $e) {
    jsonResponse(false, $e->getMessage(), null, 500);
}
