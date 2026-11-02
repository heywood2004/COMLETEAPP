<?php
/**
 * Check-out API – finalizes stay and recalculates invoice via billing rules
 */
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}

require_once __DIR__ . '/billing_rules.php';

header('Content-Type: application/json');

try {
    $user = requirePermission('checkout');
    if ($user['role'] !== 'admin') {
        jsonResponse(false, 'Administrator access required.', null, 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed.', null, 405);
    }

    $input = getJsonInput();
    $bookingId = (int) ($input['booking_id'] ?? 0);
    $discount  = (float) ($input['discount_amount'] ?? 0);

    if ($bookingId <= 0) {
        throw new Exception('Booking ID is required.');
    }

    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    $stmt = $pdo->prepare("
        SELECT
            b.id, b.room_id, b.guest_id, b.guest_name,
            b.check_in, b.check_out, b.status, b.stay_status,
            b.actual_check_in, b.actual_check_out,
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
    if ($booking['stay_status'] !== 'checked_in') {
        throw new Exception('This guest is not currently checked in.');
    }

    // Mark checked out (status stays 'active' if 'completed' is not in ENUM)
    try {
        $upd = $pdo->prepare("
            UPDATE bookings
            SET stay_status = 'checked_out', actual_check_out = NOW(), status = 'completed'
            WHERE id = ?
        ");
        $upd->execute([$bookingId]);
    } catch (Throwable $e) {
        $upd = $pdo->prepare("
            UPDATE bookings
            SET stay_status = 'checked_out', actual_check_out = NOW()
            WHERE id = ?
        ");
        $upd->execute([$bookingId]);
    }

    $booking['actual_check_out'] = date('Y-m-d H:i:s');
    if (empty($booking['actual_check_in'])) {
        $booking['actual_check_in'] = $booking['check_in'];
    }

    // Mark room dirty for housekeeping
    try {
        $pdo->prepare("
            UPDATE rooms SET
                housekeeping_status = 'dirty',
                housekeeping_notes = 'Auto-set after checkout',
                housekeeping_updated_at = NOW()
            WHERE id = ?
        ")->execute([(int) $booking['room_id']]);
    } catch (Throwable $e) {
        // ignore if column missing
    }

    // Consume checkout inventory (e.g. minibar / disposable items rules)
    try {
        $roomId = (int) $booking['room_id'];
        $setup = $pdo->prepare("
            SELECT s.item_id, s.quantity_per_room, i.name, i.quantity AS stock, i.unit
            FROM room_setup_items s
            INNER JOIN inventory_items i ON i.id = s.item_id
            WHERE s.is_active = 1 AND i.is_active = 1 AND s.auto_consume_on = 'checkout'
              AND (s.room_type IS NULL OR s.room_type = '' OR s.room_type = ?)
        ");
        $setup->execute([$booking['room_type'] ?? '']);
        foreach ($setup->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $need = min((float)$row['quantity_per_room'], (float)$row['stock']);
            if ($need <= 0) continue;
            $newBal = round((float)$row['stock'] - $need, 2);
            $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?")->execute([$newBal, $row['item_id']]);
            $pdo->prepare("
                INSERT INTO inventory_transactions
                  (item_id, room_id, booking_id, txn_type, quantity, balance_after, reference, notes, recorded_by)
                VALUES (?, ?, ?, 'consume', ?, ?, ?, 'Checkout auto-consume', ?)
            ")->execute([
                $row['item_id'], $roomId, $bookingId, -$need, $newBal,
                'HK-CHECKOUT-R' . ($booking['room_number'] ?? ''),
                $user['id'] ?? null
            ]);
        }
    } catch (Throwable $e) {
        // inventory optional
    }

    $settings = getBillingSettings($pdo);
    $invoice  = upsertInvoiceForBooking(
        $pdo,
        $booking,
        (float) $booking['price'],
        $settings,
        true,   // checkout → apply actual nights + late fee rules
        max(0, $discount)
    );

    $pdo->commit();

    jsonResponse(true, 'Guest checked out successfully.', [
        'booking_id'        => $bookingId,
        'invoice_id'        => $invoice['invoice_id'],
        'invoice_number'    => $invoice['invoice_number'],
        'nights'            => $invoice['nights'],
        'subtotal'          => $invoice['subtotal'],
        'tax'               => $invoice['tax_amount'],
        'tax_amount'        => $invoice['tax_amount'],
        'service_charge'    => $invoice['service_charge'],
        'late_fee'          => $invoice['late_fee'],
        'discount_amount'   => $invoice['discount_amount'],
        'total'             => $invoice['total_amount'],
        'total_amount'      => $invoice['total_amount'],
        'amount_paid'       => $invoice['amount_paid'],
        'balance_due'       => $invoice['balance_due'],
        'status'            => $invoice['status'],
        'actual_check_out'  => $booking['actual_check_out'],
        'items'             => $invoice['items'],
    ], 200);

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $error->getMessage(), null, 400);
}
