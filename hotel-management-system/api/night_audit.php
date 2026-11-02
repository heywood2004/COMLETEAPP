<?php
/**
 * Night audit report – end of day operational snapshot
 * GET ?date=YYYY-MM-DD  (defaults to today)
 */
require_once __DIR__ . '/auth.php';

requirePermission('night_audit');

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!isValidDate($date)) {
    apiError('Invalid date. Use YYYY-MM-DD.', 400);
}

try {
    $nextDay = (new DateTime($date))->modify('+1 day')->format('Y-m-d');

    // Arrivals expected today
    $arrivals = $pdo->prepare("
        SELECT b.id, b.guest_name, b.check_in, b.check_out, b.stay_status, b.status,
               r.room_number, r.room_type
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.status = 'active' AND b.check_in = ?
        ORDER BY r.room_number
    ");
    $arrivals->execute([$date]);
    $arrivalList = $arrivals->fetchAll(PDO::FETCH_ASSOC);

    // Departures expected today
    $departures = $pdo->prepare("
        SELECT b.id, b.guest_name, b.check_in, b.check_out, b.stay_status, b.status,
               r.room_number, r.room_type
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.status = 'active' AND b.check_out = ?
        ORDER BY r.room_number
    ");
    $departures->execute([$date]);
    $departureList = $departures->fetchAll(PDO::FETCH_ASSOC);

    // In-house (checked in, not checked out)
    $inHouse = $pdo->query("
        SELECT b.id, b.guest_name, b.check_in, b.check_out, b.stay_status,
               r.room_number, r.room_type
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.status = 'active' AND b.stay_status = 'checked_in'
        ORDER BY r.room_number
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Actually checked in on this date
    $checkedInToday = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE DATE(actual_check_in) = ? OR (stay_status = 'checked_in' AND check_in = ? AND actual_check_in IS NOT NULL)
    ");
    $checkedInToday->execute([$date, $date]);
    $checkedInCount = (int)$checkedInToday->fetchColumn();

    // Actually checked out on this date
    $checkedOutToday = $pdo->prepare("
        SELECT COUNT(*) FROM bookings WHERE DATE(actual_check_out) = ?
    ");
    $checkedOutToday->execute([$date]);
    $checkedOutCount = (int)$checkedOutToday->fetchColumn();

    // Payments collected on this date
    $payments = $pdo->prepare("
        SELECT p.id, p.amount, p.payment_method AS method, p.transaction_reference, p.paid_at,
               p.invoice_id, i.invoice_number, b.guest_name, r.room_number
        FROM payments p
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN bookings b ON b.id = i.booking_id
        LEFT JOIN rooms r ON r.id = b.room_id
        WHERE DATE(p.paid_at) = ?
        ORDER BY p.paid_at
    ");
    $payments->execute([$date]);
    $paymentList = $payments->fetchAll(PDO::FETCH_ASSOC);

    $paymentsByMethod = [];
    $paymentsTotal = 0.0;
    foreach ($paymentList as $pay) {
        $m = $pay['method'] ?: 'other';
        $paymentsByMethod[$m] = ($paymentsByMethod[$m] ?? 0) + (float)$pay['amount'];
        $paymentsTotal += (float)$pay['amount'];
    }

    // Open balances (unpaid / partial invoices for active or in-house)
    $open = $pdo->query("
        SELECT i.id, i.invoice_number, i.total_amount, i.amount_paid, i.balance_due, i.status,
               b.guest_name, r.room_number, b.check_out
        FROM invoices i
        INNER JOIN bookings b ON b.id = i.booking_id
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE i.balance_due > 0.009 AND i.status IN ('unpaid', 'partial', 'issued', 'pending')
        ORDER BY i.balance_due DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $openTotal = 0.0;
    foreach ($open as $row) {
        $openTotal += (float)$row['balance_due'];
    }

    // Occupancy snapshot
    $totalRooms = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $occupied = $pdo->prepare("
        SELECT COUNT(DISTINCT room_id) FROM bookings
        WHERE status = 'active' AND check_in <= ? AND check_out > ?
    ");
    $occupied->execute([$date, $date]);
    $occupiedCount = (int)$occupied->fetchColumn();
    $occupancyPct = $totalRooms > 0 ? round($occupiedCount / $totalRooms * 100, 1) : 0;

    // Housekeeping summary if column exists
    $hk = [];
    try {
        $hkRows = $pdo->query("
            SELECT housekeeping_status, COUNT(*) AS cnt
            FROM rooms GROUP BY housekeeping_status
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hkRows as $row) {
            $hk[$row['housekeeping_status'] ?: 'unknown'] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {
        $hk = [];
    }

    jsonResponse(true, 'Night audit for ' . $date, [
        'date' => $date,
        'summary' => [
            'total_rooms' => $totalRooms,
            'occupied_rooms' => $occupiedCount,
            'occupancy_pct' => $occupancyPct,
            'expected_arrivals' => count($arrivalList),
            'expected_departures' => count($departureList),
            'in_house' => count($inHouse),
            'checked_in_today' => $checkedInCount,
            'checked_out_today' => $checkedOutCount,
            'payments_total' => round($paymentsTotal, 2),
            'payments_by_method' => $paymentsByMethod,
            'open_balance_total' => round($openTotal, 2),
            'open_balance_count' => count($open),
        ],
        'arrivals' => $arrivalList,
        'departures' => $departureList,
        'in_house' => $inHouse,
        'payments' => $paymentList,
        'open_balances' => $open,
        'housekeeping' => $hk,
        'generated_at' => date('c'),
        'generated_by' => getCurrentUser()['name'] ?? 'staff',
    ]);
} catch (Throwable $e) {
    handleApiException($e, 'Unable to generate night audit.');
}
