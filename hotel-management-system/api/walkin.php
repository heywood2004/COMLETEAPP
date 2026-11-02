<?php
/**
 * Admin walk-in booking
 * Creates a booking without requiring a registered user account.
 */
require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


requirePermission('walkin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.', null, 405);
}

$input = getJsonInput();

$roomId    = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
$guestName = clean($input['guest_name'] ?? '');
$checkIn   = trim((string) ($input['check_in'] ?? ''));
$checkOut  = trim((string) ($input['check_out'] ?? ''));
$phone     = trim((string) ($input['phone'] ?? ''));
$email     = trim((string) ($input['email'] ?? ''));
$idNumber  = trim((string) ($input['id_number'] ?? ''));
$notes     = trim((string) ($input['notes'] ?? ''));
$autoCheckin = !empty($input['auto_checkin']);

if (!$roomId) {
    jsonResponse(false, 'Please select a room.', null, 400);
}
if ($guestName === '') {
    jsonResponse(false, 'Guest name is required.', null, 400);
}
if (!isValidDate($checkIn)) {
    jsonResponse(false, 'Invalid check-in date.', null, 400);
}
if (!isValidDate($checkOut)) {
    jsonResponse(false, 'Invalid check-out date.', null, 400);
}
if (strtotime($checkOut) <= strtotime($checkIn)) {
    jsonResponse(false, 'Check-out must be after check-in.', null, 400);
}

try {
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    $roomStmt = $pdo->prepare("
        SELECT id, room_number, room_type, price, capacity
        FROM rooms WHERE id = ? LIMIT 1
    ");
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        throw new Exception('Room does not exist.');
    }
    try {
        $hk = $pdo->prepare("SELECT COALESCE(housekeeping_status,'ready') AS s FROM rooms WHERE id = ?");
        $hk->execute([$roomId]);
        $s = $hk->fetchColumn();
        if ($s === 'out_of_order') {
            throw new Exception('This room is out of order and cannot be booked.');
        }
    } catch (Exception $ex) {
        throw $ex;
    } catch (Throwable $e) {}

    // Overlap lock
    $overlap = $pdo->prepare("
        SELECT id FROM bookings
        WHERE room_id = ?
          AND status = 'active'
          AND check_in < ?
          AND check_out > ?
        FOR UPDATE
    ");
    $overlap->execute([$roomId, $checkOut, $checkIn]);
    if ($overlap->fetch()) {
        throw new Exception('Room is already booked for the selected dates.');
    }

    // Optional guest profile
    $guestId = null;
    if ($guestName !== '') {
        // Try match by id_number or phone
        if ($idNumber !== '') {
            $g = $pdo->prepare("SELECT id FROM guests WHERE id_number = ? LIMIT 1");
            $g->execute([$idNumber]);
            $row = $g->fetch();
            if ($row) $guestId = (int) $row['id'];
        }
        if (!$guestId && $phone !== '') {
            $g = $pdo->prepare("SELECT id FROM guests WHERE phone = ? LIMIT 1");
            $g->execute([$phone]);
            $row = $g->fetch();
            if ($row) $guestId = (int) $row['id'];
        }
        if ($guestId) {
            $pdo->prepare("
                UPDATE guests SET
                    full_name = ?, phone = COALESCE(?, phone),
                    email = COALESCE(?, email), id_number = COALESCE(?, id_number),
                    notes = COALESCE(?, notes)
                WHERE id = ?
            ")->execute([
                $guestName,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $idNumber !== '' ? $idNumber : null,
                $notes !== '' ? $notes : null,
                $guestId
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO guests (full_name, phone, email, id_number, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $guestName,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $idNumber !== '' ? $idNumber : null,
                $notes !== '' ? $notes : null
            ]);
            $guestId = (int) $pdo->lastInsertId();
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO bookings (
            user_id, room_id, guest_id, guest_name,
            check_in, check_out, status, stay_status, special_requests
        ) VALUES (NULL, ?, ?, ?, ?, ?, 'active', 'not_arrived', ?)
    ");
    $ins->execute([
        $roomId,
        $guestId,
        $guestName,
        $checkIn,
        $checkOut,
        $notes !== '' ? $notes : null
    ]);
    $bookingId = (int) $pdo->lastInsertId();

    $result = [
        'booking_id'  => $bookingId,
        'room_id'     => (int) $roomId,
        'room_number' => $room['room_number'],
        'room_type'   => $room['room_type'],
        'guest_name'  => $guestName,
        'guest_id'    => $guestId,
        'check_in'    => $checkIn,
        'check_out'   => $checkOut,
        'status'      => 'active',
        'stay_status' => 'not_arrived',
        'walk_in'     => true,
    ];

    // Optional immediate check-in
    if ($autoCheckin) {
        require_once __DIR__ . '/billing_rules.php';

        $pdo->prepare("
            UPDATE bookings
            SET stay_status = 'checked_in', actual_check_in = NOW(), guest_id = ?
            WHERE id = ?
        ")->execute([$guestId, $bookingId]);

        $bookingRow = [
            'id'          => $bookingId,
            'guest_id'    => $guestId,
            'guest_name'  => $guestName,
            'check_in'    => $checkIn,
            'check_out'   => $checkOut,
            'room_number' => $room['room_number'],
            'room_type'   => $room['room_type'],
            'actual_check_in' => date('Y-m-d H:i:s'),
        ];
        $settings = getBillingSettings($pdo);
        $invoice = upsertInvoiceForBooking(
            $pdo,
            $bookingRow,
            (float) $room['price'],
            $settings,
            false
        );
        $result['stay_status'] = 'checked_in';
        $result['invoice_id'] = $invoice['invoice_id'];
        $result['invoice_number'] = $invoice['invoice_number'];
        $result['total_amount'] = $invoice['total_amount'];
        $result['actual_check_in'] = $bookingRow['actual_check_in'];
    }

    $pdo->commit();

    jsonResponse(
        true,
        $autoCheckin
            ? 'Walk-in booking created and guest checked in.'
            : 'Walk-in booking created successfully.',
        $result,
        201
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}
