<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * AUTHENTICATED BOOK ROOM API
 * ==========================================
 */

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';


/*
|--------------------------------------------------------------------------
| ALLOW ONLY POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    jsonResponse(
        false,
        'Method Not Allowed.',
        null,
        405
    );
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

$user = requireLogin();


/*
|--------------------------------------------------------------------------
| GET JSON INPUT
|--------------------------------------------------------------------------
*/

$input = getJsonInput();


$roomId = filter_var(
    $input['room_id'] ?? null,
    FILTER_VALIDATE_INT
);


$guestName = clean(
    $input['guest_name'] ?? ''
);


$checkIn =
    $input['check_in'] ?? '';


$checkOut =
    $input['check_out'] ?? '';


/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (!$roomId) {

    jsonResponse(
        false,
        'Invalid room selected.',
        null,
        400
    );
}


if ($guestName === '') {

    jsonResponse(
        false,
        'Guest name is required.',
        null,
        400
    );
}


if (!isValidDate($checkIn)) {

    jsonResponse(
        false,
        'Invalid check-in date.',
        null,
        400
    );
}


if (!isValidDate($checkOut)) {

    jsonResponse(
        false,
        'Invalid check-out date.',
        null,
        400
    );
}


if (
    strtotime($checkOut) <=
    strtotime($checkIn)
) {

    jsonResponse(
        false,
        'Check-out must be after check-in.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| PREVENT PAST CHECK-IN
|--------------------------------------------------------------------------
*/

if (
    strtotime($checkIn) <
    strtotime(date('Y-m-d'))
) {

    jsonResponse(
        false,
        'Check-in date cannot be in the past.',
        null,
        400
    );
}


/*
|--------------------------------------------------------------------------
| VERIFY ROOM EXISTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            room_number,
            room_type,
            price,
            capacity
        FROM rooms
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $roomId
    ]);

    $room = $stmt->fetch();


    if (!$room) {

        jsonResponse(
            false,
            'Room does not exist.',
            null,
            404
        );
    }

    // Housekeeping block
    try {
        $hk = $pdo->prepare("SELECT COALESCE(housekeeping_status, 'ready') AS housekeeping_status FROM rooms WHERE id = ? LIMIT 1");
        $hk->execute([$roomId]);
        $hkRow = $hk->fetch();
        if ($hkRow && ($hkRow['housekeeping_status'] ?? '') === 'out_of_order') {
            jsonResponse(false, 'This room is out of order and cannot be booked.', null, 409);
        }
    } catch (Throwable $e) {
        // column may not exist yet
    }


} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to verify room.',
        $e->getMessage(),
        500
    );
}


/*
|--------------------------------------------------------------------------
| CREATE BOOKING
|--------------------------------------------------------------------------
*/

try {

    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }


    /*
    |--------------------------------------------------------------------------
    | LOCK OVERLAPPING ACTIVE BOOKINGS
    |--------------------------------------------------------------------------
    |
    | A booking overlaps another booking when:
    |
    | new check-out > old check-in
    | AND
    | new check-in < old check-out
    |
    */

    $stmt = $pdo->prepare("
        SELECT
            id
        FROM bookings
        WHERE room_id = ?
        AND status = 'active'
        AND check_in < ?
        AND check_out > ?
        FOR UPDATE
    ");

    $stmt->execute([
        $roomId,
        $checkOut,
        $checkIn
    ]);


    if ($stmt->fetch()) {

        $pdo->rollBack();

        jsonResponse(
            false,
            'Room is already booked for the selected dates.',
            null,
            409
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT BOOKING
    |--------------------------------------------------------------------------
    */

    $insert = $pdo->prepare("
        INSERT INTO bookings (
            user_id,
            room_id,
            guest_name,
            check_in,
            check_out,
            status
        )
        VALUES (
            ?, ?, ?, ?, ?, 'active'
        )
    ");


    $insert->execute([

        $user['id'],

        $roomId,

        $guestName,

        $checkIn,

        $checkOut

    ]);


    $bookingId =
        (int) $pdo->lastInsertId();


    $pdo->commit();

    // Email confirmation (non-blocking for the API response)
    $emailSent = false;
    try {
        $emailSent = sendBookingConfirmationEmail([
            'id' => $bookingId,
            'guest_name' => $guestName,
            'room_number' => $room['room_number'] ?? '',
            'room_type' => $room['room_type'] ?? '',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'price' => $room['price'] ?? 0,
        ], $user['email'] ?? null);
    } catch (Throwable $mailErr) {
        apiLog('warning', 'Booking confirmation email failed', ['error' => $mailErr->getMessage()]);
    }

    $smsSent = false;
    try {
        $phone = smsNormalizePhone((string)($input['phone'] ?? $user['phone'] ?? ''));
        if ($phone !== '') {
            $smsSent = sendBookingSms([
                'id' => $bookingId,
                'room_number' => $room['room_number'] ?? '',
                'room_type' => $room['room_type'] ?? '',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ], $phone);
        }
    } catch (Throwable $smsErr) {
        apiLog('warning', 'Booking SMS failed', ['error' => $smsErr->getMessage()]);
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        ($emailSent || $smsSent)
            ? 'Room booked successfully. Confirmation notification(s) sent.'
            : 'Room booked successfully.',

        [

            'booking_id' =>
                $bookingId,

            'user_id' =>
                (int) $user['id'],

            'room_id' =>
                (int) $roomId,

            'room_number' =>
                $room['room_number'],

            'guest_name' =>
                $guestName,

            'check_in' =>
                $checkIn,

            'check_out' =>
                $checkOut,

            'status' =>
                'active'

        ],

        201

    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(
        false,
        'Booking failed.',
        $e->getMessage(),
        500
    );
}