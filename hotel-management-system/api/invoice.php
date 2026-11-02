<?php
/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * INVOICE API (PDO)
 * ==========================================
 *
 * GET ?booking_id=123
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. Please use GET.', null, 405);
}

$user = requireLogin();

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);

if (!$bookingId || $bookingId <= 0) {
    jsonResponse(false, 'Valid booking ID is required.', null, 400);
}

$userId = (int) $user['id'];
$userRole = strtolower($user['role'] ?? 'user');

try {
    if ($userRole === 'admin') {
        $stmt = $pdo->prepare("
            SELECT
                b.id AS booking_id,
                b.user_id,
                b.room_id,
                b.guest_name,
                b.check_in,
                b.check_out,
                b.status AS booking_status,
                b.stay_status,
                r.room_number,
                r.room_type,
                r.price,
                r.capacity,
                r.description
            FROM bookings b
            INNER JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                b.id AS booking_id,
                b.user_id,
                b.room_id,
                b.guest_name,
                b.check_in,
                b.check_out,
                b.status AS booking_status,
                b.stay_status,
                r.room_number,
                r.room_type,
                r.price,
                r.capacity,
                r.description
            FROM bookings b
            INNER JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
              AND b.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId, $userId]);
    }

    $booking = $stmt->fetch();

    if (!$booking) {
        jsonResponse(false, 'Booking not found or you do not have permission to view it.', null, 404);
    }

    $checkIn  = $booking['check_in'];
    $checkOut = $booking['check_out'];

    if (empty($checkIn) || empty($checkOut)) {
        jsonResponse(false, 'Booking does not contain valid dates.', null, 400);
    }

    $checkInDate  = DateTime::createFromFormat('Y-m-d', $checkIn);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $checkOut);

    if (!$checkInDate || !$checkOutDate || $checkOutDate <= $checkInDate) {
        jsonResponse(false, 'Invalid booking dates.', null, 400);
    }

    $nights = max(1, (int) $checkInDate->diff($checkOutDate)->days);
    $roomPrice = (float) $booking['price'];
    $subtotal  = round($roomPrice * $nights, 2);

    // Tax from settings (default 16%)
    $taxRate = 0.0;
    try {
        $taxStmt = $pdo->prepare("SELECT setting_value FROM hotel_settings WHERE setting_key = 'tax_rate' LIMIT 1");
        $taxStmt->execute();
        $row = $taxStmt->fetch();
        if ($row) {
            $taxRate = (float) $row['setting_value'];
        }
    } catch (Throwable $e) {
        $taxRate = 16.0;
    }

    $taxAmount = round($subtotal * ($taxRate / 100), 2);
    $total     = round($subtotal + $taxAmount, 2);

    // Prefer stored invoice if present
    $invStmt = $pdo->prepare("
        SELECT *
        FROM invoices
        WHERE booking_id = ?
        LIMIT 1
    ");
    $invStmt->execute([$bookingId]);
    $stored = $invStmt->fetch();

    $invoiceNumber = $stored['invoice_number']
        ?? ('INV-' . date('Ymd') . '-' . str_pad((string) $bookingId, 4, '0', STR_PAD_LEFT));

    $amountPaid = (float) ($stored['amount_paid'] ?? 0);
    $balanceDue = $stored
        ? (float) $stored['balance_due']
        : max(0, $total - $amountPaid);

    if ($stored) {
        $subtotal   = (float) $stored['subtotal'];
        $taxAmount  = (float) $stored['tax_amount'];
        $total      = (float) $stored['total_amount'];
        $amountPaid = (float) $stored['amount_paid'];
        $balanceDue = (float) $stored['balance_due'];
    }

    $invoice = [
        'invoice_number'  => $invoiceNumber,
        'invoice_date'    => date('Y-m-d'),
        'invoice_id'      => $stored ? (int) $stored['id'] : null,
        'booking_id'      => (int) $booking['booking_id'],
        'user_id'         => (int) $booking['user_id'],
        'guest_name'      => $booking['guest_name'],
        'room_id'         => (int) $booking['room_id'],
        'room_number'     => $booking['room_number'],
        'room_type'       => $booking['room_type'],
        'capacity'        => (int) ($booking['capacity'] ?? 0),
        'description'     => $booking['description'] ?? '',
        'check_in'        => $checkIn,
        'check_out'       => $checkOut,
        'nights'          => $nights,
        'booking_status'  => $booking['booking_status'],
        'stay_status'     => $booking['stay_status'] ?? 'not_arrived',
        'currency'        => 'KES',
        'price_per_night' => $roomPrice,
        'subtotal'        => $subtotal,
        'tax_rate'        => $taxRate,
        'tax'             => $taxAmount,
        'tax_amount'      => $taxAmount,
        'total'           => $total,
        'total_amount'    => $total,
        'amount_paid'     => $amountPaid,
        'balance_due'     => $balanceDue,
        'status'          => $stored['status'] ?? 'unpaid'
    ];

    jsonResponse(true, 'Invoice generated successfully.', $invoice, 200);

} catch (Throwable $e) {
    error_log('Invoice API Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while generating the invoice.', null, 500);
}
