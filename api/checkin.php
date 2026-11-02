<?php
/**
 * Check-in API – creates guest profile + automated invoice via billing rules
 */
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}

require_once __DIR__ . '/billing_rules.php';
require_once __DIR__ . '/sms.php';

header('Content-Type: application/json');

try {
    $user = requirePermission('checkin');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed.', null, 405);
    }

    $input = getJsonInput();
    $bookingId = (int) ($input['booking_id'] ?? 0);
    $guestId   = (int) ($input['guest_id'] ?? 0);

    if ($bookingId <= 0) {
        throw new Exception('Booking ID is required.');
    }

    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    $stmt = $pdo->prepare("
        SELECT
            b.id, b.user_id, b.room_id, b.guest_id, b.guest_name,
            b.check_in, b.check_out, b.status, b.stay_status,
            r.room_number, r.room_type, r.price
        FROM bookings b
        INNER JOIN rooms r ON r.id = b.room_id
        WHERE b.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found.');
    }
    if ($booking['status'] !== 'active') {
        throw new Exception('This booking is not active.');
    }
    if ($booking['stay_status'] === 'checked_in') {
        throw new Exception('This guest is already checked in.');
    }
    if ($booking['stay_status'] === 'checked_out') {
        throw new Exception('This booking has already been checked out.');
    }

    // Guest profile
    if ($guestId > 0) {
        $guestStmt = $pdo->prepare("SELECT id FROM guests WHERE id = ? LIMIT 1");
        $guestStmt->execute([$guestId]);
        if (!$guestStmt->fetch()) {
            throw new Exception('Guest profile not found.');
        }
    } else {
        $guestName = trim((string) $booking['guest_name']);
        if ($guestName === '') {
            throw new Exception('Guest information is required.');
        }
        $guestStmt = $pdo->prepare("INSERT INTO guests (full_name) VALUES (?)");
        $guestStmt->execute([$guestName]);
        $guestId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET guest_id = ?, stay_status = 'checked_in', actual_check_in = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$guestId, $bookingId]);

    $booking['guest_id'] = $guestId;
    $booking['actual_check_in'] = date('Y-m-d H:i:s');

    $settings = getBillingSettings($pdo);
    $invoice  = upsertInvoiceForBooking(
        $pdo,
        $booking,
        (float) $booking['price'],
        $settings,
        false
    );

    $pdo->commit();

    try {
        $phone = '';
        // guest profile phone
        if (!empty($guestId)) {
            $gp = $pdo->prepare("SELECT phone FROM guests WHERE id = ? LIMIT 1");
            $gp->execute([(int)$guestId]);
            $phone = smsNormalizePhone((string)($gp->fetchColumn() ?: ''));
        }
        if ($phone === '' && !empty($booking['user_id'])) {
            $up = $pdo->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
            $up->execute([(int)$booking['user_id']]);
            $phone = smsNormalizePhone((string)($up->fetchColumn() ?: ''));
        }
        if ($phone !== '') {
            sendCheckinSms([
                'room_number' => $booking['room_number'] ?? '',
                'id' => $bookingId,
            ], $phone);
        }
    } catch (Throwable $e) {
        if (function_exists('apiLog')) {
            apiLog('warning', 'Check-in SMS failed', ['error' => $e->getMessage()]);
        }
    }


    jsonResponse(true, 'Guest checked in successfully.', [
        'booking_id'       => $bookingId,
        'guest_id'         => $guestId,
        'invoice_id'       => $invoice['invoice_id'],
        'invoice_number'   => $invoice['invoice_number'],
        'nights'           => $invoice['nights'],
        'subtotal'         => $invoice['subtotal'],
        'tax_amount'       => $invoice['tax_amount'],
        'total_amount'     => $invoice['total_amount'],
        'balance_due'      => $invoice['balance_due'],
        'room_number'      => $booking['room_number'],
        'actual_check_in'  => $booking['actual_check_in'],
    ], 200);

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $error->getMessage(), null, 400);
}
